<?php
/**
 * Kontrol Paneli için veri toplama katmanı (DB sorguları + sistem/OS
 * metrikleri). Eski src/dashboard.php'deki tüm "$db->query(...)" ve
 * shell_exec() tabanlı veri toplama mantığı buraya taşındı — Controller
 * artık sadece bu sınıfı çağırıp View'a veri geçiriyor.
 */
require_once __DIR__ . '/../asterisk_helper.php';

class DashboardRepository extends BaseRepository
{
    public static function getStats(): array
    {
        $db = static::db();

        $user_count = (int)$db->query('SELECT COUNT(*) FROM sys_users')->fetchColumn();
        $ext_count = (int)$db->query("SELECT COUNT(*) FROM sys_users WHERE extension IS NOT NULL AND extension != ''")->fetchColumn();

        // Dual-Endpoint mimarisinde her dahili -sip/-webrtc iki endpoint'e
        // sahip; çift saymamak için taban dahili numarasına göre grupla.
        $pjsip_statuses = getAsteriskPJSIPStatuses();
        $online_extensions = [];
        foreach ($pjsip_statuses as $ep => $st) {
            if (in_array($st, ['Available', 'Not in use', 'In use', 'Ringing'], true)) {
                $base_ext = preg_replace('/-(sip|webrtc|mob-webrtc)(\/.*)?$/', '', $ep);
                $online_extensions[$base_ext] = true;
            }
        }
        $online_pjsip_count = count($online_extensions);

        $trunks = $db->query('SELECT * FROM pbx_trunks WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC);
        $trunk_count = count($trunks);
        $online_trunk_count = 0;
        foreach ($trunks as $t) {
            $t_st = $pjsip_statuses[$t['trunk_name']] ?? 'Available';
            if (in_array($t_st, ['Available', 'Not in use', 'In use', 'Ringing'], true)) {
                $online_trunk_count++;
            }
        }

        $fax_rx_count = (int)$db->query('SELECT COUNT(*) FROM fax_received')->fetchColumn();
        $fax_tx_count = (int)$db->query('SELECT COUNT(*) FROM fax_sent')->fetchColumn();
        $cc_count = (int)$db->query('SELECT COUNT(*) FROM cdrs')->fetchColumn();

        return [
            'user_count' => $user_count,
            'ext_count' => $ext_count,
            'online_pjsip_count' => $online_pjsip_count,
            'trunk_count' => $trunk_count,
            'online_trunk_count' => $online_trunk_count,
            'fax_rx_count' => $fax_rx_count,
            'fax_tx_count' => $fax_tx_count,
            'cc_count' => $cc_count,
        ];
    }

    /**
     * Live call figures for the dashboard (also served by api/dashboard_live.php).
     */
    public static function getLiveStats(): array
    {
        $live = [
            'active_calls' => 0,
            'active_channels' => 0,
            'calls_processed' => 0,
            'queue_waiting' => 0,
            'trunk_usage' => [],
            'today_total' => 0,
            'today_answered' => 0,
            'today_missed' => 0,
        ];

        $count = AsteriskHelper::execCLI('core show channels count')['output'] ?? '';
        if (preg_match('/(\d+) active channels?/', $count, $m)) $live['active_channels'] = (int)$m[1];
        if (preg_match('/(\d+) active calls?/', $count, $m)) $live['active_calls'] = (int)$m[1];
        if (preg_match('/(\d+) calls? processed/', $count, $m)) $live['calls_processed'] = (int)$m[1];

        $queues = AsteriskHelper::execCLI('queue show')['output'] ?? '';
        if (preg_match_all('/ has (\d+) calls? /', $queues, $mm)) {
            $live['queue_waiting'] = array_sum(array_map('intval', $mm[1]));
        }

        // Channels in use per trunk: PJSIP channel names start with "PJSIP/<trunk>-".
        $channels = AsteriskHelper::execCLI('core show channels concise')['output'] ?? '';
        $per_trunk = [];
        foreach (explode("\n", $channels) as $line) {
            if (preg_match('#^PJSIP/(.+)-[0-9a-f]{8}!#', $line, $m)) {
                $per_trunk[$m[1]] = ($per_trunk[$m[1]] ?? 0) + 1;
            }
        }
        $db = static::db();
        $trunks = $db->query('SELECT trunk_name, title, max_channels FROM pbx_trunks WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($trunks as $t) {
            $live['trunk_usage'][] = [
                'name' => $t['trunk_name'],
                'title' => $t['title'] ?: $t['trunk_name'],
                'in_use' => $per_trunk[$t['trunk_name']] ?? 0,
                'max' => (int)$t['max_channels'],
            ];
        }

        // Today's calls, one per call (linkedid), answered if any leg was answered.
        $row = $db->query(
            "SELECT COUNT(*) AS total, COALESCE(SUM(answered), 0) AS answered FROM (
                 SELECT MAX(disposition = 'ANSWERED') AS answered
                 FROM asteriskcdr
                 WHERE calldate >= CURDATE()
                 GROUP BY COALESCE(NULLIF(linkedid, ''), uniqueid)
             ) calls"
        )->fetch(PDO::FETCH_ASSOC);
        $live['today_total'] = (int)($row['total'] ?? 0);
        $live['today_answered'] = (int)($row['answered'] ?? 0);
        $live['today_missed'] = $live['today_total'] - $live['today_answered'];

        return $live;
    }

    public static function getSystemMetrics(): array
    {
        exec('pgrep asterisk', $ast_pids);

        $db_relay = getSystemSetting('mail_relay_host', '');
        if (!empty($db_relay)) {
            $db_port = getSystemSetting('mail_smtp_port', '25');
            $mail_relay_display = '[' . $db_relay . ']:' . $db_port;
        } else {
            $mail_relay_display = trim((string)shell_exec('postconf -h relayhost 2>/dev/null')) ?: null;
        }

        return [
            'is_asterisk_running' => !empty($ast_pids),
            'mail_relay_host' => $mail_relay_display,
            'ram_info' => trim((string)shell_exec("free -m | awk '/Mem:/ {print $3\" / \"$2\" MB (\"int($3/$2*100)\"%)\"}'")) ?: 'N/A',
            'disk_info' => trim((string)shell_exec("df -h / | awk 'NR==2 {print $3\" / \"$2\" (\"$5\")\"}'")) ?: 'N/A',
            'uptime_info' => trim((string)shell_exec('uptime -p')) ?: 'N/A',
            'asterisk_version' => trim((string)shell_exec("asterisk -rx 'core show version' | head -1")) ?: 'Asterisk',
        ];
    }
}
