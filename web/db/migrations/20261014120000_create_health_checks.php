<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Admin → Health checks: the last result of each check (health_checks) and the
 * alarm / recovery history of the last 30 days (health_events).
 */
final class CreateHealthChecks extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('health_checks')) {
            $this->table('health_checks', ['id' => false, 'primary_key' => ['check_key']])
                ->addColumn('check_key', 'string', ['limit' => 40])
                ->addColumn('interval_min', 'integer', ['null' => true, 'comment' => 'NULL: the default of the check; 0: off'])
                ->addColumn('status', 'string', ['limit' => 8, 'default' => 'unknown'])
                ->addColumn('message', 'string', ['limit' => 500, 'default' => ''])
                ->addColumn('checked_at', 'datetime', ['null' => true])
                ->addColumn('changed_at', 'datetime', ['null' => true])
                ->addColumn('bad_streak', 'integer', ['default' => 0])
                ->addColumn('alerted_status', 'string', ['limit' => 8, 'default' => ''])
                ->addColumn('alerted_at', 'datetime', ['null' => true])
                ->create();
        }
        if (!$this->hasTable('health_events')) {
            $this->table('health_events')
                ->addColumn('check_key', 'string', ['limit' => 40])
                ->addColumn('status', 'string', ['limit' => 8])
                ->addColumn('message', 'string', ['limit' => 500, 'default' => ''])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['created_at'])
                ->create();
        }
    }

    public function down(): void
    {
        foreach (['health_events', 'health_checks'] as $t) {
            if ($this->hasTable($t)) {
                $this->table($t)->drop()->save();
            }
        }
    }
}
