<?php

class QueueRepository extends BaseRepository
{
    protected static string $table = 'pbx_queues';

    public static function allOrderedById(): array
    {
        return static::findAll('id ASC');
    }

    public static function extensionAgents(): array
    {
        return static::db()->query(
            "SELECT id, username, full_name, extension, role FROM sys_users WHERE extension IS NOT NULL AND extension != '' ORDER BY role ASC, extension ASC"
        )->fetchAll();
    }

    public static function activeMohClasses(): array
    {
        $classes = static::db()->query("SELECT name FROM pbx_moh_classes WHERE is_active = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
        return !empty($classes) ? $classes : ['default', 'custom'];
    }

    public static function supervisorName(string $extension): string
    {
        return DBHelper::fetchColumn("SELECT full_name FROM sys_users WHERE extension = ?", [$extension]) ?: $extension;
    }
}
