<?php

class FeatureCodeStatusRepository extends BaseRepository
{
    protected static string $table = 'sys_users';

    public static function activeSipUsersStatus(): array
    {
        return static::db()->query(
            "SELECT extension, full_name, pickup_group, dnd_enabled, call_forward_number,
                    cf_busy_number, cf_noanswer_number, cf_noanswer_timeout
             FROM sys_users
             WHERE is_active = 1 AND extension_type = 'sip' AND extension IS NOT NULL AND extension != ''
             ORDER BY extension ASC"
        )->fetchAll();
    }
}
