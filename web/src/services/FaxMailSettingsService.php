<?php
/**
 * Fax Mail Settings (Faks Ayarları) Service
 */
require_once __DIR__ . '/../asterisk_sync.php';

class FaxMailSettingsService {
    public static function saveSettings(array $data): array
    {
        if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik doğrulama kodu!'];
        }

        $fax_settings = [
            'fax_email_from_address' => trim($data['fax_email_from_address'] ?? 'fax@example.com'),
            'fax_email_from_name'    => trim($data['fax_email_from_name'] ?? 'AI PBX Faks Sistemi'),
            'fax_email_rx_enabled'   => trim($data['fax_email_rx_enabled'] ?? 'yes'),
            'fax_email_rx_attach_pdf' => trim($data['fax_email_rx_attach_pdf'] ?? 'yes'),
            'fax_email_tx_enabled'   => trim($data['fax_email_tx_enabled'] ?? 'yes'),
            'fax_retention_days'     => trim($data['fax_retention_days'] ?? '60'),
            'fax_header_info'        => trim($data['fax_header_info'] ?? 'AI PBX Fax Server'),
            'fax_local_station_id'   => trim($data['fax_local_station_id'] ?? 'FAX37'),
            'fax_max_retries'        => trim($data['fax_max_retries'] ?? '3'),
            'fax_retry_time'         => trim($data['fax_retry_time'] ?? '60'),
            'fax_wait_time'          => trim($data['fax_wait_time'] ?? '30'),
        ];

        $db = getDB();
        $stmt = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($fax_settings as $k => $v) {
            $stmt->execute([$k, $v]);
        }

        // Diğer alanların çoğu (mail ayarları, deneme/bekleme süreleri) gerçekten
        // Asterisk config'ini etkilemiyor — fax_send.php/script'ler sys_settings'i
        // her seferinde canlı okuyor. AMA fax_header_info + fax_local_station_id
        // istisna: SyncDialplan.php bunları statik extensions_general.conf'a gömüyor
        // (FAXOPT(headerinfo)/FAXOPT(localstationid)) — bu iki alan markPendingSync()
        // ÇAĞIRMADIĞI için kaydedilen değer hiçbir zaman Asterisk'e yansımıyordu,
        // "Uygula" listesinde de hiç görünmüyordu (2026-08-31 denetiminde bulundu,
        // kullanıcının TSID/başlık değişikliği isteğiyle fark edildi).
        markPendingSync('general_dialplan', 'system_setting', 'fax', 'Faks Başlığı / TSID Ayarları', 'update', $_SESSION['user_id'] ?? null);
        writeAuditLog(null, 'fax_mail_settings', 'general', 'Faks Mail Ayarları güncellendi', 'update', $_SESSION['user_id'] ?? null);

        return ['success' => true, 'message' => 'Faks ayarları başarıyla kaydedildi!'];
    }
}
