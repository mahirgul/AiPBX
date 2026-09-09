<?php

class FaxSettingsRepository extends BaseRepository
{
    protected static string $table = 'sys_did_mappings';

    public static function allMappingsWithUser(): array
    {
        return static::db()->query(
            "SELECT m.id, m.department_name, m.notification_email, m.did_extension, m.assigned_user_id, m.is_active, u.extension AS fax_user_extension, u.full_name AS fax_user_name
             FROM sys_did_mappings m LEFT JOIN sys_users u ON u.id = m.assigned_user_id
             ORDER BY m.department_name ASC"
        )->fetchAll();
    }

    /**
     * is_active=1 filtresi eklendi (2026-08-31, FaxSendController'ın admin için
     * "hangi birim adına gönderiliyor" seçici dropdown'ında da kullanılmaya
     * başlamasıyla) — pasif bir faks birimi adına yeni gönderim yapılabilir
     * olmamalı. DID-birim eşleme sayfası (bu metodun asıl tüketicisi) için de
     * davranış değişmiyor, bugüne kadar tüm kayıtlar zaten aktifti.
     */
    public static function faxUsersForDropdown(): array
    {
        return static::db()->query(
            "SELECT id, extension, full_name FROM sys_users WHERE extension_type = 'fax' AND extension IS NOT NULL AND extension != '' AND is_active = 1 ORDER BY extension ASC"
        )->fetchAll();
    }
}
