<?php

use Phinx\Migration\AbstractMigration;

/**
 * Outbound caller ID normalization per trunk: keep the last N digits of the
 * caller number, then prepend a prefix (e.g. transit call from extension 7840
 * on another trunk → keep 4, prepend 90370418 → 903704187840).
 */
final class AddOutboundCidNormalizationToTrunks extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('pbx_trunks');
        if (!$table->hasColumn('cid_keep_last')) {
            $table->addColumn('cid_keep_last', 'integer', ['signed' => false, 'null' => false, 'default' => 0]);
        }
        if (!$table->hasColumn('cid_prepend')) {
            $table->addColumn('cid_prepend', 'string', ['limit' => 30, 'null' => true, 'default' => null]);
        }
        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('pbx_trunks');
        foreach (['cid_keep_last', 'cid_prepend'] as $col) {
            if ($table->hasColumn($col)) {
                $table->removeColumn($col);
            }
        }
        $table->update();
    }
}
