<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateVideoMeetingsTables extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('pbx_meetings')) {
            $table = $this->table('pbx_meetings', ['id' => true, 'primary_key' => ['id']]);
            $table->addColumn('meeting_token', 'string', ['limit' => 64])
                  ->addColumn('title', 'string', ['limit' => 191])
                  ->addColumn('description', 'text', ['null' => true])
                  ->addColumn('host_user_id', 'integer', ['null' => true])
                  ->addColumn('room_number', 'string', ['limit' => 32])
                  ->addColumn('pin', 'string', ['limit' => 32, 'null' => true])
                  ->addColumn('scheduled_start', 'datetime')
                  ->addColumn('scheduled_end', 'datetime')
                  ->addColumn('video_mode', 'string', ['limit' => 20, 'default' => 'sfu'])
                  ->addColumn('max_participants', 'integer', ['default' => 25])
                  ->addColumn('allow_guests', 'boolean', ['default' => true])
                  ->addColumn('status', 'string', ['limit' => 20, 'default' => 'scheduled'])
                  ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                  ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
                  ->addIndex(['meeting_token'], ['unique' => true])
                  ->addIndex(['scheduled_start'])
                  ->addIndex(['host_user_id'])
                  ->create();
        }

        if (!$this->hasTable('pbx_meeting_invites')) {
            $table = $this->table('pbx_meeting_invites', ['id' => true, 'primary_key' => ['id']]);
            $table->addColumn('meeting_id', 'integer')
                  ->addColumn('email', 'string', ['limit' => 191])
                  ->addColumn('name', 'string', ['limit' => 191, 'null' => true])
                  ->addColumn('guest_token', 'string', ['limit' => 64])
                  ->addColumn('status', 'string', ['limit' => 20, 'default' => 'invited'])
                  ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                  ->addIndex(['guest_token'], ['unique' => true])
                  ->addIndex(['meeting_id'])
                  ->create();
        }

        // Add video parameters to legacy pbx_conferences if not present
        if ($this->hasTable('pbx_conferences')) {
            $confTable = $this->table('pbx_conferences');
            if (!$confTable->hasColumn('video_mode')) {
                $confTable->addColumn('video_mode', 'string', ['limit' => 20, 'default' => 'none', 'after' => 'music_on_hold'])
                          ->addColumn('max_video_streams', 'integer', ['default' => 10, 'after' => 'video_mode'])
                          ->update();
            }
        }
    }
}
