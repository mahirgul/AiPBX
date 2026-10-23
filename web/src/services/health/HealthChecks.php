<?php
/**
 * The checks of Admin → Health checks (docs/health.md). Each one returns
 * [status, message]:
 *   ok    working
 *   warn  needs attention soon (disk 85 %, certificate in 14 days …)
 *   fail  not working (a service is down, a trunk is unreachable …)
 *   info  worth knowing, never an alarm (a newer release)
 *   off   not used on this server (no trunks, FTP switched off …)
 *
 * They run as root from bin/aipbx_health.php (cron, `aipbx-selftest`), so they
 * may read anything; none of them changes anything.
 */
final class HealthChecks
{
    public const STATUSES = ['ok', 'warn', 'fail', 'info', 'off', 'unknown'];

    /** Key => default interval in minutes (0 = off by default). The order is the page's. */
    public const DEFAULTS = [
        'asterisk' => 5,
        'database' => 5,
        'web' => 5,
        'chat' => 5,
        'trunks' => 5,
        'disk' => 60,
        'memory' => 15,
        'mail' => 60,
        'pending_changes' => 60,
        'fax_queue' => 60,
        'call_records' => 15,
        'call_quality' => 5,
        'alerts' => 5,
        'network_services' => 15,
        'server_address' => 60,
        'local_ai' => 15,
        'certificate' => 1440,
        'backup' => 1440,
        'installation' => 1440,
        'update' => 1440,
    ];

    /** Intervals offered on the page (minutes). */
    public const INTERVALS = [0, 5, 15, 60, 360, 1440];

    /** @return array{0: string, 1: string} */
    public static function run(string $key): array
    {
        try {
            return match ($key) {
                'asterisk' => self::asterisk(),
                'database' => self::database(),
                'web' => self::web(),
                'chat' => self::service('aipbx-chat'),
                'trunks' => self::trunks(),
                'disk' => self::disk(),
                'memory' => self::memory(),
                'mail' => self::mail(),
                'pending_changes' => self::pendingChanges(),
                'fax_queue' => self::faxQueue(),
                'call_records' => self::callRecords(),
                'call_quality' => self::callQuality(),
                'alerts' => self::alerts(),
                'network_services' => self::networkServices(),
                'server_address' => self::serverAddress(),
                'local_ai' => self::localAi(),
                'certificate' => self::certificate(),
                'backup' => self::backup(),
                'installation' => self::installation(),
                'update' => self::update(),
                default => ['fail', 'unknown check'],
            };
        } catch (\Throwable $e) {
            return ['fail', mb_substr($e->getMessage(), 0, 300)];
        }
    }

    // ------------------------------------------------------------------ checks

    private static function asterisk(): array
    {
        $out = self::sh('asterisk -rx "core show uptime seconds"');
        if (!preg_match('/System uptime:\s*(\d+)/', $out, $m)) {
            return ['fail', t('health.msg_asterisk_down')];
        }
        $calls = self::sh('asterisk -rx "core show channels count"');
        $n = preg_match('/(\d+) active call/', $calls, $c) ? (int) $c[1] : 0;
        $msg = sprintf(t('health.msg_asterisk_ok'), self::duration((int) $m[1]), $n);
        // needrestart leaves Asterisk alone (it dropped calls): after a library
        // update it keeps running on the replaced files until restarted.
        $pid = (int) trim(self::sh('pidof -s asterisk'));
        $maps = $pid > 0 ? (string) @file_get_contents("/proc/{$pid}/maps") : '';
        if (preg_match_all('#\s(/\S+\.so[.\d]*) \(deleted\)$#m', $maps, $d)) {
            $libs = array_map('basename', array_unique($d[1]));
            return ['warn', $msg . ' ' . sprintf(t('health.msg_asterisk_old_libs'), implode(', ', array_slice($libs, 0, 5)))];
        }
        return ['ok', $msg];
    }

    private static function database(): array
    {
        $db = getDB();
        $db->query('SELECT 1')->fetchColumn();
        $tables = (int) $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
        return ['ok', sprintf(t('health.msg_database_ok'), $tables)];
    }

    private static function web(): array
    {
        $code = trim(self::sh('curl -sk -o /dev/null -w "%{http_code}" --max-time 10 https://127.0.0.1/login'));
        if ($code === '200') {
            return ['ok', t('health.msg_web_ok')];
        }
        return ['fail', sprintf(t('health.msg_web_fail'), $code !== '' ? $code : '—')];
    }

    private static function service(string $unit): array
    {
        $state = trim(self::sh('systemctl is-active ' . escapeshellarg($unit)));
        return $state === 'active'
            ? ['ok', sprintf(t('health.msg_service_ok'), $unit)]
            : ['fail', sprintf(t('health.msg_service_down'), $unit, $state ?: 'unknown')];
    }

