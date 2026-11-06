<?php
require_once __DIR__ . '/PhoneProvisionService.php';
require_once __DIR__ . '/../priv_helper.php';
require_once __DIR__ . '/CertificateService.php';
require_once __DIR__ . '/../asterisk_sync.php';

/**
 * Regions of desk phones (PBX → Phones): a phone is put in one by hand, and gets the region's
 * addresses and settings in its files. An empty field takes the general provisioning value; "none"
 * in the proxy or syslog field means none for this region, as a phone outside cannot use an internal
 * one. The allowed networks stay general.
 */
final class PhoneRegionService
{
    public const NONE = 'none';

    /** Region field => the general setting it replaces. */
    private const FIELDS = [
        'sip_server' => 'provision_sip_server',
        'sip_proxy' => 'provision_sip_proxy',
        'base_url' => 'provision_base_url',
        'transport' => 'provision_transport',
        'srtp' => 'provision_srtp',
        'codecs' => 'provision_codecs',
        'ntp' => 'provision_ntp',
        'syslog' => 'provision_syslog',
        'timezone' => 'provision_timezone',
        'language' => 'provision_language',
    ];

    /** @var list<array<string, mixed>>|null */
    private static ?array $cache = null;

    /** @return list<array<string, mixed>> default first, then by name */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = getDB()->query('SELECT * FROM pbx_phone_regions ORDER BY is_default DESC, name')->fetchAll(PDO::FETCH_ASSOC);
        }
        return self::$cache;
    }

    /** @return array<int, int> region id => phones in it; a phone without one counts in the default */
    public static function phoneCounts(): array
    {
        $counts = [];
        foreach (getDB()->query('SELECT region_id, COUNT(*) AS n FROM pbx_phones GROUP BY region_id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['region_id'] ?: self::defaultId();
            $counts[$id] = ($counts[$id] ?? 0) + (int) $row['n'];
        }
        return $counts;
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }

    public static function defaultId(): int
    {
        foreach (self::all() as $r) {
            if (!empty($r['is_default'])) {
                return (int) $r['id'];
            }
        }
        return 0;
    }

    /** @return array<string, mixed> the phone's region; a phone without one is in the default region */
    public static function forPhone(array $phone): array
    {
        $id = (int) ($phone['region_id'] ?? 0) ?: self::defaultId();
        foreach (self::all() as $r) {
            if ((int) $r['id'] === $id) {
                return $r;
            }
        }
        return [];
    }

    public static function exists(int $id): bool
    {
        foreach (self::all() as $r) {
            if ((int) $r['id'] === $id) {
                return true;
            }
        }
        return false;
    }

    /**
     * The general settings as this region's phones see them.
     *
     * @param array<string, string> $settings
     * @param array<string, mixed> $region
     * @return array<string, string>
     */
    public static function apply(array $settings, array $region): array
    {
        foreach (self::FIELDS as $field => $key) {
            $value = (string) ($region[$field] ?? '');
            if ($value !== '') {
                $settings[$key] = $value === self::NONE ? '' : $value;
            }
        }
        return $settings;
    }

    /**
     * The region fields of a form, checked. Throws on the first bad one.
     *
     * @return array<string, string>
     */
    public static function validate(array $data): array
    {
        $host = static function (string $field, string $err, bool $none = false) use ($data): string {
            $v = trim((string) ($data[$field] ?? ''));
            if ($v !== '' && !($none && $v === self::NONE) && !preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,253}[A-Za-z0-9])?$/D', $v)) {
                throw new \Exception(t($err));
            }
            return $v;
        };
        $out = [
            'sip_server' => $host('sip_server', 'phones.err_sip_server'),
            'sip_proxy' => $host('sip_proxy', 'phones.err_sip_proxy', true),
            'ntp' => $host('ntp', 'phones.err_ntp'),
        ];
        $syslog = trim((string) ($data['syslog'] ?? ''));
        if ($syslog !== '' && $syslog !== self::NONE && filter_var($syslog, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \Exception(t('phones.err_syslog'));
        }
        $out['syslog'] = $syslog;
        $baseUrl = rtrim(trim((string) ($data['base_url'] ?? '')), '/');
        if ($baseUrl !== '' && !preg_match('~^(https?://)?[A-Za-z0-9]([A-Za-z0-9.-]{0,253}[A-Za-z0-9])?(:[0-9]{1,5})?$~iD', $baseUrl)) {
            throw new \Exception(t('phones.err_base_url'));
        }
        $out['base_url'] = $baseUrl;
        $transport = (string) ($data['transport'] ?? '');
        $out['transport'] = in_array($transport, ['udp', 'tcp', 'tls'], true) ? $transport : '';
        $port = trim((string) ($data['sip_port'] ?? ''));
        if ($port !== '' && (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535)) {
            throw new \Exception(t('phones.region_err_port'));
        }
        $out['sip_port'] = $port;
        $out['sip443'] = empty($data['sip443']) ? '0' : '1';
        $out['sip443_sources'] = implode(', ', self::sources((string) ($data['sip443_sources'] ?? '')));
        if ($out['sip443'] === '1') {
            // nginx hands on TLS only: these phones talk TLS to 443.
            [$out['transport'], $out['sip_port']] = ['tls', '443'];
            if (self::sip443Name($out) === '' && $out['sip443_sources'] === '') {
                throw new \Exception(t('phones.region_err_sip443_none'));
            }
        }
        $srtp = (string) ($data['srtp'] ?? '');
        $out['srtp'] = in_array($srtp, ['0', '1', '2'], true) ? $srtp : '';
        // In the order of CODECS, as in the general settings; none ticked takes the general list.
        $out['codecs'] = implode(',', array_intersect(PhoneProvisionService::CODECS, (array) ($data['codecs'] ?? [])));
        $tz = (string) ($data['timezone'] ?? '');
        if ($tz !== '' && !in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            throw new \Exception(t('phones.err_timezone'));
        }
        $out['timezone'] = $tz;
        $lang = (string) ($data['language'] ?? '');
        $out['language'] = isset(UI_LANGUAGES[$lang]) ? $lang : '';
        return $out;
    }

    /**
     * A comma list of addresses or networks (IPv4 or IPv6, with or without /prefix), checked.
     * Throws on the first bad one.
     *
     * @return list<string>
     */
    public static function sources(string $list): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY) as $item) {
            [$ip, $prefix] = array_pad(explode('/', $item, 2), 2, null);
            $v6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            if (filter_var($ip, FILTER_VALIDATE_IP) === false
                || ($prefix !== null && (!ctype_digit($prefix) || (int) $prefix > ($v6 ? 128 : 32)))) {
                throw new \Exception(sprintf(t('phones.region_err_sip443'), $item));
            }
            $out[] = $item;
        }
        return array_values(array_unique($out));
    }

    /**
     * The region's own SIP server name for SIP on 443: a host name other than the portal's. Browsers
     * reach TURN on 443 by the portal's name, so only another name tells nginx a phone is calling.
     */
    public static function sip443Name(array $region): string
    {
        $host = strtolower((string) ($region['sip_server'] ?? ''));
        return $host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || $host === CertificateService::domain() ? '' : $host;
    }

    /**
     * What nginx on 443 sends to Asterisk's TLS transport instead of the web server or TURN, for
     * every region with SIP on 443: a connection naming the region's own name ("name HOST"), or one
     * without ALPN from its addresses when it has none ("addr ADDRESS"). install.sh writes the same.
     *
     * @return list<string>
     */
    public static function sip443Lines(): array
    {
        $names = $addrs = [];
        foreach (self::all() as $r) {
            if (!empty($r['sip443'])) {
                $name = self::sip443Name($r);
                $name !== '' ? $names[] = $name : array_push($addrs, ...self::sources((string) $r['sip443_sources']));
            }
        }
        return [...array_map(static fn(string $n): string => "name {$n}", array_unique($names)), ...array_map(static fn(string $a): string => "addr {$a}", array_unique($addrs))];
    }

    /** Writes nginx's lists through aipbx-priv; returns the names in them. */
    private static function syncSip443(): array
    {
        $lines = self::sip443Lines();
        $res = PrivHelper::run(['sip443', 'set'], implode("\n", $lines) . "\n");
        if (!$res['success']) {
            throw new \Exception(t('phones.region_err_sip443_apply') . ' ' . $res['output']);
        }
        return array_values(array_map(static fn(string $l): string => substr($l, 5), array_filter($lines, static fn(string $l): bool => str_starts_with($l, 'name '))));
    }

    /**
     * After a region changed: nginx's lists when SIP on 443 is or was on, and Asterisk (on Apply)
     * when a SIP server name changed, as phones are known by the names in pjsipIdentifyHosts().
     * Returns a note when the certificate should be requested again for a new name.
     */
    private static function afterChange(array $before, array $after): string
    {
        $note = '';
        if (!empty($before['sip443']) || !empty($after['sip443'])) {
            $old = self::sip443Name($before);
            $names = self::syncSip443();
            $new = self::sip443Name($after);
            if ($new !== '' && $new !== $old && in_array($new, $names, true) && @file_get_contents(CertificateService::MODE_FILE) === "letsencrypt\n") {
                $note = ' ' . sprintf(t('phones.region_sip443_cert'), $new);
            }
        }
        if ((string) ($before['sip_server'] ?? '') !== (string) ($after['sip_server'] ?? '')) {
            markPendingSync('extensions', 'phone_region', (int) ($after['id'] ?? $before['id'] ?? 0), 'Region: ' . ($after['name'] ?? $before['name'] ?? ''), 'update', $_SESSION['user_id'] ?? null);
        }
        return $note;
    }

    public static function save(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $id = (int) ($data['region_id'] ?? 0);
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 50 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
                throw new \Exception(t('phones.region_err_name'));
            }
            $fields = self::validate($data);
            $db = getDB();
            $same = $db->prepare('SELECT id FROM pbx_phone_regions WHERE name = ? AND id <> ?');
            $same->execute([$name, $id]);
            if ($same->fetchColumn() !== false) {
                throw new \Exception(t('phones.region_err_taken'));
            }
            if ($id > 0 && !self::exists($id)) {
                throw new \Exception(t('phones.region_err_missing'));
            }
            $columns = array_keys($fields);
            $before = $id > 0 ? self::forPhone(['region_id' => $id]) : [];
            if ($id > 0) {
                $set = implode(', ', array_map(static fn(string $c): string => "{$c} = ?", $columns));
                $db->prepare("UPDATE pbx_phone_regions SET name = ?, {$set} WHERE id = ?")->execute([$name, ...array_values($fields), $id]);
            } else {
                $marks = implode(', ', array_fill(0, count($columns), '?'));
                $db->prepare('INSERT INTO pbx_phone_regions (name, ' . implode(', ', $columns) . ") VALUES (?, {$marks})")->execute([$name, ...array_values($fields)]);
                $id = (int) $db->lastInsertId();
            }
            self::resetCache();
            writeAuditLog('phones', 'phone_region', $id, $name, !empty($data['region_id']) ? 'update' : 'create', $_SESSION['user_id'] ?? null);
            PhoneProvisionService::exportTftpQuietly();
            // Saving a region with SIP on 443 again also repairs a list a failed write left behind.
            return sprintf(t('phones.region_saved'), $name) . self::afterChange($before, ['id' => $id, 'name' => $name] + $fields);
        });
    }

    /** The default region and a region with phones stay, so no phone is moved without a choice. */
    public static function delete(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $id = (int) ($data['region_id'] ?? 0);
            $region = self::forPhone(['region_id' => $id]);
            if ($region === [] || (int) $region['id'] !== $id) {
                throw new \Exception(t('phones.region_err_missing'));
            }
            if (!empty($region['is_default'])) {
                throw new \Exception(t('phones.region_err_default'));
            }
            $db = getDB();
            $count = $db->prepare('SELECT COUNT(*) FROM pbx_phones WHERE region_id = ?');
            $count->execute([$id]);
            $phones = (int) $count->fetchColumn();
            if ($phones > 0) {
                throw new \Exception(sprintf(t('phones.region_err_used'), $phones));
            }
            $db->prepare('DELETE FROM pbx_phone_regions WHERE id = ?')->execute([$id]);
            self::resetCache();
            self::afterChange($region, []);
            writeAuditLog('phones', 'phone_region', $id, (string) $region['name'], 'delete', $_SESSION['user_id'] ?? null);
            return t('phones.region_deleted');
        });
    }
}
