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

    public static function getSystemMetrics(): array
    {
        exec('pgrep asterisk', $ast_pids);
        return [
            'is_asterisk_running' => !empty($ast_pids),
            'mail_relay_host' => trim((string)shell_exec('postconf -h relayhost 2>/dev/null')) ?: null,
            'ram_info' => trim((string)shell_exec("free -m | awk '/Mem:/ {print $3\" / \"$2\" MB (\"int($3/$2*100)\"%)\"}'")) ?: 'N/A',
            'disk_info' => trim((string)shell_exec("df -h / | awk 'NR==2 {print $3\" / \"$2\" (\"$5\")\"}'")) ?: 'N/A',
            'uptime_info' => trim((string)shell_exec('uptime -p')) ?: 'N/A',
            'asterisk_version' => trim((string)shell_exec("asterisk -rx 'core show version' | head -1")) ?: 'Asterisk',
        ];
    }
}
