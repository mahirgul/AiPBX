<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../audit_log.php';
require_once __DIR__ . '/../asterisk_helper.php';

/**
 * Night restart window (PBX settings): needrestart no longer restarts Asterisk
 * (that dropped calls), so a library update takes effect only on the next
 * restart. Inside the window, Asterisk is restarted when it still runs on
 * replaced libraries and no call is in progress — at most once a day, never
 * when the call count cannot be read. Run every minute by task_dispatcher.php.
 */
class NightRestartService
{
    public static function isDue(): bool
    {
        if (getSystemSetting('night_restart_enabled', '0') !== '1') {
            return false;
        }
        if (!self::inWindow(date('H:i'), (string) getSystemSetting('night_restart_window_start', '03:00'),
            (string) getSystemSetting('night_restart_window_end', '05:00'))) {
            return false;
        }
        if (getSystemSetting('night_restart_last_date', '') === date('Y-m-d')) {
            return false;
        }
        // Only when it is needed: Asterisk still maps a security-relevant library
        // an update replaced (not for libsystemd and the like).
        if (!AsteriskHelper::replacedSecurityLibraries()) {
            return false;
        }
        return AsteriskHelper::activeCallCount() === 0;
    }

    /** "HH:MM" inside from-to; a window may cross midnight (23:00-04:00). */
    public static function inWindow(string $now, string $from, string $to): bool
    {
        return $from <= $to ? ($now >= $from && $now <= $to) : ($now >= $from || $now <= $to);
    }

    public static function executeRestart(): bool
    {
        if (!self::isDue()) {
            return false;
        }
        setSystemSetting('night_restart_last_date', date('Y-m-d'));
        writeAuditLog('asterisk', 'maintenance', 'night_restart', 'Night restart: 0 calls, updated security libraries loaded', 'restart', null);
        if (getenv('AIPBX_NO_ASTERISK') !== '1') {
            exec('systemctl restart asterisk >/dev/null 2>&1 &');
        }
        return true;
    }
}
