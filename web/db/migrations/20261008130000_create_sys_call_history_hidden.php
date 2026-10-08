<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A user can remove calls from their own call history (#10). The CDR is never
 * touched: reports, call journeys and recordings stay as they are. Hidden
 * single calls are kept here by call key (linkedid, or uniqueid), "clear all"
 * stores a time on the user instead of one row per call.
 */
final class CreateSysCallHistoryHidden extends AbstractMigration
{
    public function change(): void
    {
        $this->table('sys_call_history_hidden', ['id' => false, 'primary_key' => ['user_id', 'call_key']])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('call_key', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('hidden_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('user_id', 'sys_users', 'id', ['delete' => 'CASCADE'])
            ->create();

        $this->table('sys_users')
            ->addColumn('call_history_cleared_at', 'datetime', ['null' => true, 'default' => null])
            ->update();
    }
}
