<?php

use Phinx\Migration\AbstractMigration;

/**
 * What happened the last time a plugin's cron job ran.
 *
 * One row per slug+job. The dispatcher writes the start BEFORE the job runs
 * (so a second dispatcher in the same minute skips it, and a crash still
 * counts as "started") and the finish afterwards, including failures.
 *
 * It is a core table, not a plugin's: the loader is the owner of this
 * bookkeeping, it must survive a plugin being disabled or removed, and a plugin
 * may not create or alter a core table. It also means the portal and the health
 * checks can read it without loading the plugin's code.
 *
 * `last_output` is bounded (the tail of the job's output); this is an
 * operations record, not a log. The real log stays in the system journal.
 *
 * `last_status` is SIGNED on purpose: a plugin job that exceeds its timeout is
 * killed and recorded as -1, which an unsigned column rejects outright
 * ("Out of range value for column 'last_status'") — a failed write in exactly
 * the case the timeout exists for. An exit code still fits: 0-255.
 */
final class CreatePluginCronRuns extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('sys_plugin_cron_runs')) {
            return;
        }
        $this->table('sys_plugin_cron_runs', ['id' => 'id'])
            ->addColumn('slug', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('job', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('last_started', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('last_finished', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            // -1 = killed at its deadline (see the class comment).
            ->addColumn('last_status', 'integer', ['signed' => true, 'null' => false, 'default' => 0])
            ->addColumn('last_duration_ms', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('last_output', 'string', ['limit' => 2048, 'null' => false, 'default' => ''])
            ->addColumn('runs', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('failures', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addIndex(['slug', 'job'], ['unique' => true, 'name' => 'sys_plugin_cron_runs_slug_job'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('sys_plugin_cron_runs')) {
            $this->table('sys_plugin_cron_runs')->drop()->save();
        }
    }
}
