<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Rooms of video meetings stop recording names and playing join prompts: ConfBridge does both
 * before a person enters the bridge, which kept video away for over 30 seconds. New meetings
 * are saved this way; this covers the ones that exist. The dialplan is regenerated on upgrade.
 */
final class MeetingRoomsWithoutAnnouncements extends AbstractMigration
{
    public function up(): void
    {
        // Not a JOIN: the two tables' room_number columns have different collations.
        $rooms = $this->fetchAll('SELECT DISTINCT room_number FROM pbx_meetings WHERE room_number IS NOT NULL');
        $update = $this->getAdapter()->getConnection()->prepare(
            'UPDATE pbx_conferences SET announce_join_leave = 0, announce_user_count = 0 WHERE room_number = ?'
        );
        foreach ($rooms as $room) {
            $update->execute([$room['room_number']]);
        }
    }

    public function down(): void
    {
    }
}
