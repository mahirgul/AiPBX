<?php
/**
 * Desk phone provisioning (roadmap item 9): the phone list, waiting phones,
 * CSV import, per-user key layouts, the provisioning settings, and the
 * answer to a phone's configuration request (/provision/…).
 *
 * Security model:
 *  - /provision/<token>/<file>: the random per-phone token is the secret.
 *  - /provision/<file> (MAC-based names, for DHCP option 66 / zero touch):
 *    answered only when the allowed networks setting is filled and the
 *    phone's address is inside it; an unknown MAC lands in "waiting phones".
 *  - With allowed networks set, token URLs are refused outside them too.
 *  - Per-IP rate limit; every request is logged (file and result, never a
 *    password) in pbx_phone_fetch_log.
 */

require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/sip_helper.php';
require_once dirname(__DIR__) . '/audit_log.php';
require_once dirname(__DIR__) . '/secret_box.php';
require_once __DIR__ . '/ForgotPasswordService.php';
require_once __DIR__ . '/phones/PhoneTemplates.php';

class PhoneProvisionService
{
    public const KEY_TYPES = ['blf', 'speeddial', 'park', 'dnd', 'line'];

    /** Feature codes written to the phone (see SyncFeatureCodes). */
    public const VOICEMAIL_CODE = '*97';
    public const PICKUP_PREFIX = '*21';

    public const CODECS = ['g722', 'pcma', 'pcmu', 'g729', 'opus'];

    /** sys_settings keys and their defaults. */
    public const SETTINGS = [
        'provision_transport' => 'udp',
        'provision_srtp' => '0',
        'provision_codecs' => 'g722,pcma,pcmu',
        'provision_ntp' => 'pool.ntp.org',
        'provision_timezone' => '',
        'provision_language' => 'en',
        'provision_allowed_networks' => '',
        'provision_rate_limit' => '60',
    ];

    /** Rows kept in the fetch log. */
    private const LOG_KEEP_DAYS = 90;

    // ------------------------------------------------------------ settings

    public static function settings(): array
    {
        $out = [];
        foreach (self::SETTINGS as $key => $default) {
            $out[$key] = (string) getSystemSetting($key, $default);
        }
        if ($out['provision_timezone'] === '') {
            $out['provision_timezone'] = date_default_timezone_get();
        }
        return $out;
    }

    public static function saveSettings(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $transport = ($data['provision_transport'] ?? '') === 'tls' ? 'tls' : 'udp';
            $srtp = (string) max(0, min(2, (int) ($data['provision_srtp'] ?? 0)));
            $codecs = array_values(array_intersect((array) ($data['provision_codecs'] ?? []), self::CODECS));
            if (empty($codecs)) {
                throw new \Exception(t('phones.err_codecs'));
            }
            $ntp = trim((string) ($data['provision_ntp'] ?? ''));
            if ($ntp === '' || !preg_match('/^[A-Za-z0-9.-]{1,255}$/', $ntp)) {
                throw new \Exception(t('phones.err_ntp'));
            }
            $tz = (string) ($data['provision_timezone'] ?? '');
            if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                throw new \Exception(t('phones.err_timezone'));
            }
            $lang = (string) ($data['provision_language'] ?? 'en');
            if (!isset(UI_LANGUAGES[$lang])) {
                $lang = 'en';
            }
            $networks = self::parseNetworks((string) ($data['provision_allowed_networks'] ?? ''), $bad);
            if ($bad !== []) {
                throw new \Exception(sprintf(t('phones.err_networks'), implode(', ', $bad)));
            }
            $rate = max(5, min(1000, (int) ($data['provision_rate_limit'] ?? 60)));

