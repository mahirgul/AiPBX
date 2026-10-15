<?php

/**
 * Database access for the Fax mail settings.
 * Tables: sys_settings.
 * Used by FaxMailSettingsController.
 */
class FaxMailSettingsRepository extends BaseRepository
{
    protected static string $table = 'sys_settings';

    public static function allSettings(): array
    {
        return static::db()->query("SELECT setting_key, setting_value FROM sys_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
