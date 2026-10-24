<?php

use Phinx\Migration\AbstractMigration;

/**
 * Wave 2.4 & 2.5: Per-trunk carrier normalization, 100rel, and PBX tuning fields.
 */
final class AddWave2PbxTuningFields extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('pbx_trunks');

        if (!$table->hasColumn('carrier_strip_prefix')) {
            $table->addColumn('carrier_strip_prefix', 'string', ['limit' => 30, 'null' => true, 'default' => null]);
        }
        if (!$table->hasColumn('carrier_add_prefix')) {
            $table->addColumn('carrier_add_prefix', 'string', ['limit' => 30, 'null' => true, 'default' => null]);
        }
        if (!$table->hasColumn('prack_100rel')) {
            $table->addColumn('prack_100rel', 'string', ['limit' => 20, 'null' => false, 'default' => 'yes']);
        }

        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('pbx_trunks');
        foreach (['carrier_strip_prefix', 'carrier_add_prefix', 'prack_100rel'] as $col) {
            if ($table->hasColumn($col)) {
                $table->removeColumn($col);
            }
        }
        $table->update();
    }
}
