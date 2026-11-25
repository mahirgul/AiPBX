<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DynamicRoutingB8 extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('pbx_ivr_data_steps')) {
            $t = $this->table('pbx_ivr_data_steps');
            $changed = false;
            if (!$t->hasColumn('cache_ttl_sec')) {
                $t->addColumn('cache_ttl_sec', 'integer', ['default' => 0, 'null' => false, 'after' => 'caller_name_template']);
                $changed = true;
            }
            if (!$t->hasColumn('say_mode')) {
                $t->addColumn('say_mode', 'string', ['limit' => 16, 'default' => 'none', 'null' => false, 'after' => 'cache_ttl_sec']);
                $changed = true;
            }
            if (!$t->hasColumn('say_template')) {
                $t->addColumn('say_template', 'string', ['limit' => 255, 'null' => true, 'default' => null, 'after' => 'say_mode']);
                $changed = true;
            }
            if (!$t->hasColumn('cdr_write')) {
                $t->addColumn('cdr_write', 'boolean', ['default' => false, 'null' => false, 'after' => 'say_template']);
                $changed = true;
            }
            if ($changed) {
                $t->update();
            }
        }

        if (!$this->hasTable('pbx_ivr_data_cache')) {
            $this->table('pbx_ivr_data_cache', ['id' => false, 'primary_key' => ['step_id', 'cache_key']])
                ->addColumn('step_id', 'integer', ['null' => false])
                ->addColumn('cache_key', 'string', ['limit' => 64, 'null' => false])
                ->addColumn('payload_json', 'text', ['null' => false])
                ->addColumn('expires_at', 'datetime', ['null' => false])
                ->addIndex(['expires_at'])
                ->create();
        }

        if (!$this->hasTable('pbx_ivr_data_stats')) {
            $this->table('pbx_ivr_data_stats', ['id' => false, 'primary_key' => ['step_id', 'branch']])
                ->addColumn('step_id', 'integer', ['null' => false])
                ->addColumn('branch', 'string', ['limit' => 32, 'null' => false])
                ->addColumn('hits', 'integer', ['default' => 0, 'null' => false])
                ->addColumn('last_hit_at', 'datetime', ['null' => false])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('pbx_ivr_data_stats')) {
            $this->table('pbx_ivr_data_stats')->drop()->save();
        }
        if ($this->hasTable('pbx_ivr_data_cache')) {
            $this->table('pbx_ivr_data_cache')->drop()->save();
        }
        if ($this->hasTable('pbx_ivr_data_steps')) {
            $t = $this->table('pbx_ivr_data_steps');
            $changed = false;
            foreach (['cdr_write', 'say_template', 'say_mode', 'cache_ttl_sec'] as $col) {
                if ($t->hasColumn($col)) {
                    $t->removeColumn($col);
                    $changed = true;
                }
            }
            if ($changed) {
                $t->update();
            }
        }
    }
}
