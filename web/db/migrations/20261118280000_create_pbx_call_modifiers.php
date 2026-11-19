<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePbxCallModifiers extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('pbx_call_modifiers')) {
            return;
        }
        $this->table('pbx_call_modifiers')
            ->addColumn('title', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('mod_type', 'string', ['limit' => 24, 'null' => false])
            ->addColumn('mod_value', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('dest_type', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('dest_id', 'string', ['limit' => 120, 'null' => false, 'default' => ''])
            ->addColumn('is_active', 'boolean', ['default' => true, 'null' => false])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('pbx_call_modifiers')) {
            $this->table('pbx_call_modifiers')->drop()->save();
        }
    }
}
