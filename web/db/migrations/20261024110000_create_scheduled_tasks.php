<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Roadmap 2.1: Scheduled tasks
 * Administrators run shell, PHP or Python scripts on a cron schedule inside
 * a sandboxed environment with history, versions, and audit logging.
 */
final class CreateScheduledTasks extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pbx_scheduled_tasks')) {
            $this->table('pbx_scheduled_tasks', [
                'id' => true,
                'primary_key' => ['id'],
                'collation' => 'utf8mb4_general_ci',
                'encoding' => 'utf8mb4'
            ])
            ->addColumn('name', 'string', ['limit' => 100])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('is_active', 'boolean', ['default' => false])
            ->addColumn('cron_expression', 'string', ['limit' => 64, 'default' => '0 3 * * *'])
            ->addColumn('script_type', 'string', ['limit' => 16, 'default' => 'shell'])
            ->addColumn('script_content', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM, 'null' => true])
            ->addColumn('timeout_sec', 'integer', ['default' => 300])
            ->addColumn('memory_limit_mb', 'integer', ['default' => 256])
            ->addColumn('cpu_limit_pct', 'integer', ['default' => 50])
            ->addColumn('network_access', 'boolean', ['default' => false])
            ->addColumn('env_vars', 'text', ['null' => true])
            ->addColumn('data_scopes', 'text', ['null' => true])
            ->addColumn('allow_personal_data', 'boolean', ['default' => false])
            ->addColumn('api_scopes', 'text', ['null' => true])
            ->addColumn('last_run_at', 'datetime', ['null' => true])
            ->addColumn('last_status', 'string', ['limit' => 16, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addColumn('created_by', 'integer', ['null' => true])
            ->addIndex(['is_active'])
            ->create();
        }

        if (!$this->hasTable('pbx_scheduled_task_runs')) {
            $this->table('pbx_scheduled_task_runs', [
                'id' => true,
                'primary_key' => ['id'],
                'collation' => 'utf8mb4_general_ci',
                'encoding' => 'utf8mb4'
            ])
            ->addColumn('task_id', 'integer')
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'running'])
            ->addColumn('triggered_by', 'string', ['limit' => 16, 'default' => 'schedule'])
            ->addColumn('started_at', 'datetime')
            ->addColumn('finished_at', 'datetime', ['null' => true])
            ->addColumn('duration_ms', 'integer', ['null' => true])
            ->addColumn('exit_code', 'integer', ['null' => true])
            ->addColumn('output', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM, 'null' => true])
            ->addColumn('error_message', 'text', ['null' => true])
            ->addIndex(['task_id', 'started_at'])
            ->addIndex(['status'])
            ->create();
        }

        if (!$this->hasTable('pbx_scheduled_task_versions')) {
            $this->table('pbx_scheduled_task_versions', [
                'id' => true,
                'primary_key' => ['id'],
                'collation' => 'utf8mb4_general_ci',
                'encoding' => 'utf8mb4'
            ])
            ->addColumn('task_id', 'integer')
            ->addColumn('version_num', 'integer', ['default' => 1])
            ->addColumn('script_content', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM, 'null' => true])
            ->addColumn('changed_by', 'integer', ['null' => true])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['task_id', 'version_num'])
            ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('pbx_scheduled_task_versions')) {
            $this->table('pbx_scheduled_task_versions')->drop()->save();
        }
        if ($this->hasTable('pbx_scheduled_task_runs')) {
            $this->table('pbx_scheduled_task_runs')->drop()->save();
        }
        if ($this->hasTable('pbx_scheduled_tasks')) {
            $this->table('pbx_scheduled_tasks')->drop()->save();
        }
    }
}