            $values = [
                'provision_transport' => $transport,
                'provision_srtp' => $srtp,
                'provision_codecs' => implode(',', $codecs),
                'provision_ntp' => $ntp,
                'provision_timezone' => $tz,
                'provision_language' => $lang,
                'provision_allowed_networks' => implode("\n", $networks),
                'provision_rate_limit' => (string) $rate,
            ];
            $stmt = getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            foreach ($values as $k => $v) {
                $stmt->execute([$k, $v]);
            }
            writeAuditLog('phones', 'settings', 0, 'Provisioning settings', 'update', $_SESSION['user_id'] ?? null);
            return t('phones.settings_saved');
        });
    }

    /**
     * Allowed networks from a text (one CIDR or address per line, or comma
     * separated). Invalid entries are returned in $bad.
     *
     * @return string[]
     */
    public static function parseNetworks(string $text, ?array &$bad = null): array
    {
        $bad = [];
        $out = [];
        foreach (preg_split('/[\s,]+/', trim($text)) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }
            if (self::validCidr($entry)) {
                $out[] = $entry;
            } else {
                $bad[] = $entry;
            }
        }
        return array_values(array_unique($out));
    }

    private static function validCidr(string $cidr): bool
    {
        [$ip, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $packed = @inet_pton((string) $ip);
        if ($packed === false) {
            return false;
        }
        if ($bits === null) {
            return true;
        }
        return ctype_digit($bits) && (int) $bits <= strlen($packed) * 8;
    }

    /** Is $ip inside one of $networks (IPv4 and IPv6 CIDRs or single addresses)? */
    public static function ipInNetworks(string $ip, array $networks): bool
    {
        $addr = @inet_pton($ip);
        if ($addr === false) {
            return false;
        }
        foreach ($networks as $cidr) {
            [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
            $netAddr = @inet_pton((string) $net);
            if ($netAddr === false || strlen($netAddr) !== strlen($addr)) {
                continue;
            }
            $bits = $bits === null ? strlen($addr) * 8 : (int) $bits;
            $bytes = intdiv($bits, 8);
            if (substr($addr, 0, $bytes) !== substr($netAddr, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($addr[$bytes]) & $mask) === (ord($netAddr[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------- phones

    public static function listPhones(): array
    {
        return getDB()->query(
            "SELECT p.*, u.full_name, u.extension
             FROM pbx_phones p LEFT JOIN sys_users u ON u.id = p.user_id
             ORDER BY u.extension IS NULL, u.extension, p.mac"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getPhone(int $id): ?array
    {
        $stmt = getDB()->prepare('SELECT * FROM pbx_phones WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function listWaiting(): array
    {
        return getDB()->query('SELECT * FROM pbx_phones_waiting ORDER BY last_seen_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Users a phone can be assigned to: active SIP extensions. */
    public static function assignableUsers(): array
    {
        return getDB()->query(
            "SELECT id, full_name, extension FROM sys_users
             WHERE is_active = 1 AND extension_type = 'sip' AND extension IS NOT NULL AND extension != ''
             ORDER BY extension + 0, extension"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function provisioningUrl(array $phone): string
    {
        return ForgotPasswordService::portalUrl() . '/provision/' . $phone['token'] . '/';
    }

    public static function savePhone(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $id = self::upsertPhone(
                (int) ($data['id'] ?? 0),
                (string) ($data['mac'] ?? ''),
                (string) ($data['model'] ?? ''),
                (int) ($data['user_id'] ?? 0),
                (string) ($data['notes'] ?? '')
            );
            return sprintf(t('phones.saved'), PhoneModels::formatMac((string) self::getPhone($id)['mac']));
        });
    }

    /**
     * Creates or updates a phone (by id, or by MAC when $id is 0 and the MAC
     * is known) and returns its id. A new phone gets its token and web admin
     * password here; a MAC that was waiting leaves the waiting list.
     */
    public static function upsertPhone(int $id, string $rawMac, string $model, int $userId, string $notes = ''): int
    {
        $mac = PhoneModels::normalizeMac($rawMac);
        if ($mac === '') {
            throw new \Exception(t('phones.err_mac'));
        }
        if (PhoneModels::get($model) === null) {
            throw new \Exception(t('phones.err_model'));
        }
        $userId = $userId > 0 ? $userId : null;
        if ($userId !== null && !self::isAssignableUser($userId)) {
            throw new \Exception(t('phones.err_user'));
        }
        $notes = mb_substr(trim($notes), 0, 255);

        $db = getDB();
        $byMac = $db->prepare('SELECT id FROM pbx_phones WHERE mac = ?');
        $byMac->execute([$mac]);
        $existing = (int) ($byMac->fetchColumn() ?: 0);
        if ($id > 0 && $existing > 0 && $existing !== $id) {
            throw new \Exception(t('phones.err_mac_taken'));
        }
        $id = $id ?: $existing;

        if ($id > 0) {
            if (self::getPhone($id) === null) {
                throw new \Exception(t('phones.err_not_found'));
            }
            $db->prepare('UPDATE pbx_phones SET mac = ?, model = ?, user_id = ?, notes = ? WHERE id = ?')
                ->execute([$mac, $model, $userId, $notes, $id]);
            $action = 'update';
        } else {
            $db->prepare('INSERT INTO pbx_phones (mac, model, user_id, token, admin_password, notes) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$mac, $model, $userId, self::newToken(), SecretBox::encrypt(self::newPassword()), $notes]);
            $id = (int) $db->lastInsertId();
            $action = 'create';
        }
        $db->prepare('DELETE FROM pbx_phones_waiting WHERE mac = ?')->execute([$mac]);
        writeAuditLog('phones', 'phone', $id, PhoneModels::formatMac($mac), $action, $_SESSION['user_id'] ?? null);
        return $id;
    }

    private static function isAssignableUser(int $userId): bool
    {
        $stmt = getDB()->prepare("SELECT 1 FROM sys_users WHERE id = ? AND extension_type = 'sip' AND extension IS NOT NULL AND extension != ''");
        $stmt->execute([$userId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function deletePhone(int $id, string $csrf): array
    {
        return PBXHelper::handleAction($csrf, function () use ($id) {
            $phone = self::getPhone($id);
            if ($phone === null) {
                throw new \Exception(t('phones.err_not_found'));
            }
            getDB()->prepare('DELETE FROM pbx_phones WHERE id = ?')->execute([$id]);
            writeAuditLog('phones', 'phone', $id, PhoneModels::formatMac($phone['mac']), 'delete', $_SESSION['user_id'] ?? null);
            return t('phones.deleted');
        });
    }

    /** A new URL for the phone (the old one stops working at once). */
    public static function regenerateToken(int $id, string $csrf): array
    {
        return PBXHelper::handleAction($csrf, function () use ($id) {
            $phone = self::getPhone($id);
            if ($phone === null) {
                throw new \Exception(t('phones.err_not_found'));
            }
            getDB()->prepare('UPDATE pbx_phones SET token = ? WHERE id = ?')->execute([self::newToken(), $id]);
            writeAuditLog('phones', 'phone', $id, PhoneModels::formatMac($phone['mac']), 'new_url', $_SESSION['user_id'] ?? null);
            return t('phones.url_regenerated');
        });
    }

    public static function assignWaiting(int $waitingId, string $model, int $userId, string $csrf): array
    {
        return PBXHelper::handleAction($csrf, function () use ($waitingId, $model, $userId) {
            $stmt = getDB()->prepare('SELECT mac FROM pbx_phones_waiting WHERE id = ?');
            $stmt->execute([$waitingId]);
            $mac = $stmt->fetchColumn();
            if ($mac === false) {
                throw new \Exception(t('phones.err_not_found'));
            }
            self::upsertPhone(0, (string) $mac, $model, $userId);
            return sprintf(t('phones.saved'), PhoneModels::formatMac((string) $mac));
        });
    }

    public static function deleteWaiting(int $waitingId, string $csrf): array
    {
        return PBXHelper::handleAction($csrf, function () use ($waitingId) {
            getDB()->prepare('DELETE FROM pbx_phones_waiting WHERE id = ?')->execute([$waitingId]);
            return t('phones.waiting_removed');
        });
    }

    /**
     * CSV import: one phone per line as "mac,model,extension" (a header line
     * is skipped; ; and tab work as separators too). Model is the catalog
     * key (yealink-t46u) or the name on the page (Yealink T46U). A known MAC
     * is updated.
     *
     * @return array{added: int, updated: int, errors: string[]}
     */
    public static function importCsv(string $csv): array
    {
        $added = 0;
        $updated = 0;
        $errors = [];
        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        foreach ($lines as $i => $line) {
            $lineNo = $i + 1;
            if (trim($line) === '') {
                continue;
            }
            $cols = array_map('trim', str_getcsv($line, self::csvSeparator($line), '"', ''));
            if ($i === 0 && PhoneModels::normalizeMac($cols[0]) === '' && stripos($line, 'mac') !== false) {
                continue; // header
            }
            [$rawMac, $rawModel, $ext] = array_pad($cols, 3, '');
            try {
                $mac = PhoneModels::normalizeMac((string) $rawMac);
                if ($mac === '') {
                    throw new \Exception(t('phones.err_mac'));
                }
                $model = self::modelFromText((string) $rawModel);
                if ($model === '') {
                    throw new \Exception(t('phones.err_model'));
                }
                $userId = 0;
                if ($ext !== '') {
                    $stmt = getDB()->prepare("SELECT id FROM sys_users WHERE extension = ? AND extension_type = 'sip'");
                    $stmt->execute([$ext]);
                    $userId = (int) ($stmt->fetchColumn() ?: 0);
                    if ($userId === 0) {
                        throw new \Exception(sprintf(t('phones.err_extension'), $ext));
                    }
                }
                $known = getDB()->prepare('SELECT 1 FROM pbx_phones WHERE mac = ?');
                $known->execute([$mac]);
                $isUpdate = (bool) $known->fetchColumn();
                self::upsertPhone(0, $mac, $model, $userId);
                $isUpdate ? $updated++ : $added++;
            } catch (\Exception $e) {
                $errors[] = sprintf(t('phones.import_line_error'), $lineNo, $e->getMessage());
            }
        }
        return ['added' => $added, 'updated' => $updated, 'errors' => $errors];
    }

    /** Controller wrapper of importCsv(): one notice for the page. */
    public static function importCsvAction(string $csv, string $csrf): array
    {
        if (!verifyCSRFToken($csrf)) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        if (trim($csv) === '') {
            return ['success' => false, 'error' => t('phones.err_csv_empty')];
        }
        $r = self::importCsv($csv);
        $msg = sprintf(t('phones.import_done'), $r['added'], $r['updated']);
        if ($r['errors'] !== []) {
            return ['success' => false, 'error' => $msg . ' ' . implode(' ', array_slice($r['errors'], 0, 10))];
        }
        return ['success' => true, 'message' => $msg];
    }

    private static function csvSeparator(string $line): string
    {
        foreach ([',', ';', "\t"] as $sep) {
            if (str_contains($line, $sep)) {
                return $sep;
            }
        }
        return ',';
    }

    /** Catalog key from "yealink-t46u", "Yealink T46U" or "T46U" ('' when unknown or ambiguous). */
    public static function modelFromText(string $text): string
    {
        $norm = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $text));
        if ($norm === '') {
            return '';
        }
        $hits = [];
        foreach (PhoneModels::MODELS as $key => $m) {
            $keyNorm = str_replace('-', '', $key);
            $short = strtolower((string) preg_replace('/[^a-z0-9]/i', '', substr($key, strpos($key, '-') + 1)));
            if ($norm === $keyNorm || $norm === $short) {
                $hits[] = $key;
            }
        }
        return count($hits) === 1 ? $hits[0] : '';
    }

    /** Re-provision (and optionally reboot) through a SIP NOTIFY to the phone's endpoint. */
    public static function resync(int $id, bool $reboot, string $csrf): array
    {
        return PBXHelper::handleAction($csrf, function () use ($id, $reboot) {
            $cmd = self::notifyCommand($id, $reboot);
            $res = AsteriskHelper::execCLI($cmd);
            if (empty($res['success'])) {
                throw new \Exception(trim((string) $res['output']));
            }
            $phone = self::getPhone($id);
            writeAuditLog('phones', 'phone', $id, PhoneModels::formatMac((string) ($phone['mac'] ?? '')), $reboot ? 'reboot' : 'resync', $_SESSION['user_id'] ?? null);
            return $reboot ? t('phones.reboot_sent') : t('phones.resync_sent');
        });
    }

    /** The Asterisk CLI command of resync() (split out for the tests). */
    public static function notifyCommand(int $id, bool $reboot): string
    {
        $phone = self::getPhone($id);
        if ($phone === null) {
            throw new \Exception(t('phones.err_not_found'));
        }
        $user = $phone['user_id'] ? self::userRow((int) $phone['user_id']) : null;
        if ($user === null) {
            throw new \Exception(t('phones.err_unassigned'));
        }
        $tpl = PhoneTemplates::forModel($phone['model']);
        if ($tpl === null) {
            throw new \Exception(t('phones.err_model'));
        }
        $option = $reboot ? $tpl->notifyReboot() : $tpl->notifyResync();
        if ($option === '') {
            throw new \Exception(t('phones.err_reboot_unsupported'));
        }
        $ext = preg_replace('/[^0-9]/', '', (string) $user['extension']);
        return "pjsip send notify {$option} endpoint {$ext}-sip";
    }

    // ---------------------------------------------------------- key layout

    public static function getKeys(int $userId): array
    {
        $stmt = getDB()->prepare('SELECT page, position, key_type AS type, target, label FROM pbx_phone_keys WHERE user_id = ? ORDER BY page, position');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The model of the user's (first) phone, '' when they have none. */
    public static function userPhoneModel(int $userId): string
    {
        $stmt = getDB()->prepare('SELECT model FROM pbx_phones WHERE user_id = ? ORDER BY id LIMIT 1');
        $stmt->execute([$userId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    /**
     * Replaces a user's key layout. $keys: list of [page, position, type,
     * target, label]; rows with an empty type are dropped.
     */
    public static function saveKeys(int $userId, array $keys, string $csrf): array
    {
        return PBXHelper::handleAction($csrf, function () use ($userId, $keys) {
            if (self::userRow($userId) === null) {
                throw new \Exception(t('phones.err_user'));
            }
            $rows = self::validateKeys($keys);
            self::writeKeys($userId, $rows);
            writeAuditLog('phones', 'key_layout', $userId, (string) self::userRow($userId)['extension'], 'update', $_SESSION['user_id'] ?? null);
            return t('phones.keys_saved');
        });
    }

    /** Copies $fromUser's layout to every user in $toUsers (their own layout is replaced). */
    public static function copyKeys(int $fromUser, array $toUsers, string $csrf): array
    {
        return PBXHelper::handleAction($csrf, function () use ($fromUser, $toUsers) {
            $rows = self::getKeys($fromUser);
            $n = 0;
            foreach (array_unique(array_map('intval', $toUsers)) as $to) {
                if ($to === $fromUser || self::userRow($to) === null) {
                    continue;
                }
                self::writeKeys($to, $rows);
                writeAuditLog('phones', 'key_layout', $to, (string) self::userRow($to)['extension'], 'copy', $_SESSION['user_id'] ?? null);
                $n++;
            }
            if ($n === 0) {
                throw new \Exception(t('phones.err_copy_none'));
            }
            return sprintf(t('phones.keys_copied'), $n);
        });
    }

    /** @return list<array{page: int, position: int, type: string, target: string, label: string}> */
    public static function validateKeys(array $keys): array
    {
        $rows = [];
        $seen = [];
        foreach ($keys as $k) {
            $type = (string) ($k['type'] ?? '');
            if ($type === '') {
                continue;
            }
            if (!in_array($type, self::KEY_TYPES, true)) {
                throw new \Exception(t('phones.err_key_type'));
            }
            $page = (int) ($k['page'] ?? 0);
            $pos = (int) ($k['position'] ?? 0);
            if ($page < 0 || $page > 6 || $pos < 1 || $pos > 120) {
                throw new \Exception(t('phones.err_key_position'));
            }
            $target = trim((string) ($k['target'] ?? ''));
            if (in_array($type, ['blf', 'speeddial', 'park'], true)) {
                if (!preg_match('/^[0-9*#+]{1,32}$/', $target)) {
                    throw new \Exception(sprintf(t('phones.err_key_target'), $pos));
                }
            } else {
                $target = '';
            }
            $label = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($k['label'] ?? ''))), 0, 64);
            if (isset($seen["{$page}-{$pos}"])) {
                continue;
            }
            $seen["{$page}-{$pos}"] = true;
            $rows[] = ['page' => $page, 'position' => $pos, 'type' => $type, 'target' => $target, 'label' => $label];
        }
        return $rows;
    }

    private static function writeKeys(int $userId, array $rows): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM pbx_phone_keys WHERE user_id = ?')->execute([$userId]);
            $ins = $db->prepare('INSERT INTO pbx_phone_keys (user_id, page, position, key_type, target, label) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($rows as $r) {
                $ins->execute([$userId, (int) $r['page'], (int) $r['position'], $r['type'], $r['target'], $r['label']]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------ phone requests

    /**
     * Answers one configuration request.
     *
     * @param string|null $token null for a MAC-based request (/provision/<file>)
     * @return array{status: int, body: string, type: string, result: string}
     */
    public static function handleRequest(?string $token, string $file, string $ip, string $userAgent): array
    {
        $settings = self::settings();
        $networks = self::parseNetworks($settings['provision_allowed_networks']);
        $ua = mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $userAgent), 0, 255);
        $file = mb_substr($file, 0, 120);

        // Rate limit first: refused requests are logged too and must not flood the log.
        if (self::recentRequests($ip) >= (int) $settings['provision_rate_limit']) {
            return self::answer(429, 'rate_limited');
        }
        if ($networks !== [] ? !self::ipInNetworks($ip, $networks) : $token === null) {
            self::log(null, '', $file, 'denied', $ip, $ua);
            return self::answer(403, 'denied');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $file)) {
            self::log(null, '', $file, 'not_found', $ip, $ua);
            return self::answer(404, 'not_found');
        }

        if ($token !== null) {
            $phone = preg_match('/^[0-9a-f]{40}$/', $token) ? self::phoneBy('token', $token) : null;
            if ($phone === null) {
                self::log(null, '', $file, 'bad_token', $ip, $ua);
                return self::answer(404, 'bad_token');
            }
            $tpl = PhoneTemplates::forModel($phone['model']);
            $mac = $tpl ? $tpl->macFromFile($file) : '';
            if ($mac === '') {
                self::log((int) $phone['id'], '', $file, 'not_found', $ip, $ua);
                return self::answer(404, 'not_found');
            }
            if ($mac !== $phone['mac']) {
                self::log((int) $phone['id'], $mac, $file, 'mac_mismatch', $ip, $ua);
                return self::answer(404, 'mac_mismatch');
            }
            return self::serve($phone, $tpl, $file, $ip, $ua);
        }

        // MAC-based file name: any vendor whose pattern matches.
        $mac = '';
        foreach (PhoneTemplates::all() as $t) {
            $mac = $mac ?: $t->macFromFile($file);
        }
        if ($mac === '') {
            self::log(null, '', $file, 'not_found', $ip, $ua);
            return self::answer(404, 'not_found');
        }
        $phone = self::phoneBy('mac', $mac);
        if ($phone === null) {
            self::recordWaiting($mac, PhoneModels::guessVendor($mac, $ua), $ip, $ua);
            self::log(null, $mac, $file, 'unknown', $ip, $ua);
            return self::answer(404, 'unknown');
        }
        $tpl = PhoneTemplates::forModel($phone['model']);
        if ($tpl === null || $tpl->macFromFile($file) !== $mac) {
            self::log((int) $phone['id'], $mac, $file, 'not_found', $ip, $ua);
            return self::answer(404, 'not_found');
        }
        return self::serve($phone, $tpl, $file, $ip, $ua);
    }

    private static function serve(array $phone, PhoneTemplate $tpl, string $file, string $ip, string $ua): array
    {
        $user = $phone['user_id'] ? self::userRow((int) $phone['user_id']) : null;
        if ($user === null || (int) $user['is_active'] !== 1) {
            self::log((int) $phone['id'], $phone['mac'], $file, 'unassigned', $ip, $ua);
            return self::answer(404, 'unassigned');
        }
        $body = $tpl->render(self::buildContext($phone, $user));
        getDB()->prepare('UPDATE pbx_phones SET last_fetch_at = NOW(), last_ip = ?, last_user_agent = ? WHERE id = ?')
            ->execute([$ip, $ua, $phone['id']]);
        self::log((int) $phone['id'], $phone['mac'], $file, 'served', $ip, $ua);
        return ['status' => 200, 'body' => $body, 'type' => $tpl->contentType(), 'result' => 'served'];
    }

    private static function answer(int $status, string $result): array
    {
        return ['status' => $status, 'body' => '', 'type' => 'text/plain; charset=utf-8', 'result' => $result];
    }

    /** Everything a template needs for one phone. */
    public static function buildContext(array $phone, array $user): array
    {
        $s = self::settings();
        $ext = (string) preg_replace('/[^0-9]/', '', (string) $user['extension']);
        $sipMap = SIPHelper::getSettingsMap($ext);
        $secret = !empty($sipMap['secret']) ? (string) $sipMap['secret'] : (string) $user['sip_password'];
        $tls = $s['provision_transport'] === 'tls';
        try {
            $offset = intdiv((new \DateTimeZone($s['provision_timezone']))->getOffset(new \DateTimeImmutable('now')), 60);
        } catch (\Exception $e) {
            $offset = 0;
        }
        return [
            'mac' => $phone['mac'],
            'model' => $phone['model'],
            'extension' => $ext,
            'display_name' => toCleanAscii((string) $user['full_name']) ?: $ext,
            'sip_password' => $secret,
            'server' => self::sipServer(),
            'port' => self::sipPort($tls),
            'transport' => $tls ? 'tls' : 'udp',
            'srtp' => (int) $s['provision_srtp'],
            'codecs' => array_values(array_intersect(explode(',', $s['provision_codecs']), self::CODECS)),
            'ntp' => $s['provision_ntp'],
            'utc_offset' => $offset,
            'language' => $s['provision_language'],
            'voicemail' => self::VOICEMAIL_CODE,
            'pickup_prefix' => self::PICKUP_PREFIX,
            'admin_password' => SecretBox::decrypt((string) $phone['admin_password']),
            'keys' => self::getKeys((int) $user['id']),
        ];
    }

    /**
     * The SIP server written to phones: the installation's domain, from the
     * same settings as ForgotPasswordService::portalUrl() (never the Host
     * header of the request), without the web port.
     */
    public static function sipServer(): string
    {
        $host = (string) parse_url(ForgotPasswordService::portalUrl(), PHP_URL_HOST);
        return trim($host, '[]') ?: 'localhost';
    }

    private static function sipPort(bool $tls): int
    {
        $stmt = getDB()->prepare('SELECT data FROM pjsipsettings WHERE keyword = ?');
        $stmt->execute([$tls ? 'tls_bindport' : 'bindport']);
        $port = (int) $stmt->fetchColumn();
        return $port > 0 ? $port : ($tls ? 5061 : 5060);
    }

    /** The web admin password of a phone (shown on the page on request). */
    public static function adminPassword(array $phone): string
    {
        return SecretBox::decrypt((string) $phone['admin_password']);
    }

    private static function phoneBy(string $column, string $value): ?array
    {
        $stmt = getDB()->prepare('SELECT * FROM pbx_phones WHERE ' . ($column === 'token' ? 'token' : 'mac') . ' = ?');
        $stmt->execute([$value]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function userRow(int $id): ?array
    {
        $stmt = getDB()->prepare("SELECT id, full_name, extension, sip_password, is_active FROM sys_users WHERE id = ? AND extension_type = 'sip' AND extension IS NOT NULL AND extension != ''");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function recordWaiting(string $mac, string $vendor, string $ip, string $ua): void
    {
        getDB()->prepare(
            'INSERT INTO pbx_phones_waiting (mac, vendor, ip, user_agent) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE vendor = VALUES(vendor), ip = VALUES(ip), user_agent = VALUES(user_agent),
                 request_count = request_count + 1, last_seen_at = NOW()'
        )->execute([$mac, $vendor, $ip, $ua]);
    }

    private static function recentRequests(string $ip): int
    {
        $stmt = getDB()->prepare('SELECT COUNT(*) FROM pbx_phone_fetch_log WHERE ip = ? AND created_at >= (NOW() - INTERVAL 60 SECOND)');
        $stmt->execute([$ip]);
        return (int) $stmt->fetchColumn();
    }

    /** One fetch log row; old rows are trimmed now and then. Never stores a password. */
    private static function log(?int $phoneId, string $mac, string $file, string $result, string $ip, string $ua): void
    {
        $db = getDB();
        $db->prepare('INSERT INTO pbx_phone_fetch_log (phone_id, mac, file, result, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$phoneId, $mac, $file, $result, mb_substr($ip, 0, 45), $ua]);
        if (random_int(1, 200) === 1) {
            $db->exec('DELETE FROM pbx_phone_fetch_log WHERE created_at < (NOW() - INTERVAL ' . self::LOG_KEEP_DAYS . ' DAY)');
        }
    }

    public static function recentLog(int $limit = 50): array
    {
        $stmt = getDB()->prepare('SELECT * FROM pbx_phone_fetch_log ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function newToken(): string
    {
        return bin2hex(random_bytes(20));
    }

    /** Phone web admin password: 16 characters without look-alikes. */
    private static function newPassword(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }
}
