<?php

class AsteriskSettingsRepository extends BaseRepository
{
    protected static string $table = 'sys_settings';

    public static function currentSettings(): array
    {
        return static::db()->query("SELECT setting_key, setting_value FROM sys_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function activeRingSoundOptions(): array
    {
        return static::db()->query("SELECT audio_file, title FROM pbx_announcements WHERE is_active = 1 ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
}
