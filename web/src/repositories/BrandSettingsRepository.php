<?php

class BrandSettingsRepository extends BaseRepository
{
    protected static string $table = 'sys_settings';

    public static function currentSettings(): array
    {
        return static::db()->query("SELECT setting_key, setting_value FROM sys_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
