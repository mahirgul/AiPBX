<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Deleting chat messages (roadmap item 2): the sender can delete their own
 * message. The row stays, marked deleted, with its text and attachment
 * cleared (chat/message_delete.go). The admin setting
 * sys_settings.chat_delete_window_minutes limits how long after sending a
 * message can be deleted; it is missing (= no limit) until an admin saves it.
 */
final class AddDeletedToChatMessages extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('chat_messages')) {
            return;
        }
        $table = $this->table('chat_messages');
        if (!$table->hasColumn('is_deleted')) {
            $table->addColumn('is_deleted', 'boolean', ['default' => false, 'null' => false, 'after' => 'system_meta']);
        }
        if (!$table->hasColumn('deleted_at')) {
            $table->addColumn('deleted_at', 'datetime', ['null' => true, 'after' => 'is_deleted']);
        }
        $table->update();
    }

    public function down(): void
    {
        if (!$this->hasTable('chat_messages')) {
            return;
        }
        $table = $this->table('chat_messages');
        if ($table->hasColumn('deleted_at')) {
            $table->removeColumn('deleted_at');
        }
        if ($table->hasColumn('is_deleted')) {
            $table->removeColumn('is_deleted');
        }
        $table->update();
    }
}
