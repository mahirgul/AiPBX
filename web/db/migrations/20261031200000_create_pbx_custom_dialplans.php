<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Custom Asterisk Dialplan Snippets & Contexts.
 */
final class CreatePbxCustomDialplans extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pbx_custom_dialplans')) {
            $this->table('pbx_custom_dialplans', ['signed' => false])
                ->addColumn('title', 'string', ['limit' => 100, 'null' => false])
                ->addColumn('context_name', 'string', ['limit' => 80, 'null' => false])
                ->addColumn('dialplan_content', 'text', ['null' => false])
                ->addColumn('include_in_internal', 'boolean', ['null' => false, 'default' => 1])
                ->addColumn('description', 'string', ['limit' => 255, 'null' => true, 'default' => null])
                ->addColumn('sort_order', 'integer', ['signed' => false, 'null' => false, 'default' => 10])
                ->addColumn('is_active', 'boolean', ['null' => false, 'default' => 1])
                ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['context_name'], ['unique' => true])
                ->addIndex(['is_active'])
                ->addIndex(['sort_order'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('pbx_custom_dialplans')) {
            $this->table('pbx_custom_dialplans')->drop()->save();
        }
    }
}
