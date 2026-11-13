<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Conference calls started from a group chat: one open row per conversation at a time. */
final class CreateChatCalls extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('chat_calls')) {
            return;
        }
        $this->table('chat_calls', ['collation' => 'utf8mb4_general_ci', 'encoding' => 'utf8mb4'])
            ->addColumn('conversation_id', 'integer', ['null' => false])
            ->addColumn('room', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('video', 'integer', ['limit' => 1, 'default' => 0, 'null' => false])
            ->addColumn('started_by', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('started_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('ended_at', 'datetime', ['null' => true])
            ->addColumn('peak_parties', 'integer', ['default' => 0, 'null' => false])
            ->addIndex(['room'], ['unique' => true])
            ->addIndex(['conversation_id', 'ended_at'])
            ->create();
    }
}
