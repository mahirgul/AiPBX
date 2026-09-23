<?php

class ExtensionRepository extends BaseRepository
{
    protected static string $table = 'sys_users';

    public static function allWithExtension(): array
    {
        return static::db()->query(
            "SELECT u.id, u.username, u.full_name, u.email, u.extension, u.sip_password, u.sip_auth_digest,
                    u.extension_type, u.outbound_group, u.cid_internal, u.cid_external, u.is_active, u.role,
                    u.permission_group_id, u.boss_secretary_group_id, u.boss_secretary_role,
                    u.voicemail_enabled, u.voicemail_pin, u.voicemail_email, u.voicemail_attach_audio,
                    u.vm_on_noanswer, u.vm_on_busy, u.vm_on_unavail, u.vm_always,
                    pg.group_name AS permission_group_name,
                    bsg.group_name AS boss_secretary_group_name
             FROM sys_users u
             LEFT JOIN pbx_permission_groups pg ON u.permission_group_id = pg.id
             LEFT JOIN pbx_boss_secretary_groups bsg ON u.boss_secretary_group_id = bsg.id
             WHERE u.extension IS NOT NULL AND u.extension != ''
             ORDER BY u.extension ASC"
        )->fetchAll();
    }

    public static function livePjsipStatuses(): array
    {
        return AsteriskHelper::getPJSIPStatuses();
    }
}
