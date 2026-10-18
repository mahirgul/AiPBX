<?php

/**
 * AI → Assistant: the audit trail and rollback record for every change the
 * assistant applied. One row per accepted "apply": what was asked for, what
 * was changed, when, by whom, and — for actions that captured the previous
 * value — the undo payload.
 *
 * The assistant only writes through the same services the portal's own pages
 * use (see AssistantPlanService), so this table does not sit in the write
 * path of a PBX change: it is the record the Dialplan editor page shows and
 * the thing rollback() reads first.
 */

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

final class CreateAiChanges extends AbstractMigration
{
    public function up(): void
    {
        $this->table('pbx_ai_changes', ['collation' => 'utf8mb4_general_ci', 'encoding' => 'utf8mb4'])
            ->addColumn('ts', 'datetime', ['null' => false])
            ->addColumn('user_id', 'integer', ['limit' => 11, 'default' => 0, 'null' => false])
            ->addColumn('request', 'string', ['limit' => 300, 'default' => '', 'null' => false])
            ->addColumn('action', 'string', ['limit' => 64, 'default' => '', 'null' => false])
            ->addColumn('arguments', 'text', ['limit' => MysqlAdapter::TEXT_REGULAR, 'null' => false])
            ->addColumn('summary', 'string', ['limit' => 300, 'default' => '', 'null' => false])
            ->addColumn('source', 'string', ['limit' => 16, 'default' => 'rules', 'null' => false])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'applied', 'null' => false])
            ->addColumn('applied_at', 'datetime', ['null' => true])
            ->addColumn('before_json', 'text', ['limit' => MysqlAdapter::TEXT_REGULAR, 'null' => true])
            ->addColumn('rolled_back_at', 'datetime', ['null' => true])
            ->addIndex(['ts'])
            ->addIndex(['action'])
            ->create();
    }

    public function down(): void
    {
        $this->table('pbx_ai_changes')->drop()->save();
    }
}
