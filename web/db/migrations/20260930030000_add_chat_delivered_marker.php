<?php

use Phinx\Migration\AbstractMigration;

/**
 * Chat delivery receipts: the highest message id delivered to at least one of a
 * participant's devices (read state is last_read_message_id). Existing rows start
 * at their read marker.
 */
final class AddChatDeliveredMarker extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('chat_participants')) {
            return;
        }
        $table = $this->table('chat_participants');
        if (!$table->hasColumn('last_delivered_message_id')) {
            $table->addColumn('last_delivered_message_id', 'biginteger', ['null' => false, 'default' => 0])->update();
            $this->execute('UPDATE chat_participants SET last_delivered_message_id = last_read_message_id');
        }
    }

    public function down(): void
    {
        if ($this->hasTable('chat_participants') && $this->table('chat_participants')->hasColumn('last_delivered_message_id')) {
            $this->table('chat_participants')->removeColumn('last_delivered_message_id')->update();
        }
    }
}
