<?php

class PushSettingsRepository extends BaseRepository
{
    protected static string $table = 'sys_settings';

    /**
     * Fetch all push settings with defaults
     */
    public static function currentSettings(): array
    {
        $keys = [
            'push_enabled' => '0',
            'push_provider' => 'none',
            'push_fcm_project_id' => '',
            'push_fcm_service_account' => '',
            'push_fcm_app_id' => '',
            'push_fcm_api_key' => '',
            'push_fcm_sender_id' => '',
            'push_wait_seconds' => '8',
        ];

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = static::db()->prepare("SELECT setting_key, setting_value FROM sys_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute(array_keys($keys));
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        return array_merge($keys, $rows ?: []);
    }

    /**
     * Get list of active mobile devices for testing
     */
    public static function activeMobileDevices(): array
    {
        $stmt = static::db()->query("
            SELECT d.id, d.extension, d.device_name, d.platform, d.app_version, d.fcm_token, d.updated_at, u.full_name
            FROM sys_mobile_devices d
            LEFT JOIN sys_users u ON u.id = d.user_id
            WHERE d.is_active = 1
            ORDER BY d.updated_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
