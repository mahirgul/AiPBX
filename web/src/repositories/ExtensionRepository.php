<?php

class ExtensionRepository extends BaseRepository
{
    protected static string $table = 'sys_users';

    public static function allWithExtension(): array
    {
        return static::db()->query(
            "SELECT id, username, full_name, extension, sip_password, sip_auth_digest, extension_type, outbound_group, cid_internal, cid_external, is_active, role
             FROM sys_users WHERE extension IS NOT NULL AND extension != '' ORDER BY extension ASC"
        )->fetchAll();
    }

    public static function livePjsipStatuses(): array
    {
        return AsteriskHelper::getPJSIPStatuses();
    }
}
