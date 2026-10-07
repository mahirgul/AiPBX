<?php

class SystemUserRepository extends BaseRepository
{
    protected static string $table = 'sys_users';

    /**
     * password_hash and reset_token are left OUT on purpose — neither is used
     * on the client side (JS/PHP), yet uiEditButton() embeds the whole row in
     * the page source (the onclick attribute); there is no reason to expose
     * the bcrypt hash and the active password reset token for ALL users on
     * every page load (found in the 2026-08-21 audit).
     */
    public static function allWithRoleName(): array
    {
        $rows = static::db()->query(
            "SELECT u.id, u.username, u.full_name, u.email, u.extension, u.sip_password, u.role, u.can_listen_recordings, u.can_view_all_cdrs, u.can_view_queue_monitor, u.allowed_phone_mode, u.pickup_group, u.cid_internal, u.cid_external, u.is_active, u.two_factor_enabled, r.role_name
             FROM sys_users u LEFT JOIN sys_roles r ON u.role = r.role_key
             ORDER BY u.role ASC, u.username ASC"
        )->fetchAll();
        return localizeRoles($rows);
    }

    public static function allRolesForDropdown(): array
    {
        return localizeRoles(static::db()->query("SELECT role_key, role_name FROM sys_roles ORDER BY is_system DESC, role_name ASC")->fetchAll());
    }

    public static function allRolesFull(): array
    {
        return localizeRoles(static::db()->query("SELECT * FROM sys_roles ORDER BY id ASC")->fetchAll());
    }
}
