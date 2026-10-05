<?php
require_once __DIR__ . '/../asterisk_sync.php';
require_once __DIR__ . '/../priv_helper.php';

/**
 * fail2ban management service
 * Runs `fail2ban-client` as root through PrivHelper (the aipbx-priv `f2b`
 * subcommands) — no direct sudo call. jail.local is NOT touched for
 * persistence — a separate override file (OVERRIDE_FILE) is used, which is
 * also installed by aipbx-priv from STAGING_FILE.
 */
class Fail2banService {

    /**
     * These IPs can never be removed from the ignoreip list — localhost must
     * always stay safe. "127.0.0.0/8", NOT "127.0.0.1/8" — fail2ban-client
     * normalizes the value to the network address and returns it that way
     * (verified live); that is the value shown/removable in the UI, so the
     * protection has to match it.
     */
    const PROTECTED_IGNOREIPS = ['127.0.0.0/8', '::1'];

    const OVERRIDE_FILE = '/etc/fail2ban/jail.d/zz-ai-pbx.local';

    /**
     * jail.d belongs to root (a jail file can define a command to run, so the
     * web user cannot write there). The panel stages the file here and
     * `aipbx-priv f2b install-override` validates it line by line and installs it.
     */
    const STAGING_FILE = '/var/lib/aipbx/fail2ban-override.local';

    private static function run(string ...$args): array {
        return PrivHelper::run(array_merge(['f2b'], $args));
    }

    private static function get(string $jail, string $key): string {
        return self::run('get', $jail, $key)['output'];
    }

    public static function listJails(): array {
        $out = self::run('status')['output'];
        if (!preg_match('/Jail list:\s*(.*)$/m', $out, $m)) return [];
        $names = array_filter(array_map('trim', explode(',', $m[1])));
        return array_values($names);
    }

    /**
     * Live state of a jail (banned IPs, counters) + its settings (bantime/
     * findtime/maxretry) — all read live from fail2ban-client (instead of
     * parsing files); the running service is the single source of truth.
     */
    public static function jailDetail(string $jail): ?array {
        $jails = self::listJails();
        if (!in_array($jail, $jails, true)) return null;

        $status = self::run('status', $jail)['output'];
        $banned_ips = [];
        if (preg_match('/Banned IP list:\s*(.*)$/m', $status, $m)) {
            $banned_ips = array_values(array_filter(preg_split('/\s+/', trim($m[1]))));
        }
        $currently_banned = 0;
        if (preg_match('/Currently banned:\s*(\d+)/', $status, $m)) $currently_banned = (int) $m[1];
        $total_banned = 0;
        if (preg_match('/Total banned:\s*(\d+)/', $status, $m)) $total_banned = (int) $m[1];

        return [
            'name' => $jail,
            'currently_banned' => $currently_banned,
            'total_banned' => $total_banned,
            'banned_ips' => $banned_ips,
            'bantime' => (int) trim(self::get($jail, 'bantime')),
            'findtime' => (int) trim(self::get($jail, 'findtime')),
            'maxretry' => (int) trim(self::get($jail, 'maxretry')),
        ];
    }

