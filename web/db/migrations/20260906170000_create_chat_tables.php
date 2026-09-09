<?php

use Phinx\Migration\AbstractMigration;

/**
 * AI-PBX Chat, Mesajlasma ve Medya Paylasim Tablolari.
 */
final class CreateChatTables extends AbstractMigration
{
    public function up(): void
    {
        // 1. Konusmalar (Sohbet Odalari)
        if (!$this->hasTable('chat_conversations')) {
            $table = $this->table('chat_conversations', ['collation' => 'utf8mb4_general_ci', 'encoding' => 'utf8mb4']);
            $table->addColumn('type', 'string', ['limit' => 16, 'default' => 'direct', 'null' => false])
                  ->addColumn('direct_key', 'string', ['limit' => 50, 'null' => true])
                  ->addColumn('title', 'string', ['limit' => 100, 'null' => true])
                  ->addColumn('created_by', 'string', ['limit' => 20, 'null' => false])
                  ->addColumn('last_message_text', 'string', ['limit' => 255, 'null' => true])
                  ->addColumn('last_message_at', 'datetime', ['null' => true])
                  ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                  ->addColumn('updated_at', 'datetime', ['null' => true, 'default' => 'CURRENT_TIMESTAMP'])
                  ->addIndex(['direct_key'], ['unique' => true])
                  ->addIndex(['last_message_at'])
                  ->create();
        }

        // 2. Katilimcilar
        if (!$this->hasTable('chat_participants')) {
            $table = $this->table('chat_participants', ['collation' => 'utf8mb4_general_ci', 'encoding' => 'utf8mb4']);
            $table->addColumn('conversation_id', 'integer', ['null' => false])
                  ->addColumn('extension', 'string', ['limit' => 20, 'null' => false])
                  ->addColumn('last_read_message_id', 'biginteger', ['default' => 0, 'null' => false])
                  ->addColumn('is_muted', 'integer', ['limit' => 1, 'default' => 0, 'null' => false])
                  ->addColumn('joined_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                  ->addIndex(['conversation_id', 'extension'], ['unique' => true])
                  ->addIndex(['extension'])
                  ->create();
        }

        // 3. Mesajlar ve Ekler
        if (!$this->hasTable('chat_messages')) {
            $table = $this->table('chat_messages', ['collation' => 'utf8mb4_general_ci', 'encoding' => 'utf8mb4']);
            $table->addColumn('conversation_id', 'integer', ['null' => false])
                  ->addColumn('sender_ext', 'string', ['limit' => 20, 'null' => false])
                  ->addColumn('msg_type', 'string', ['limit' => 16, 'default' => 'text', 'null' => false])
                  ->addColumn('message', 'text', ['null' => true])
                  ->addColumn('attachment_url', 'string', ['limit' => 255, 'null' => true])
                  ->addColumn('file_name', 'string', ['limit' => 255, 'null' => true])
                  ->addColumn('file_size', 'integer', ['default' => 0, 'null' => false])
                  ->addColumn('mime_type', 'string', ['limit' => 100, 'null' => true])
                  ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                  ->addIndex(['conversation_id', 'id'])
                  ->addIndex(['sender_ext'])
                  ->addIndex(['created_at'])
                  ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('chat_messages')) {
            $this->table('chat_messages')->drop()->save();
        }
        if ($this->hasTable('chat_participants')) {
            $this->table('chat_participants')->drop()->save();
        }
        if ($this->hasTable('chat_conversations')) {
            $this->table('chat_conversations')->drop()->save();
        }
    }
}
