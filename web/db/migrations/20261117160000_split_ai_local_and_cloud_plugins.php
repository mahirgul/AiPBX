<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Faz 2: Registers `ailocal` and `aicloud` plugins, splits `ai_settings` RBAC
 * into `ai_local` and `ai_cloud`, and creates `ai_connections`, `ai_model_refs`,
 * and `ai_bindings` for the common model selector.
 */
final class SplitAiLocalAndCloudPlugins extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('sys_plugins')) {
            $now = date('Y-m-d H:i:s');
            $aiRow = $this->fetchRow("SELECT enabled FROM sys_plugins WHERE slug = 'ai' LIMIT 1");
            $localRow = $this->fetchRow("SELECT enabled FROM sys_plugins WHERE slug = 'ai-models' LIMIT 1");
            $cloudRow = $this->fetchRow("SELECT enabled FROM sys_plugins WHERE slug = 'ai-cloud' LIMIT 1");

            $localOn = $localRow !== false ? (int) $localRow['enabled'] : ($aiRow !== false ? (int) $aiRow['enabled'] : 0);
            $cloudOn = $cloudRow !== false ? (int) $cloudRow['enabled'] : ($aiRow !== false ? (int) $aiRow['enabled'] : 0);

            $this->execute(
                "INSERT INTO sys_plugins (slug, name, version, source, enabled, worker_enabled, config_version, installed_at, updated_at)
                 VALUES ('ailocal', 'Local AI', '1.0.0', 'official', {$localOn}, 0, 1, '{$now}', '{$now}')
                 ON DUPLICATE KEY UPDATE updated_at = '{$now}'"
            );
            $this->execute(
                "INSERT INTO sys_plugins (slug, name, version, source, enabled, worker_enabled, config_version, installed_at, updated_at)
                 VALUES ('aicloud', 'Cloud AI', '1.0.0', 'official', {$cloudOn}, 0, 1, '{$now}', '{$now}')
                 ON DUPLICATE KEY UPDATE updated_at = '{$now}'"
            );
        }

        if ($this->hasTable('sys_role_permissions')) {
            foreach (['ai_local' => 'ai_models', 'ai_cloud' => 'ai_cloud'] as $target => $legacy) {
                $this->execute(
                    "INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
                     SELECT role_key, '{$target}', MAX(can_view), MAX(can_access), MAX(can_edit), MAX(can_delete)
                     FROM sys_role_permissions
                     WHERE module_key IN ('ai_settings', '{$legacy}')
                     GROUP BY role_key
                     ON DUPLICATE KEY UPDATE
                        can_view = GREATEST(can_view, VALUES(can_view)),
                        can_access = GREATEST(can_access, VALUES(can_access)),
                        can_edit = GREATEST(can_edit, VALUES(can_edit)),
                        can_delete = GREATEST(can_delete, VALUES(can_delete))"
                );
            }
        }

        if (!$this->hasTable('ai_connections')) {
            $this->table('ai_connections', ['id' => false, 'primary_key' => 'id'])
                ->addColumn('id', 'string', ['limit' => 64, 'null' => false])
                ->addColumn('plugin_slug', 'string', ['limit' => 32, 'null' => false])
                ->addColumn('adapter', 'string', ['limit' => 32, 'null' => false])
                ->addColumn('title', 'string', ['limit' => 128, 'null' => false])
                ->addColumn('endpoint_url', 'string', ['limit' => 512, 'null' => true])
                ->addColumn('region', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('secret_ref', 'string', ['limit' => 128, 'null' => true])
                ->addColumn('enabled', 'boolean', ['default' => true, 'null' => false])
                ->addColumn('config_revision', 'integer', ['default' => 1, 'null' => false])
                ->addColumn('policy_tag', 'string', ['limit' => 32, 'default' => 'standard', 'null' => false])
                ->addColumn('updated_at', 'datetime', ['null' => false])
                ->addIndex(['plugin_slug'])
                ->create();
        }

        if (!$this->hasTable('ai_model_refs')) {
            $this->table('ai_model_refs', ['id' => false, 'primary_key' => 'id'])
                ->addColumn('id', 'string', ['limit' => 128, 'null' => false])
                ->addColumn('connection_id', 'string', ['limit' => 64, 'null' => false])
                ->addColumn('external_model', 'string', ['limit' => 255, 'null' => false])
                ->addColumn('revision_digest', 'string', ['limit' => 128, 'null' => true])
                ->addColumn('task', 'string', ['limit' => 32, 'null' => false])
                ->addColumn('capabilities_json', 'text', ['null' => true])
                ->addColumn('language', 'string', ['limit' => 16, 'null' => true])
                ->addColumn('embedding_dim', 'integer', ['null' => true])
                ->addColumn('verified', 'boolean', ['default' => false, 'null' => false])
                ->addIndex(['connection_id', 'task'])
                ->create();
        }

        if (!$this->hasTable('ai_bindings')) {
            $this->table('ai_bindings', ['id' => true, 'primary_key' => 'id'])
                ->addColumn('feature', 'string', ['limit' => 48, 'null' => false])
                ->addColumn('scope_ref', 'string', ['limit' => 64, 'default' => 'global', 'null' => false])
                ->addColumn('task', 'string', ['limit' => 32, 'null' => false])
                ->addColumn('language', 'string', ['limit' => 16, 'default' => 'default', 'null' => false])
                ->addColumn('mode', 'string', ['limit' => 32, 'default' => 'off', 'null' => false])
                ->addColumn('model_ref_id', 'string', ['limit' => 128, 'null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => false])
                ->addIndex(['feature', 'scope_ref', 'task', 'language'], ['unique' => true, 'name' => 'uniq_ai_binding'])
                ->create();
        }
    }

    public function down(): void
    {
        // Forward migration only; keep data intact on rollback.
    }
}
