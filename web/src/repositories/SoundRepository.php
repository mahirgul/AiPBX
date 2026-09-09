<?php

class SoundRepository extends BaseRepository
{
    protected static string $table = 'pbx_announcements';

    public static function allAnnouncementsOrdered(): array
    {
        return static::findAll('id DESC');
    }

    public static function allMohClasses(): array
    {
        $classes = static::db()->query("SELECT * FROM pbx_moh_classes ORDER BY id ASC")->fetchAll();
        if (empty($classes)) {
            $classes = [
                ['id' => 1, 'name' => 'default', 'directory' => MOH_BASE_DIR, 'mode' => 'files', 'sort' => 'alpha'],
                ['id' => 2, 'name' => 'custom', 'directory' => MOH_BASE_DIR . '/custom', 'mode' => 'files', 'sort' => 'alpha'],
            ];
        }
        return $classes;
    }
}
