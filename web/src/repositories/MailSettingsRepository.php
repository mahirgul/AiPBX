<?php

class MailSettingsRepository extends BaseRepository
{
    protected static string $table = 'sys_settings';

    public static function allSettings(): array
    {
        $settings = static::db()->query("SELECT setting_key, setting_value FROM sys_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

        // If mail_relay_host is not yet set in database, fallback to live Postfix relayhost configuration
        if (!array_key_exists('mail_relay_host', $settings)) {
            $rawRelay = trim((string)shell_exec('postconf -h relayhost 2>/dev/null'));
            if (!empty($rawRelay)) {
                if (preg_match('/^(?:\[([^\]]+)\]|([^:]+))(?::(\d+))?$/', $rawRelay, $m)) {
                    $settings['mail_relay_host'] = !empty($m[1]) ? $m[1] : (!empty($m[2]) ? $m[2] : '');
                    if (!empty($m[3]) && (empty($settings['mail_smtp_port']) || $settings['mail_smtp_port'] === '25')) {
                        $settings['mail_smtp_port'] = $m[3];
                    }
                } else {
                    $settings['mail_relay_host'] = trim($rawRelay, '[]');
                }
            }
        }

        return $settings;
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
