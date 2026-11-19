<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * External data (rapor plan, phase A and B):
 * - writing to an external database, per connection, off by default;
 * - AGI scripts written in the portal, with their saved versions (run by aipbx-agi in a sandbox);
 * - Dynamic Routing: the IVR data step grows more inputs and sources, a list of
 *   "value -> destination" rules, caller name, direct-to-extension and retries.
 */
final class AddDataSourcesAgiAndDynamicRouting extends AbstractMigration
{
    private const STEP_COLUMNS = [
        'input_var' => ['string', ['limit' => 64, 'null' => true]],
        'min_digits' => ['integer', ['default' => 1, 'null' => false]],
        'http_headers_enc' => ['text', ['null' => true]],
        'http_body' => ['text', ['null' => true]],
        'json_path' => ['string', ['limit' => 200, 'null' => true]],
        'agi_script' => ['string', ['limit' => 100, 'null' => true]],
        'value_template' => ['string', ['limit' => 255, 'null' => true]],
        'var_prefix' => ['string', ['limit' => 32, 'default' => '', 'null' => false]],
        'rules_json' => ['text', ['null' => true]],
        'caller_name_mode' => ['string', ['limit' => 10, 'default' => '', 'null' => false]],
        'caller_name_template' => ['string', ['limit' => 100, 'null' => true]],
        'direct_extension' => ['boolean', ['default' => 0, 'null' => false]],
        'busy_dest_type' => ['string', ['limit' => 50, 'default' => '', 'null' => false]],
        'busy_dest_id' => ['string', ['limit' => 100, 'default' => '', 'null' => false]],
        'invalid_prompt' => ['string', ['limit' => 255, 'null' => true]],
        'retries' => ['integer', ['default' => 0, 'null' => false]],
    ];

    public function up(): void
    {
        $dbs = $this->table('pbx_external_dbs');
        if (!$dbs->hasColumn('allow_write')) {
            $dbs->addColumn('allow_write', 'boolean', ['default' => 0, 'null' => false, 'after' => 'is_active'])->update();
        }

        if (!$this->hasTable('pbx_agi_scripts')) {
            $this->table('pbx_agi_scripts', ['signed' => false])
                ->addColumn('name', 'string', ['limit' => 64])
                ->addColumn('description', 'string', ['limit' => 255, 'default' => '', 'null' => false])
                ->addColumn('language', 'string', ['limit' => 10, 'default' => 'python', 'null' => false])
                ->addColumn('content', 'text', ['limit' => 16777215])
                ->addColumn('network_access', 'boolean', ['default' => 0, 'null' => false])
                ->addColumn('timeout_sec', 'integer', ['default' => 10, 'null' => false])
                ->addColumn('memory_mb', 'integer', ['default' => 128, 'null' => false])
                ->addColumn('is_active', 'boolean', ['default' => 1, 'null' => false])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_by', 'integer', ['null' => true])
                ->addIndex(['name'], ['unique' => true])
                ->create();
        }
        if (!$this->hasTable('pbx_agi_script_versions')) {
            $this->table('pbx_agi_script_versions', ['signed' => false])
                ->addColumn('script_id', 'integer', ['signed' => false])
                ->addColumn('language', 'string', ['limit' => 10])
                ->addColumn('content', 'text', ['limit' => 16777215])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('created_by', 'integer', ['null' => true])
                ->addIndex(['script_id', 'id'])
                ->addForeignKey('script_id', 'pbx_agi_scripts', 'id', ['delete' => 'CASCADE'])
                ->create();
        }

        $t = $this->table('pbx_ivr_data_steps');
        foreach (self::STEP_COLUMNS as $name => [$type, $opts]) {
            if (!$t->hasColumn($name)) {
                $t->addColumn($name, $type, $opts);
            }
        }
        $t->update();
    }

    public function down(): void
    {
        $t = $this->table('pbx_ivr_data_steps');
        foreach (array_keys(self::STEP_COLUMNS) as $c) {
            if ($t->hasColumn($c)) {
                $t->removeColumn($c);
            }
        }
        $t->update();
        foreach (['pbx_agi_script_versions', 'pbx_agi_scripts'] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop()->save();
            }
        }
        $dbs = $this->table('pbx_external_dbs');
        if ($dbs->hasColumn('allow_write')) {
            $dbs->removeColumn('allow_write')->update();
        }
    }
}
