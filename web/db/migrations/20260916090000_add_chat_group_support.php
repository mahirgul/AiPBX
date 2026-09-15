<?php

use Phinx\Migration\AbstractMigration;

/**
 * AI-PBX Chat - Grup Sohbeti Desteği (chat_conversations, chat_participants, chat_messages genişletmesi).
 */
final class AddChatGroupSupport extends AbstractMigration
{
    public function up(): void
    {
        // 1. chat_conversations tablosuna avatar, açıklama ve soft delete kolonları
        if ($this->hasTable('chat_conversations')) {
            $table = $this->table('chat_conversations');
            if (!$table->hasColumn('avatar_url')) {
                $table->addColumn('avatar_url', 'string', ['limit' => 255, 'null' => true, 'after' => 'title']);
            }
            if (!$table->hasColumn('description')) {
                $table->addColumn('description', 'string', ['limit' => 255, 'null' => true, 'after' => 'avatar_url']);
            }
            if (!$table->hasColumn('is_deleted')) {
                $table->addColumn('is_deleted', 'boolean', ['default' => false, 'null' => false, 'after' => 'updated_at']);
            }
            $table->update();
        }

        // 2. chat_participants tablosuna rol, ekleyen kişi ve ayrılma tarihi
        if ($this->hasTable('chat_participants')) {
            $table = $this->table('chat_participants');
            if (!$table->hasColumn('role')) {
                $table->addColumn('role', 'string', ['limit' => 16, 'default' => 'member', 'null' => false, 'after' => 'extension']);
            }
            if (!$table->hasColumn('added_by')) {
                $table->addColumn('added_by', 'string', ['limit' => 20, 'null' => true, 'after' => 'role']);
            }
            if (!$table->hasColumn('left_at')) {
                $table->addColumn('left_at', 'datetime', ['null' => true, 'after' => 'joined_at']);
            }
            $table->update();
        }

        // 3. chat_messages tablosuna sistem olayları için kolonlar
        if ($this->hasTable('chat_messages')) {
            $table = $this->table('chat_messages');
            if (!$table->hasColumn('system_event')) {
                $table->addColumn('system_event', 'string', ['limit' => 32, 'null' => true, 'after' => 'mime_type']);
            }
            if (!$table->hasColumn('system_meta')) {
                $table->addColumn('system_meta', 'string', ['limit' => 255, 'null' => true, 'after' => 'system_event']);
            }
            $table->update();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('chat_messages')) {
            $table = $this->table('chat_messages');
            if ($table->hasColumn('system_meta')) {
                $table->removeColumn('system_meta');
            }
            if ($table->hasColumn('system_event')) {
                $table->removeColumn('system_event');
            }
            $table->update();
        }

        if ($this->hasTable('chat_participants')) {
            $table = $this->table('chat_participants');
            if ($table->hasColumn('left_at')) {
                $table->removeColumn('left_at');
            }
            if ($table->hasColumn('added_by')) {
                $table->removeColumn('added_by');
            }
            if ($table->hasColumn('role')) {
                $table->removeColumn('role');
            }
            $table->update();
        }

        if ($this->hasTable('chat_conversations')) {
            $table = $this->table('chat_conversations');
            if ($table->hasColumn('is_deleted')) {
                $table->removeColumn('is_deleted');
            }
            if ($table->hasColumn('description')) {
                $table->removeColumn('description');
            }
            if ($table->hasColumn('avatar_url')) {
                $table->removeColumn('avatar_url');
            }
            $table->update();
        }
    }
}
