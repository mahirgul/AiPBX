<?php

class MsTeamsRepository extends BaseRepository
{
    protected static string $table = 'teams_user_mappings';

    /**
     * Varsayılan MS Teams ayarlarını ve veritabanındaki mevcut değerleri döner.
     */
    public static function currentSettings(): array
    {
        $defaults = [
            'teams_enabled'              => '0',
            'teams_domain'               => '',
            'teams_sip_port'             => '5061',
            'teams_tls_cert_path'        => '/etc/asterisk/keys/teams_cert.pem',
            'teams_tls_key_path'         => '/etc/asterisk/keys/teams_key.pem',
            'teams_sbc_name'             => '',
            'teams_webhook_enabled'      => '0',
            'teams_webhook_url'          => '',
            'teams_notify_missed_calls'  => '1',
            'teams_notify_voicemail'     => '1',
            'teams_notify_queue_alerts'  => '1',
            'teams_notify_cdr_summary'   => '0',
            'teams_notify_fax'           => '1',
        ];

        $placeholders = implode(',', array_fill(0, count($defaults), '?'));
        $stmt = static::db()->prepare("SELECT setting_key, setting_value FROM sys_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute(array_keys($defaults));
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        return array_merge($defaults, $rows ?: []);
    }

    /**
     * Ayarları sys_settings tablosuna kaydeder.
     */
    public static function saveSettings(array $settings): bool
    {
        $db = static::db();
        $allowedKeys = [
            'teams_enabled',
            'teams_domain',
            'teams_sip_port',
            'teams_tls_cert_path',
            'teams_tls_key_path',
            'teams_sbc_name',
            'teams_webhook_enabled',
            'teams_webhook_url',
            'teams_notify_missed_calls',
            'teams_notify_voicemail',
            'teams_notify_queue_alerts',
            'teams_notify_cdr_summary',
            'teams_notify_fax',
        ];

        $stmt = $db->prepare("
            INSERT INTO sys_settings (setting_key, setting_value) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");

        foreach ($settings as $key => $value) {
            if (in_array($key, $allowedKeys, true)) {
                $stmt->execute([$key, (string)$value]);
            }
        }

        return true;
    }

    /**
     * Tüm kullanıcı & dahili eşleştirmelerini kullanıcı bilgileriyle listeler.
     */
    public static function allUserMappings(): array
    {
        $stmt = static::db()->query("
            SELECT m.*, u.full_name, u.email as user_email
            FROM teams_user_mappings m
            LEFT JOIN sys_users u ON u.extension = m.extension
            ORDER BY m.extension ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Tekil eşleştirme kaydı döner.
     */
    public static function findUserMapping(int $id): ?array
    {
        $stmt = static::db()->prepare("SELECT * FROM teams_user_mappings WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Yeni eşleştirme ekler veya mevcut olanı günceller.
     */
    public static function saveUserMapping(array $data): array
    {
        $db = static::db();
        $id = !empty($data['id']) ? (int)$data['id'] : 0;
        $extension = trim($data['extension'] ?? '');
        $teams_upn = trim($data['teams_upn'] ?? '');
        $phone_number = trim($data['phone_number'] ?? '');
        $direct_routing_enabled = !empty($data['direct_routing_enabled']) ? 1 : 0;
        $notes = trim($data['notes'] ?? '');

        if ($extension === '') {
            return ['success' => false, 'error' => t('ms_teams.msg_err_ext_required')];
        }
        if ($teams_upn === '' || !filter_var($teams_upn, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => t('ms_teams.msg_err_invalid_upn')];
        }

        // Benzersizlik kontrolleri
        if ($id > 0) {
            $stmt = $db->prepare("SELECT id FROM teams_user_mappings WHERE extension = ? AND id != ?");
            $stmt->execute([$extension, $id]);
            if ($stmt->fetch()) {
                return ['success' => false, 'error' => t('ms_teams.msg_err_ext_exists')];
            }
            $stmt = $db->prepare("SELECT id FROM teams_user_mappings WHERE teams_upn = ? AND id != ?");
            $stmt->execute([$teams_upn, $id]);
            if ($stmt->fetch()) {
                return ['success' => false, 'error' => t('ms_teams.msg_err_upn_exists')];
            }

            $updateStmt = $db->prepare("
                UPDATE teams_user_mappings 
                SET extension = ?, teams_upn = ?, phone_number = ?, direct_routing_enabled = ?, notes = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $updateStmt->execute([$extension, $teams_upn, $phone_number, $direct_routing_enabled, $notes, $id]);
            return ['success' => true, 'message' => t('ms_teams.msg_mapping_saved'), 'id' => $id];
        } else {
            $stmt = $db->prepare("SELECT id FROM teams_user_mappings WHERE extension = ?");
            $stmt->execute([$extension]);
            if ($stmt->fetch()) {
                return ['success' => false, 'error' => t('ms_teams.msg_err_ext_exists')];
            }
            $stmt = $db->prepare("SELECT id FROM teams_user_mappings WHERE teams_upn = ?");
            $stmt->execute([$teams_upn]);
            if ($stmt->fetch()) {
                return ['success' => false, 'error' => t('ms_teams.msg_err_upn_exists')];
            }

            $insertStmt = $db->prepare("
                INSERT INTO teams_user_mappings (extension, teams_upn, phone_number, direct_routing_enabled, notes, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $insertStmt->execute([$extension, $teams_upn, $phone_number, $direct_routing_enabled, $notes]);
            $newId = (int)$db->lastInsertId();
            return ['success' => true, 'message' => t('ms_teams.msg_mapping_saved'), 'id' => $newId];
        }
    }

    /**
     * Eşleştirmeyi siler.
     */
    public static function deleteUserMapping(int $id): bool
    {
        $stmt = static::db()->prepare("DELETE FROM teams_user_mappings WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Sistemde tanımlı tüm aktif dahili aboneleri döner.
     */
    public static function availableExtensions(): array
    {
        $stmt = static::db()->query("
            SELECT extension, full_name, email 
            FROM sys_users 
            WHERE extension IS NOT NULL AND extension != '' AND is_active = 1 
            ORDER BY CAST(extension AS UNSIGNED) ASC, extension ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
