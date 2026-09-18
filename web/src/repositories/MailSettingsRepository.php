<?php

class MailSettingsRepository extends BaseRepository
{
    protected static string $table = 'sys_settings';

    public static function allSettings(): array
    {
        return static::db()->query("SELECT setting_key, setting_value FROM sys_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function getPostfixStatus(): array
    {
        exec('systemctl is-active --quiet postfix 2>/dev/null', $unused, $ret);
        $is_running = ($ret === 0);

        $relayhost = trim((string)shell_exec('postconf -h relayhost 2>/dev/null')) ?: '';

        $queue_raw = trim((string)shell_exec('mailq 2>/dev/null | tail -1'));
        $queue_count = 0;
        if (preg_match('/--\s+(\d+)\s+Kbytes\s+in\s+(\d+)\s+Request/i', $queue_raw, $m)) {
            $queue_count = (int)$m[2];
        } elseif (stripos($queue_raw, 'Mail queue is empty') !== false) {
            $queue_count = 0;
        }

        return [
            'is_running' => $is_running,
            'relayhost' => $relayhost,
            'queue_summary' => $queue_raw ?: 'Kuyruk boş',
            'queue_count' => $queue_count
        ];
    }
}
