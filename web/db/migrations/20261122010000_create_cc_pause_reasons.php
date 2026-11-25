<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCcPauseReasons extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('cc_pause_reasons')) {
            $table = $this->table('cc_pause_reasons', ['id' => true]);
            $table
                ->addColumn('code', 'string', ['limit' => 16, 'null' => false])
                ->addColumn('icon', 'string', ['limit' => 16, 'null' => true, 'default' => '☕'])
                ->addColumn('name', 'string', ['limit' => 80, 'null' => false])
                ->addColumn('max_minutes', 'integer', ['null' => true, 'default' => null])
                ->addColumn('warn_on_exceed', 'boolean', ['null' => false, 'default' => true])
                ->addColumn('auto_unpause', 'boolean', ['null' => false, 'default' => false])
                ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
                ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
                ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['code'], ['unique' => true, 'name' => 'uniq_cc_pause_reasons_code'])
                ->create();
        }

        $count = (int) $this->fetchRow('SELECT COUNT(*) AS cnt FROM cc_pause_reasons')['cnt'];
        if ($count === 0) {
            $this->table('cc_pause_reasons')->insert([
                ['code' => '1', 'name' => 'Break', 'icon' => '☕', 'max_minutes' => 15, 'is_active' => 1, 'sort_order' => 10],
                ['code' => '2', 'name' => 'Lunch', 'icon' => '🍲', 'max_minutes' => 60, 'is_active' => 1, 'sort_order' => 20],
                ['code' => '3', 'name' => 'Meeting', 'icon' => '📋', 'max_minutes' => 30, 'is_active' => 1, 'sort_order' => 30],
                ['code' => '4', 'name' => 'Training', 'icon' => '🎓', 'max_minutes' => 60, 'is_active' => 1, 'sort_order' => 40],
            ])->saveData();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('cc_pause_reasons')) {
            $this->table('cc_pause_reasons')->drop()->save();
        }
    }
}
