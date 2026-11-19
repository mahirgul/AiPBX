<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateExternalDbQueries extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pbx_external_db_queries')) {
            $this->createQueries();
        }
        $ivr = $this->table('pbx_ivr_data_steps');
        if (!$ivr->hasColumn('saved_query_id')) {
            $ivr->addColumn('saved_query_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
                ->addForeignKey('saved_query_id', 'pbx_external_db_queries', 'id', ['delete' => 'RESTRICT'])
                ->update();
        }
    }

    private function createQueries(): void
    {
        $this->table('pbx_external_db_queries', ['signed' => false])
            ->addColumn('query_key', 'string', ['limit' => 30])
            ->addColumn('description', 'string', ['limit' => 300])
            ->addColumn('external_db_id', 'integer', ['signed' => false])
            ->addColumn('query_sql', 'text')
            ->addColumn('access_mode', 'string', ['limit' => 10, 'default' => 'verified'])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addIndex(['query_key'], ['unique' => true])
            ->addForeignKey('external_db_id', 'pbx_external_dbs', 'id', ['delete' => 'RESTRICT'])
            ->create();
    }

    public function down(): void
    {
        $this->table('pbx_ivr_data_steps')->dropForeignKey('saved_query_id')->removeColumn('saved_query_id')->update();
        $this->table('pbx_external_db_queries')->drop()->save();
    }
}
