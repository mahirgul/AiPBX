<?php

/**
 * Database access for the Feature codes page.
 * Tables: pbx_feature_codes, sys_roles.
 * Used by FeatureCodeController.
 */
class FeatureCodeRepository extends BaseRepository
{
    protected static string $table = 'pbx_feature_codes';

    public static function allOrderedById(): array
    {
        return static::findAll('id ASC');
    }

    public static function allRoles(): array
    {
        return localizeRoles(static::db()->query("SELECT role_key, role_name FROM sys_roles ORDER BY role_name ASC")->fetchAll());
    }
}
