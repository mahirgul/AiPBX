<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Administrator's own PJSIP options, kept in the database so regenerating the configuration and
 * updating AiPBX cannot lose them. Versions hold the whole list as it was before each change.
 */
final class CreateAdvancedPjsipTables extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('pbx_advanced_pjsip')) {
            $this->table('pbx_advanced_pjsip')
                ->addColumn('object_type', 'string', ['limit' => 20])
                ->addColumn('object_name', 'string', ['limit' => 80])
                ->addColumn('options', 'text')
                ->addColumn('note', 'string', ['limit' => 200, 'null' => true])
                ->addColumn('is_active', 'boolean', ['default' => true])
                ->addTimestamps()
                ->addIndex(['object_type', 'object_name'], ['unique' => true])
                ->create();
        }
        if (!$this->hasTable('pbx_advanced_pjsip_versions')) {
            $this->table('pbx_advanced_pjsip_versions')
                ->addColumn('snapshot', 'text', ['limit' => 16777215])
                ->addColumn('label', 'string', ['limit' => 200])
                ->addColumn('created_by', 'integer', ['null' => true])
                ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                ->create();
        }
    }
}
