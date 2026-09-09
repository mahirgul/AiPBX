<?php

class SystemUserRepository extends BaseRepository
{
    protected static string $table = 'sys_users';

    /**
     * password_hash ve reset_token bilerek DIŞARIDA bırakılıyor — hiçbiri client
     * tarafında (JS/PHP) kullanılmıyor, oysa uiEditButton() tüm satırı sayfa
     * kaynağına (onclick attribute'una) gömüyor; bcrypt hash'i ve aktif şifre
     * sıfırlama token'ını her sayfa yüklemesinde TÜM kullanıcılar için ifşa
     * etmenin hiçbir gerekçesi yok (2026-08-21 denetiminde bulundu).
     */
    public static function allWithRoleName(): array
    {
        return static::db()->query(
            "SELECT u.id, u.username, u.full_name, u.email, u.extension, u.sip_password, u.role, u.can_listen_recordings, u.can_view_all_cdrs, u.can_view_queue_monitor, u.allowed_phone_mode, u.pickup_group, u.cid_internal, u.cid_external, u.is_active, r.role_name
             FROM sys_users u LEFT JOIN sys_roles r ON u.role = r.role_key
             ORDER BY u.role ASC, u.username ASC"
        )->fetchAll();
    }

    public static function allRolesForDropdown(): array
    {
        return static::db()->query("SELECT role_key, role_name FROM sys_roles ORDER BY is_system DESC, role_name ASC")->fetchAll();
    }

    public static function allRolesFull(): array
    {
        return static::db()->query("SELECT * FROM sys_roles ORDER BY id ASC")->fetchAll();
    }
}
