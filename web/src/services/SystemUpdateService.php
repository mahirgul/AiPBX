<?php
require_once __DIR__ . '/../priv_helper.php';

/**
 * System Update page.
 *
 * The update itself is done as root by /usr/local/sbin/aipbx-update (see
 * conf/sbin/aipbx-update); the portal only starts it through aipbx-priv and
 * reads progress from the status files aipbx-update writes. Apache restarts
 * during an update, so it runs in its own systemd unit, not inside a request.
 */
class SystemUpdateService
{
    public const CHECK_FILE = '/var/lib/aipbx/update-check.json';
    public const STATUS_FILE = '/var/lib/aipbx/update-status.json';
    private const PAGE_TEST_FAILED = '/var/lib/aipbx/update-page-test-failed';
    public const LOG_FILE = '/var/log/aipbx/update.log';

    public static function currentVersion(): string
    {
        return AIPBX_VERSION;
    }

    /** Result of the last version check (daily cron or "Check for updates"). */
    public static function lastCheck(): ?array
    {
        return self::readJson(self::CHECK_FILE);
    }

    /** State of the last / running update. */
    public static function status(): ?array
    {
        $status = self::readJson(self::STATUS_FILE);
        if ($status !== null && self::isAbandoned($status, time(), self::unitActive())) {
            $status['state'] = 'failed';
            $status['step'] = t('system_update.interrupted');
            $status['waiting_calls'] = 0;
        }
        return $status;
    }

    /**
     * "running" left behind by an update that died, for instance in a reboot, would refuse
     * every later one. A wait writes its status every few seconds; other steps can be quiet
     * for a long time, so those get an hour, and never while the update's unit is alive.
     */
    public static function isAbandoned(array $status, int $now, bool $unitActive): bool
    {
        if (($status['state'] ?? '') !== 'running' || $unitActive) {
            return false;
        }
        $at = strtotime((string) ($status['updated_at'] ?? ''));
        if ($at === false) {
            return true;
        }
        return $now - $at > ((int) ($status['waiting_calls'] ?? 0) > 0 ? 120 : 3600);
    }

    private static function unitActive(): bool
    {
        exec('systemctl is-active --quiet aipbx-update.service 2>/dev/null', $out, $rc);
        return $rc === 0;
    }

    public static function isRunning(): bool
    {
        $st = self::status();
        return $st !== null && ($st['state'] ?? '') === 'running';
    }

    /** Last lines of the update log (progress in the UI). */
    public static function logTail(int $lines = 60): string
    {
        if (!is_readable(self::LOG_FILE)) {
            return '';
        }
        $size = filesize(self::LOG_FILE);
        $fh = fopen(self::LOG_FILE, 'rb');
        if (!$fh) {
            return '';
        }
        fseek($fh, max(0, $size - 64 * 1024));
        $data = (string) stream_get_contents($fh);
        fclose($fh);
        // install.sh prints colours; terminal escape codes look like garbage on the page.
        $data = (string) preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $data);
        $all = preg_split('/\r?\n/', rtrim($data));
        return implode("\n", array_slice($all, -$lines));
    }

    /** Is a newer release available on GitHub (aipbx-update --check)? */
    public static function check(): array
    {
        $res = PrivHelper::run(['update', 'check']);
        $check = self::lastCheck();
        if (!$res['success'] || empty($check['ok'])) {
            return ['success' => false, 'error' => $check['error'] ?? ($res['output'] ?: t('system_update.unreachable'))];
        }
        return ['success' => true, 'check' => $check];
    }

    /** Starts the update in the background. */
    public static function start(string $calls = 'wait'): array
    {
        if (self::isRunning()) {
            return ['success' => false, 'error' => t('system_update.already_running')];
        }
        $args = ['update', 'start'];
        $args[] = $calls === 'end' ? '--allow-calls' : '--wait-calls';
        $res = PrivHelper::run($args);
        if (!$res['success']) {
            return ['success' => false, 'error' => $res['output'] ?: t('system_update.start_failed')];
        }
        writeAuditLog(null, 'system', 'update', 'System update started (from ' . AIPBX_VERSION . ')', 'update', $_SESSION['user_id'] ?? null);
        return ['success' => true];
    }

    /** While the update waits for calls: stop waiting and end them, or give the update up. */
    public static function whileWaiting(string $what): array
    {
        if ((int) (self::status()['waiting_calls'] ?? 0) < 1) {
            return ['success' => false, 'error' => t('system_update.not_waiting')];
        }
        $res = PrivHelper::run(['update', $what === 'end' ? 'end-calls' : 'cancel']);
        if (!$res['success']) {
            return ['success' => false, 'error' => $res['output'] ?: t('system_update.not_waiting')];
        }
        writeAuditLog(null, 'system', 'update', $what === 'end' ? 'Update: calls ended instead of waiting' : 'Update cancelled while waiting for calls', 'update', $_SESSION['user_id'] ?? null);
        return ['success' => true];
    }

    private static function readJson(string $file): ?array
    {
        if (!is_readable($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /** When the page test after the last update found a problem, or null. It runs after the update ends. */
    public static function pageTestFailedAt(): ?string
    {
        return is_readable(self::PAGE_TEST_FAILED) ? trim((string) file_get_contents(self::PAGE_TEST_FAILED)) : null;
    }
}
