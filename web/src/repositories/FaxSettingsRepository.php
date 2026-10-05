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
     * The is_active=1 filter was added (2026-08-31, when FaxSendController
     * started using it in the admin's "send on behalf of which unit" dropdown
     * too) — sending on behalf of an inactive fax unit must not be possible.
     * Nothing changes for the DID-unit mapping page (the main consumer of this
     * method): all records were active anyway so far.
     */
    public static function faxUsersForDropdown(): array
    {
        return static::db()->query(
            "SELECT id, extension, full_name FROM sys_users WHERE extension_type = 'fax' AND extension IS NOT NULL AND extension != '' AND is_active = 1 ORDER BY extension ASC"
        )->fetchAll();
    }
}