    private static function trunks(): array
    {
        $trunks = getDB()->query('SELECT trunk_name FROM pbx_trunks WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
        if (!$trunks) {
            return ['off', t('health.msg_trunks_none')];
        }
        // Endpoints with qualify: "Unavailable" means the provider does not answer.
        $contacts = self::sh('asterisk -rx "pjsip show contacts"');
        $regs = self::sh('asterisk -rx "pjsip show registrations"');
        $bad = [];
        $unchecked = 0;
        foreach ($trunks as $t) {
            $q = preg_quote($t, '/');
            if (preg_match('/Contact:\s+' . $q . '\/\S+\s+\S+\s+(\S+)/', $contacts, $m)) {
                if ($m[1] === 'Unavail') {
                    $bad[] = $t . ' (' . t('health.trunk_unreachable') . ')';
                    continue;
                }
                // NonQual / Unknown: qualify off, or Asterisk has just started.
                $unchecked += $m[1] === 'Avail' ? 0 : 1;
            }
            if (preg_match('/^\s*' . $q . '(?:-reg)?\/\S+\s+\S+\s+(Rejected|Unregistered|Stopped)/mi', $regs, $m)) {
                $bad[] = $t . ' (' . strtolower($m[1]) . ')';
            }
        }
        if ($bad) {
            return ['fail', sprintf(t('health.msg_trunks_bad'), implode(', ', $bad))];
        }
        $msg = sprintf(t('health.msg_trunks_ok'), count($trunks) - $unchecked);
        return ['ok', $unchecked ? $msg . ' ' . sprintf(t('health.msg_trunks_unchecked'), $unchecked) : $msg];
    }

    private static function disk(): array
    {
        $worst = 0;
        $parts = [];
        foreach (array_unique(['/', '/var', '/var/spool/asterisk']) as $path) {
            if (!is_dir($path)) {
                continue;
            }
            $total = @disk_total_space($path);
            $free = @disk_free_space($path);
            if (!$total) {
                continue;
            }
            $pct = (int) round(100 * ($total - $free) / $total);
            // One line per file system: /var on the root file system is not listed twice.
            $st = @stat($path);
            $dev = $st ? (string) $st['dev'] : $path;
            if (isset($parts[$dev])) {
                continue;
            }
            $parts[$dev] = sprintf('%s %d %% (%s free)', $path, $pct, self::bytes((float) $free));
            $worst = max($worst, $pct);
        }
        $msg = implode(', ', array_values($parts));
        return [$worst >= 95 ? 'fail' : ($worst >= 85 ? 'warn' : 'ok'), $msg];
    }

    private static function memory(): array
    {
        $info = @file_get_contents('/proc/meminfo') ?: '';
        if (!preg_match('/MemTotal:\s+(\d+)/', $info, $t) || !preg_match('/MemAvailable:\s+(\d+)/', $info, $a)) {
            return ['info', 'n/a'];
        }
        $pct = (int) round(100 * $a[1] / $t[1]);
        $msg = sprintf(t('health.msg_memory'), self::bytes($a[1] * 1024.0), $pct);
        return [$pct < 5 ? 'fail' : ($pct < 10 ? 'warn' : 'ok'), $msg];
    }

    private static function mail(): array
    {
        $state = trim(self::sh('systemctl is-active postfix'));
        if ($state !== 'active') {
            return ['fail', sprintf(t('health.msg_service_down'), 'postfix', $state ?: 'unknown')];
        }
        // postqueue -j: one JSON object per queued message.
        $old = 0;
        $total = 0;
        foreach (preg_split('/\R/', self::sh('postqueue -j')) ?: [] as $line) {
            $m = json_decode($line, true);
            if (!is_array($m)) {
                continue;
            }
            $total++;
            if (time() - (int) ($m['arrival_time'] ?? time()) > 3600) {
                $old++;
            }
        }
        if ($old > 0) {
            return ['warn', sprintf(t('health.msg_mail_stuck'), $old, $total)];
        }
        return ['ok', $total ? sprintf(t('health.msg_mail_queue'), $total) : t('health.msg_mail_ok')];
    }

    private static function pendingChanges(): array
    {
        $n = (int) getDB()->query('SELECT COUNT(*) FROM sys_pending_sync WHERE changed_at < NOW() - INTERVAL 1 HOUR')->fetchColumn();
        return $n > 0 ? ['warn', sprintf(t('health.msg_pending'), $n)] : ['ok', t('health.msg_pending_ok')];
    }

    /**
     * Calls Asterisk handled since it started must be in the call history. On
     * 2026-10-21 Asterisk started before MariaDB, the CDR backend found no
     * connection and wrote nothing for 11 hours.
     */
    private static function callRecords(): array
    {
        $up = self::sh('asterisk -rx "core show uptime seconds"');
        if (!preg_match('/System uptime:\s*(\d+)/', $up, $u)) {
            return ['off', t('health.msg_asterisk_down')];
        }
        $calls = self::sh('asterisk -rx "core show channels count"');
        $processed = preg_match('/(\d+) calls? processed/', $calls, $c) ? (int) $c[1] : 0;
        $st = getDB()->prepare('SELECT COUNT(*) FROM asteriskcdr WHERE calldate >= NOW() - INTERVAL ? SECOND');
        $st->execute([(int) $u[1]]);
        return self::callRecordsStatus((int) $u[1], $processed, (int) $st->fetchColumn());
    }

    /** @return array{0: string, 1: string} Pure, so the rule is tested on its own. */
    public static function callRecordsStatus(int $uptime, int $processed, int $records): array
    {
        // A few calls and 15 minutes first: a call in progress has no record yet.
        if ($uptime >= 900 && $processed >= 3 && $records === 0) {
            return ['fail', sprintf(t('health.msg_cdr_missing'), $processed, self::duration($uptime))];
        }
        return ['ok', sprintf(t('health.msg_cdr_ok'), $records, self::duration($uptime))];
    }

    /** Trunks/extensions losing audio now (CallQualityService, from RTCP). */
    private static function callQuality(): array
    {
        if (getSystemSetting('signaling_capture', '0') !== '1') {
            return ['off', t('health.msg_cq_off')];
        }
        require_once dirname(__DIR__) . '/CallQualityService.php';
        $problems = CallQualityService::problems();
        if (!$problems) {
            return ['ok', t('health.msg_cq_ok')];
        }
        $lines = array_map(fn($g) => CallQualityService::alertTitle($g) . ': ' . CallQualityService::alertMessage($g), $problems);
        return ['warn', implode(' ', $lines)];
    }

    /** Problems other parts raised in the alert bell (plugins), so they are e-mailed too. */
    private static function alerts(): array
    {
        require_once dirname(__DIR__) . '/NoticeService.php';
        $open = NoticeService::openNotices();
        if (!$open) {
            return ['ok', t('health.msg_alerts_ok')];
        }
        $fail = in_array('fail', array_column($open, 'severity'), true);
        return [$fail ? 'fail' : 'warn', implode('; ', array_map(fn($n) => $n['title'] . ($n['message'] !== '' ? ': ' . $n['message'] : ''), $open))];
    }

    private static function faxQueue(): array
    {
        $db = getDB();
        $n = (int) $db->query("SELECT COUNT(*) FROM fax_sent WHERE status = 'PENDING' AND created_at < NOW() - INTERVAL 1 HOUR")->fetchColumn();
        if ($n > 0) {
            return ['warn', sprintf(t('health.msg_fax_stuck'), $n)];
        }
        $failed = (int) $db->query("SELECT COUNT(*) FROM fax_sent WHERE status = 'FAILED' AND created_at > NOW() - INTERVAL 1 DAY")->fetchColumn();
        return ['ok', sprintf(t('health.msg_fax_ok'), $failed)];
    }

    private static function networkServices(): array
    {
        $mode = (string) getSystemSetting('netsvc_mode', 'off');
        $ftp = getSystemSetting('netsvc_ftp', '0') === '1';
        $syslog = getSystemSetting('netsvc_syslog', '0') === '1';
        $ntp = getSystemSetting('netsvc_ntp_server', '0') === '1';
        if ($mode === 'off' && !$ftp && !$syslog && !$ntp) {
            return ['off', t('health.msg_netsvc_off')];
        }
        $down = [];
        if ($mode !== 'off' && trim(self::sh('systemctl is-active aipbx-dnsmasq')) !== 'active') {
            $down[] = $mode === 'dhcp' ? 'DHCP/TFTP' : 'TFTP';
        }
        if ($ftp && trim(self::sh('systemctl is-active aipbx-vsftpd')) !== 'active') {
            $down[] = 'FTP';
        }
        if ($syslog && trim(self::sh('systemctl is-active aipbx-syslog')) !== 'active') {
            $down[] = 'Syslog';
        }
        if ($ntp && trim(self::sh('systemctl is-active chrony')) !== 'active') {
            $down[] = 'NTP';
        }
        return $down ? ['fail', sprintf(t('health.msg_netsvc_down'), implode(', ', $down))] : ['ok', t('health.msg_netsvc_ok')];
    }

    /**
     * The server's address still matches the stored ones (roadmap 12). A new
     * DHCP lease, a cloned VM or a move to another network otherwise goes
     * unnoticed until TURN has no audio and phones register to the old IP.
     */
    private static function serverAddress(): array
    {
        require_once dirname(__DIR__) . '/NetworkSettingsService.php';
        $stale = NetworkSettingsService::staleAddresses();
        if ($stale === []) {
            return ['ok', t('health.msg_address_ok')];
        }
        $list = implode(', ', array_unique(array_map(fn($s) => $s['value'], $stale)));
        return ['warn', sprintf(t('health.msg_address_stale'), $list, implode(', ', NetworkSettingsService::localIps()))];
    }

    private static function localAi(): array
    {
        if (trim(self::sh('systemctl is-enabled aipbx-ai')) !== 'enabled') {
            return ['off', t('health.msg_ai_off')];
        }
        return self::service('aipbx-ai');
    }

    private static function certificate(): array
    {
        $pem = @file_get_contents('/etc/ssl/aipbx/active.crt');
        $cert = $pem ? @openssl_x509_parse($pem) : false;
        if (!$cert) {
            return ['warn', t('health.msg_cert_unreadable')];
        }
        $days = (int) floor(($cert['validTo_time_t'] - time()) / 86400);
        $msg = sprintf(t('health.msg_cert_days'), $days, date('Y-m-d', $cert['validTo_time_t']));
        return [$days < 3 ? 'fail' : ($days < 14 ? 'warn' : 'ok'), $msg];
    }

    private static function backup(): array
    {
        $dir = getenv('AIPBX_BACKUP_DIR') ?: '/var/backups/aipbx-daily';
        $newest = 0;
        foreach (glob($dir . '/*') ?: [] as $f) {
            $newest = max($newest, (int) @filemtime($f));
        }
        if ($newest === 0) {
            return ['warn', sprintf(t('health.msg_backup_none'), $dir)];
        }
        $hours = (int) floor((time() - $newest) / 3600);
        $msg = sprintf(t('health.msg_backup_age'), date('Y-m-d H:i', $newest), $hours);
        return [$hours > 26 ? 'warn' : 'ok', $msg];
    }

    /** What install.sh sets up: helper, sudo rule, cron, folders and their owners, masked units. */
    private static function installation(): array
    {
        $problems = [];
        $want = [
            '/usr/local/sbin/aipbx-priv' => 'x',
            '/etc/sudoers.d/aipbx' => 'f',
            '/etc/cron.d/aipbx' => 'f',
            '/etc/systemd/system/asterisk.service.d/aipbx.conf' => 'f',
        ];
        foreach ($want as $path => $kind) {
            if ($kind === 'x' ? !is_executable($path) : !is_file($path)) {
                $problems[] = $path . ' ' . t('health.inst_missing');
            }
        }
        $tftp = '/var/lib/aipbx/tftp';
        if (is_dir($tftp) && (posix_getpwuid((int) fileowner($tftp))['name'] ?? '') !== 'www-data') {
            $problems[] = $tftp . ' ' . t('health.inst_owner_www');
        }
        if (is_file('/usr/sbin/vsftpd') && trim(self::sh('systemctl is-enabled vsftpd')) !== 'masked') {
            $problems[] = 'vsftpd.service ' . t('health.inst_not_masked');
        }
        // Voicemail folders must stay writable for the asterisk group (portal and app move/delete messages).
        foreach (glob('/var/spool/asterisk/voicemail/default/*/{INBOX,Old}', GLOB_BRACE | GLOB_ONLYDIR) ?: [] as $d) {
            $group = posix_getgrgid((int) filegroup($d))['name'] ?? '';
            if ($group !== 'asterisk' || !((int) fileperms($d) & 0020)) {
                $problems[] = $d . ' ' . t('health.inst_vm_group');
            }
        }
        return $problems
            ? ['fail', implode('; ', array_slice($problems, 0, 5)) . (count($problems) > 5 ? ' …' : '')]
            : ['ok', t('health.msg_install_ok')];
    }

    private static function update(): array
    {
        $j = json_decode((string) @file_get_contents('/var/lib/aipbx/update-check.json'), true);
        if (!is_array($j) || empty($j['ok'])) {
            return ['info', t('health.msg_update_unknown')];
        }
        return !empty($j['available'])
            ? ['info', sprintf(t('health.msg_update_available'), $j['current'] ?? '?', $j['latest'] ?? '?')]
            : ['ok', sprintf(t('health.msg_update_current'), $j['current'] ?? '?')];
    }

    // ------------------------------------------------------------------ helpers

    private static function sh(string $cmd): string
    {
        return (string) shell_exec('timeout 15 ' . $cmd . ' 2>/dev/null');
    }

    public static function bytes(float $b): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $u) {
            if ($b < 1024 || $u === 'TB') {
                return ($u === 'B' ? (string) (int) $b : number_format($b, 1)) . ' ' . $u;
            }
            $b /= 1024;
        }
        return '';
    }

    public static function duration(int $s): string
    {
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);
        $m = intdiv($s % 3600, 60);
        return $d > 0 ? "{$d}d {$h}h" : ($h > 0 ? "{$h}h {$m}m" : "{$m}m");
    }
}
