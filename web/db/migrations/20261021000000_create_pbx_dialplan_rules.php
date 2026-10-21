<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Creates `pbx_dialplan_rules` table for Ingress Call Normalization Rules
 * evaluated per client origin (mobile, desktop hardphone, web softphone).
 */
final class CreatePbxDialplanRules extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pbx_dialplan_rules')) {
            $this->table('pbx_dialplan_rules')
                ->addColumn('client_type', 'string', ['limit' => 20, 'default' => 'all'])
                ->addColumn('rule_name', 'string', ['limit' => 128])
                ->addColumn('match_pattern', 'string', ['limit' => 64])
                ->addColumn('strip_front', 'integer', ['default' => 0])
                ->addColumn('prepend', 'string', ['limit' => 32, 'default' => ''])
                ->addColumn('strip_back', 'integer', ['default' => 0])
                ->addColumn('append', 'string', ['limit' => 32, 'default' => ''])
                ->addColumn('sort_order', 'integer', ['default' => 0])
                ->addColumn('is_active', 'boolean', ['default' => 1])
                ->addColumn('description', 'text', ['null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['client_type'])
                ->addIndex(['is_active'])
                ->addIndex(['sort_order'])
                ->create();

            // No rules on a new table: the administrator picks the country and
            // loads its recommended rules on Trunks -> Call Normalization
            // (1.34.4-1.50.0 seeded Turkish rules here on every installation).
        }
    }

    public function down(): void
    {
        if ($this->hasTable('pbx_dialplan_rules')) {
            $this->table('pbx_dialplan_rules')->drop()->save();
        }
    }
}
