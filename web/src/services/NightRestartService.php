<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../audit_log.php';
require_once __DIR__ . '/../asterisk_helper.php';

/**
 * Service for safe night-time Asterisk restarts (Roadmap 2.5).
 * Only restarts when active calls = 0 within the defined maintenance window.
 */
class NightRestartService
{
    public static function isDue(): bool
    {
        if (getSystemSetting('night_restart_enabled', '0') !== '1') {
            return false;
        }

        $wStart = getSystemSetting('night_restart_window_start', '03:00');
        $wEnd = getSystemSetting('night_restart_window_end', '05:00');

        $now = date('H:i');
        if ($wStart <= $wEnd) {
            $inWindow = ($now >= $wStart && $now <= $wEnd);
        } else {
            // Maintenance window spans across midnight (e.g. 23:00 to 04:00)
            $inWindow = ($now >= $wStart || $now <= $wEnd);
        }

        if (!$inWindow) {
            return false;
        }

        // Check if already restarted today
        $lastRestartDate = getSystemSetting('night_restart_last_date', '');
        $today = date('Y-m-d');
        if ($lastRestartDate === $today) {
            return false;
        }

        // Active call count must be 0
        $res = AsteriskHelper::execCLI('core show channels count');
        $activeCalls = 0;
        if (preg_match('/(\d+)\s+active call/i', (string)($res['output'] ?? ''), $m)) {
            $activeCalls = (int)$m[1];
        }

        return $activeCalls === 0;
    }

    public static function executeRestart(): bool
    {
        if (!self::isDue()) {
            return false;
        }

        setSystemSetting('night_restart_last_date', date('Y-m-d'));
        if (function_exists('writeAuditLog')) {
            writeAuditLog('asterisk', 'maintenance', 'night_restart', 'Safe night restart executed during maintenance window (0 active calls)', 'restart');
        }

        // Only trigger systemctl restart if not in test environment
        if (getenv('AIPBX_NO_ASTERISK') !== '1') {
            exec('systemctl restart asterisk >/dev/null 2>&1 &');
        }
        return true;
    }
}
