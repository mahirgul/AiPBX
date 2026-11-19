<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Chat sessions and messages for the PBX Assistant (/assistant). */
final class CreateAssistantChats extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('assistant_chats')) {
            $this->table('assistant_chats', ['collation' => 'utf8mb4_general_ci', 'encoding' => 'utf8mb4'])
                ->addColumn('user_id', 'integer', ['null' => false])
                ->addColumn('title', 'string', ['limit' => 200, 'null' => false, 'default' => ''])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
                ->addIndex(['user_id', 'updated_at'])
                ->addIndex(['updated_at'])
                ->create();
        }

        if (!$this->hasTable('assistant_messages')) {
            $this->table('assistant_messages', ['collation' => 'utf8mb4_general_ci', 'encoding' => 'utf8mb4'])
                ->addColumn('chat_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('role', 'string', ['limit' => 20, 'null' => false])
                ->addColumn('text', 'text', ['null' => false])
                ->addColumn('kind', 'string', ['limit' => 30, 'null' => false, 'default' => 'text'])
                ->addColumn('plan_id', 'integer', ['null' => true])
                ->addColumn('payload_json', 'text', ['null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
                ->addIndex(['chat_id', 'id'])
                ->addForeignKey('chat_id', 'assistant_chats', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('assistant_messages')) {
            $this->table('assistant_messages')->drop()->save();
        }
        if ($this->hasTable('assistant_chats')) {
            $this->table('assistant_chats')->drop()->save();
        }
    }
}