    public static function getIgnoreIps(): array {
        $jails = self::listJails();
        if (empty($jails)) return [];
        $out = self::get($jails[0], 'ignoreip');
        $ips = [];
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^\s*[|`]-\s*(.+)$/', $line, $m)) {
                $ips[] = trim($m[1]);
            }
        }
        return $ips;
    }

    /**
     * Validates an IP or CIDR block; returns the message on error, null if valid.
     *
     * Why (found in the 2026-08-31 audit): the old check only passed the part
     * BEFORE "/" to `filter_var()`, so the CIDR suffix was never validated —
     * entering "0.0.0.0/0" whitelisted THE WHOLE INTERNET and effectively
     * disabled fail2ban. FirewallService already had full-format validation;
     * the fail2ban side had fallen behind.
     *
     * The widest accepted block is /8 (so legitimate private networks such as
     * "10.0.0.0/8" keep working); anything wider risks disabling it by accident.
     */
    private static function validateIpOrCidr(string $value): ?string {
        $parts = explode('/', $value, 2);
        $addr = $parts[0];
        $is_v6 = strpos($addr, ':') !== false;

        $flag = $is_v6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
        if (filter_var($addr, FILTER_VALIDATE_IP, $flag) === false) {
            return 'Geçersiz IP adresi!';
        }
        if (!isset($parts[1])) return null; // plain IP, no suffix

        if (!preg_match('/^\d{1,3}$/', $parts[1])) {
            return 'Geçersiz CIDR eki! (ör. 192.0.2.0/24)';
        }
        $prefix = (int) $parts[1];
        $max = $is_v6 ? 128 : 32;
        $min = $is_v6 ? 32 : 8;
        if ($prefix > $max) {
            return "Geçersiz CIDR eki! (en fazla /{$max})";
        }
        if ($prefix < $min) {
            return "Bu blok fazla geniş (/{$prefix}) — fail2ban'ı fiilen devre dışı bırakır. En geniş /{$min} kabul ediliyor.";
        }
        return null;
    }

    public static function unbanIp(string $jail, string $ip, string $csrfToken): array {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik kodu!'];
        }
        $jail = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($jail));
        $ip = trim($ip);
        if (!in_array($jail, self::listJails(), true) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['success' => false, 'error' => 'Geçersiz jail veya IP adresi.'];
        }
        $res = self::run('set', $jail, 'unbanip', $ip);
        if (!$res['success']) {
            return ['success' => false, 'error' => 'IP ban kaldırılamadı: ' . trim($res['output'])];
        }
        writeAuditLog(null, 'fail2ban', $jail, "IP ban kaldırıldı ({$jail}): {$ip}", 'unban', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => "{$ip} adresinin banı kaldırıldı."];
    }

    /**
     * Writes a jail's bantime/findtime/maxretry both live (effective at once,
     * fail2ban-client set) and to the override file (so it survives a
     * fail2ban restart).
     */
    public static function updateJailConfig(string $jail, int $bantime, int $findtime, int $maxretry, string $csrfToken): array {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik kodu!'];
        }
        $jail = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($jail));
        if (!in_array($jail, self::listJails(), true)) {
            return ['success' => false, 'error' => 'Geçersiz jail.'];
        }
        if ($bantime < 60 || $findtime < 60 || $maxretry < 1) {
            return ['success' => false, 'error' => 'Değerler mantıksız (bantime/findtime en az 60sn, maxretry en az 1 olmalı).'];
        }

        $r1 = self::run('set', $jail, 'bantime', (string) $bantime);
        $r2 = self::run('set', $jail, 'findtime', (string) $findtime);
        $r3 = self::run('set', $jail, 'maxretry', (string) $maxretry);
        if (!$r1['success'] || !$r2['success'] || !$r3['success']) {
            return ['success' => false, 'error' => 'Ayarlar canlıya uygulanamadı: ' . trim($r1['output'] . ' ' . $r2['output'] . ' ' . $r3['output'])];
        }

        $state = self::readOverrideState();
        $state['jails'][$jail] = ['bantime' => $bantime, 'findtime' => $findtime, 'maxretry' => $maxretry];
        $persist_err = self::writeOverrideState($state);

        writeAuditLog(null, 'fail2ban', $jail, "Jail ayarları güncellendi ({$jail}): bantime={$bantime} findtime={$findtime} maxretry={$maxretry}", 'update', $_SESSION['user_id'] ?? null);
        if ($persist_err !== null) return self::persistFailure("{$jail} ayarları", $persist_err);
        return ['success' => true, 'message' => "{$jail} ayarları güncellendi."];
    }

    public static function addIgnoreIp(string $ip, string $csrfToken): array {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik kodu!'];
        }
        $ip = trim($ip);
        if (($err = self::validateIpOrCidr($ip)) !== null) {
            return ['success' => false, 'error' => $err];
        }
        foreach (self::listJails() as $jail) {
            self::run('set', $jail, 'addignoreip', $ip);
        }
        $state = self::readOverrideState();
        if (!in_array($ip, $state['ignoreip'], true)) $state['ignoreip'][] = $ip;
        $persist_err = self::writeOverrideState($state);

        writeAuditLog(null, 'fail2ban', 'ignoreip', "IP beyaz listeye eklendi: {$ip}", 'create', $_SESSION['user_id'] ?? null);
        if ($persist_err !== null) return self::persistFailure("{$ip} beyaz listeye eklendi", $persist_err);
        return ['success' => true, 'message' => "{$ip} beyaz listeye eklendi."];
    }

    public static function removeIgnoreIp(string $ip, string $csrfToken): array {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik kodu!'];
        }
        $ip = trim($ip);
        if (in_array($ip, self::PROTECTED_IGNOREIPS, true)) {
            return ['success' => false, 'error' => "{$ip} (localhost) beyaz listeden asla kaldırılamaz!"];
        }
        foreach (self::listJails() as $jail) {
            self::run('set', $jail, 'delignoreip', $ip);
        }
        $state = self::readOverrideState();
        $state['ignoreip'] = array_values(array_diff($state['ignoreip'], [$ip]));
        $persist_err = self::writeOverrideState($state);

        writeAuditLog(null, 'fail2ban', 'ignoreip', "IP beyaz listeden kaldırıldı: {$ip}", 'delete', $_SESSION['user_id'] ?? null);
        if ($persist_err !== null) return self::persistFailure("{$ip} beyaz listeden kaldırıldı", $persist_err);
        return ['success' => true, 'message' => "{$ip} beyaz listeden kaldırıldı."];
    }

    /**
     * The change was applied LIVE but could not be written to the persistent
     * file — never show this half-success as "success"; the admin must know
     * the change will be lost when fail2ban restarts. The detailed reason also
     * goes to the audit log (see the project's reload/rollback error pattern:
     * entity_label carries the error text, not just the name).
     */
    private static function persistFailure(string $what, string $reason): array {
        writeAuditLog(null, 'fail2ban', 'persist_failed', mb_substr("KALICI KAYIT BAŞARISIZ ({$what}): {$reason}", 0, 255), 'error', $_SESSION['user_id'] ?? null);
        return [
            'success' => false,
            'error' => "{$what} — CANLIYA uygulandı, ANCAK kalıcı olarak kaydedilemedi ({$reason}). "
                     . 'fail2ban yeniden başlatılırsa bu değişiklik KAYBOLUR.',
        ];
    }

    private static function readOverrideState(): array {
        $state = ['ignoreip' => self::getIgnoreIps(), 'jails' => []];
        if (is_file(self::OVERRIDE_FILE)) {
            $parsed = @parse_ini_file(self::OVERRIDE_FILE, true, INI_SCANNER_RAW);
            if (is_array($parsed)) {
                foreach ($parsed as $section => $kv) {
                    if ($section === 'DEFAULT') continue;
                    $state['jails'][$section] = [
                        'bantime' => (int) ($kv['bantime'] ?? 0),
                        'findtime' => (int) ($kv['findtime'] ?? 0),
                        'maxretry' => (int) ($kv['maxretry'] ?? 0),
                    ];
                }
            }
        }
        return $state;
    }

    /**
     * Writes the override file. Returns null on success, the REASON on
     * failure — callers MUST show it to the user.
     *
     * Why there is a return value (found in the 2026-08-31 audit): the old
     * version never checked the result of `file_put_contents()` and the file
     * had stayed root:root 644 (created by a CLI test run as root for the
     * first time) — PHP-FPM runs as the 'asterisk' user, so the write failed
     * SILENTLY: the jail setting/whitelist change was applied live but was not
     * persistent, while the admin was shown "success". The change was lost
     * when fail2ban restarted.
     */
    private static function writeOverrideState(array $state): ?string {
        // SAFETY LOCK (found in the 2026-08-31 audit): if the ignoreip list
        // came in empty (e.g. reading fail2ban-client failed for some reason)
        // this file would write `[DEFAULT] ignoreip =` (EMPTY) and override the
        // real whitelist in jail.local — after a fail2ban restart localhost and
        // the admin IP would be unprotected. NEVER write with an empty list; the
        // protected IPs are forced into the list in every case.
        $ignoreip = array_values(array_unique(array_merge(self::PROTECTED_IGNOREIPS, array_filter($state['ignoreip']))));
        if (empty($state['ignoreip'])) {
            // read failed → leave the existing file alone (do not break it silently)
            return 'mevcut beyaz liste okunamadı, dosya güvenlik gereği hiç değiştirilmedi';
        }

        $lines = [];
        $lines[] = '# AI PBX panelinden yönetiliyor (otomatik üretilir, elle düzenlemeyin)';
        $lines[] = '[DEFAULT]';
        $lines[] = 'ignoreip = ' . implode(' ', $ignoreip);
        $lines[] = '';
        foreach ($state['jails'] as $jail => $cfg) {
            $lines[] = "[{$jail}]";
            $lines[] = 'bantime = ' . intval($cfg['bantime']);
            $lines[] = 'findtime = ' . intval($cfg['findtime']);
            $lines[] = 'maxretry = ' . intval($cfg['maxretry']);
            $lines[] = '';
        }

        if (!FileHelper::writeFile(self::STAGING_FILE, implode("\n", $lines) . "\n", null, null, 0640)) {
            return self::STAGING_FILE . ' yazılamadı (dosya izni/sahipliği?)';
        }
        $res = PrivHelper::run(['f2b', 'install-override']);
        if (!$res['success']) {
            return self::OVERRIDE_FILE . ' kurulamadı: ' . trim($res['output']);
        }
        return null;
    }
}

/**
 * Is the fail2ban service really active (read-only, no sudo needed).
 */
function fail2ban_is_active(): bool {
    $out = shell_exec('systemctl is-active fail2ban 2>&1');
    return trim((string) $out) === 'active';
}
