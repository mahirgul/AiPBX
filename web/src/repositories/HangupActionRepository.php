<?php

class HangupActionRepository extends BaseRepository
{
    protected static string $table = 'pbx_hangup_actions';

    public static function allWithAnnouncementTitle(): array
    {
        return static::db()->query(
            "SELECT ha.*, anc.title AS anc_title
             FROM pbx_hangup_actions ha
             LEFT JOIN pbx_announcements anc ON ha.announcement_id = anc.id
             ORDER BY ha.id ASC"
        )->fetchAll();
    }

    public static function allAnnouncementsForDropdown(): array
    {
        return static::db()->query("SELECT id, title FROM pbx_announcements ORDER BY title ASC")->fetchAll();
    }
}
