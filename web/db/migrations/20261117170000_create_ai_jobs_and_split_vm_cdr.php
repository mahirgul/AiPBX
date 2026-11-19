<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Faz 4: Creates the `ai_jobs` table for durable async AI job execution,
 * seeds `ai-voicemail` and `ai-call-analysis` plugins in `sys_plugins`,
 * and grants `ai_voicemail` / `ai_call_analysis` permissions to `admin`.
 */
final class CreateAiJobsAndSplitVmCdr extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('ai_jobs')) {
            $this->table('ai_jobs', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'biginteger', [
                    'identity' => true,
                    'signed' => false,
                    'null' => false,
                ])
                ->addColumn('feature', 'string', ['limit' => 48, 'null' => false])
                ->addColumn('task', 'string', ['limit' => 24, 'null' => false])
                ->addColumn('entity_type', 'string', ['limit' => 32, 'null' => false])
                ->addColumn('entity_id', 'string', ['limit' => 64, 'null' => false])
                ->addColumn('idempotency_key', 'string', ['limit' => 128, 'null' => false])
                ->addColumn('binding_ref', 'string', ['limit' => 128, 'null' => true])
                ->addColumn('status', 'enum', [
                    'values' => ['pending', 'running', 'succeeded', 'failed', 'cancelled'],
                    'default' => 'pending',
                    'null' => false,
                ])
                ->addColumn('attempts', 'integer', [
                    'limit' => MysqlAdapter::INT_TINY,
                    'signed' => false,
                    'default' => 0,
                    'null' => false,
                ])
                ->addColumn('max_attempts', 'integer', [
                    'limit' => MysqlAdapter::INT_TINY,
                    'signed' => false,
                    'default' => 3,
                    'null' => false,
                ])
                ->addColumn('lease_owner', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('lease_expires_at', 'datetime', ['null' => true])
                ->addColumn('payload_json', 'text', [
                    'limit' => MysqlAdapter::TEXT_MEDIUM,
                    'null' => true,
                ])
                ->addColumn('result_json', 'text', [
                    'limit' => MysqlAdapter::TEXT_MEDIUM,
                    'null' => true,
                ])
                ->addColumn('last_error', 'text', ['null' => true])
                ->addColumn('created_at', 'datetime', ['null' => false])
                ->addColumn('updated_at', 'datetime', ['null' => false])
                ->addColumn('finished_at', 'datetime', ['null' => true])
                ->addIndex(['idempotency_key'], ['unique' => true, 'name' => 'uniq_ai_jobs_idempotency'])
                ->addIndex(['status', 'lease_expires_at'], ['name' => 'idx_ai_jobs_status_lease'])
                ->addIndex(['entity_type', 'entity_id'], ['name' => 'idx_ai_jobs_entity'])
                ->create();
        }

        if ($this->hasTable('sys_plugins')) {
            $now = date('Y-m-d H:i:s');
            $row = $this->fetchRow(
                "SELECT MAX(enabled) AS any_on FROM sys_plugins WHERE slug IN ('ai', 'ailocal', 'aicloud')"
            );
            $enabled = ($row !== false && (int) ($row['any_on'] ?? 0) === 1) ? 1 : 0;

            $this->execute(
                "INSERT INTO sys_plugins (slug, name, version, source, enabled, worker_enabled, config_version, installed_at, updated_at)
                 VALUES ('ai-voicemail', 'AI Voicemail', '1.0.0', 'official', {$enabled}, 0, 1, '{$now}', '{$now}')
                 ON DUPLICATE KEY UPDATE updated_at = '{$now}'"
            );
            $this->execute(
                "INSERT INTO sys_plugins (slug, name, version, source, enabled, worker_enabled, config_version, installed_at, updated_at)
                 VALUES ('ai-call-analysis', 'AI Call Analysis', '1.0.0', 'official', {$enabled}, 0, 1, '{$now}', '{$now}')
                 ON DUPLICATE KEY UPDATE updated_at = '{$now}'"
            );
        }

        if ($this->hasTable('sys_role_permissions')) {
            foreach (['ai_voicemail', 'ai_call_analysis'] as $moduleKey) {
                $this->execute(
                    "INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
                     VALUES ('admin', '{$moduleKey}', 1, 1, 1, 1)
                     ON DUPLICATE KEY UPDATE
                        can_view = GREATEST(can_view, 1),
                        can_access = GREATEST(can_access, 1),
                        can_edit = GREATEST(can_edit, 1),
                        can_delete = GREATEST(can_delete, 1)"
                );
            }
        }
    }

    public function down(): void
    {
        // Forward migration only; keep data intact on rollback.
    }
}
