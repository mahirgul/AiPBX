<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Advanced configuration: an entry can now belong to another Asterisk file than pjsip.conf, and a
 * PJSIP entry can name a group of objects. Existing rows stay PJSIP entries on one object.
 */
final class AdvancedConfigFilesAndTargets extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('pbx_advanced_pjsip');
        if (!$table->hasColumn('config_file')) {
            $table->addColumn('config_file', 'string', ['limit' => 20, 'default' => 'pjsip', 'after' => 'id'])
                ->addColumn('target', 'string', ['limit' => 20, 'default' => 'object', 'after' => 'config_file'])
                ->update();
        }
        if ($table->hasIndex(['object_type', 'object_name'])) {
            $table->removeIndex(['object_type', 'object_name'])->update();
        }
        if (!$table->hasIndex(['config_file', 'target', 'object_type', 'object_name'])) {
            $table->addIndex(['config_file', 'target', 'object_type', 'object_name'], ['unique' => true])->update();
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM pbx_advanced_pjsip WHERE config_file <> 'pjsip' OR target <> 'object'");
        $table = $this->table('pbx_advanced_pjsip');
        $table->removeIndex(['config_file', 'target', 'object_type', 'object_name'])->update();
        $table->addIndex(['object_type', 'object_name'], ['unique' => true])->update();
        $table->removeColumn('target')->removeColumn('config_file')->update();
    }
}
