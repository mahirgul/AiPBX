<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateAiReminders extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('ai_reminders')) {
            return;
        }
        $this->table('ai_reminders', ['collation' => 'utf8mb4_general_ci', 'encoding' => 'utf8mb4'])
            ->addColumn('user_id', 'integer', ['null' => true])
            ->addColumn('extension', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('kind', 'string', ['limit' => 20, 'default' => 'reminder', 'null' => false])
            ->addColumn('message', 'string', ['limit' => 500, 'null' => false])
            ->addColumn('remind_at', 'datetime', ['null' => false])
            ->addColumn('recurrence', 'string', ['limit' => 20, 'default' => 'none', 'null' => false])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending', 'null' => false])
            ->addColumn('attempts', 'integer', ['default' => 0, 'null' => false])
            ->addColumn('max_attempts', 'integer', ['default' => 3, 'null' => false])
            ->addColumn('last_attempt_at', 'datetime', ['null' => true])
            ->addColumn('last_error', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_by', 'integer', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['status', 'remind_at'])
            ->addIndex(['extension', 'remind_at'])
            ->create();
    }
}
