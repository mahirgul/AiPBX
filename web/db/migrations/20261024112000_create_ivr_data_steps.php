<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Roadmap 2.3: IVR data step
 * Executes a database query (2.2) or HTTP request, sets channel variables,
 * and branches on found / not found / timeout.
 */
final class CreateIvrDataSteps extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('pbx_ivr_data_steps')) {
            return;
        }

        $this->table('pbx_ivr_data_steps', [
            'id' => true,
            'primary_key' => ['id'],
            'collation' => 'utf8mb4_general_ci',
            'encoding' => 'utf8mb4'
        ])
        ->addColumn('name', 'string', ['limit' => 100])
        ->addColumn('description', 'text', ['null' => true])
        ->addColumn('prompt_file', 'string', ['limit' => 255, 'null' => true])
        ->addColumn('input_source', 'string', ['limit' => 20, 'default' => 'dtmf'])
        ->addColumn('max_digits', 'integer', ['default' => 10])
        ->addColumn('input_timeout_sec', 'integer', ['default' => 5])
        ->addColumn('data_source', 'string', ['limit' => 20, 'default' => 'database'])
        ->addColumn('external_db_id', 'integer', ['null' => true])
        ->addColumn('query_sql', 'text', ['null' => true])
        ->addColumn('http_url', 'string', ['limit' => 500, 'null' => true])
        ->addColumn('http_method', 'string', ['limit' => 10, 'default' => 'GET'])
        ->addColumn('channel_var_name', 'string', ['limit' => 64, 'default' => 'IVR_DATA_VAL'])
        ->addColumn('found_dest_type', 'string', ['limit' => 50, 'default' => 'queue'])
        ->addColumn('found_dest_id', 'string', ['limit' => 100, 'default' => ''])
        ->addColumn('not_found_dest_type', 'string', ['limit' => 50, 'default' => 'hangup'])
        ->addColumn('not_found_dest_id', 'string', ['limit' => 100, 'default' => ''])
        ->addColumn('timeout_dest_type', 'string', ['limit' => 50, 'default' => 'hangup'])
        ->addColumn('timeout_dest_id', 'string', ['limit' => 100, 'default' => ''])
        ->addColumn('is_active', 'boolean', ['default' => true])
        ->addColumn('created_at', 'datetime')
        ->addColumn('updated_at', 'datetime')
        ->addColumn('created_by', 'integer', ['null' => true])
        ->addIndex(['is_active'])
        ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('pbx_ivr_data_steps')) {
            $this->table('pbx_ivr_data_steps')->drop()->save();
        }
    }
}
