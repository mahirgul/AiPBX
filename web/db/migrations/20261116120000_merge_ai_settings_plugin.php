<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Merges ai-cloud, ai-models, and ai-voicemail into the unified `ai` plugin
 * and maps `ai_cloud` / `ai_models` RBAC permissions to `ai_settings`.
 *
 * An installation where any of the three old plugins was enabled gets `ai`
 * enabled; installations where all three were off keep `ai` disabled.
 */
final class MergeAiSettingsPlugin extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('sys_plugins')) {
            $wasOn = (int) ($this->fetchRow(
                "SELECT MAX(enabled) AS on_flag FROM sys_plugins WHERE slug IN ('ai-cloud', 'ai-models', 'ai-voicemail')"
            )['on_flag'] ?? 0);

            $now = date('Y-m-d H:i:s');
            $this->execute(
                "INSERT INTO sys_plugins (slug, name, version, source, enabled, worker_enabled, config_version, installed_at, updated_at)
                 VALUES ('ai', 'AI Settings', '1.0.0', 'official', {$wasOn}, 0, 1, '{$now}', '{$now}')
                 ON DUPLICATE KEY UPDATE enabled = GREATEST(enabled, {$wasOn}), updated_at = '{$now}'"
            );

            // Settings and KV (including historical voicemail summaries) remain intact.
            // Only catalog registrations retire; never erase user data during a merge.
            $this->execute("DELETE FROM sys_plugins WHERE slug IN ('ai-cloud', 'ai-models', 'ai-voicemail')");
        }

        if ($this->hasTable('sys_role_permissions')) {
            $this->execute(
                "INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
                 SELECT role_key, 'ai_settings', MAX(can_view), MAX(can_access), MAX(can_edit), MAX(can_delete)
                 FROM sys_role_permissions
                 WHERE module_key IN ('ai_cloud', 'ai_models')
                 GROUP BY role_key
                 ON DUPLICATE KEY UPDATE
                    can_view = GREATEST(can_view, VALUES(can_view)),
                    can_access = GREATEST(can_access, VALUES(can_access)),
                    can_edit = GREATEST(can_edit, VALUES(can_edit)),
                    can_delete = GREATEST(can_delete, VALUES(can_delete))"
            );
            $this->execute("DELETE FROM sys_role_permissions WHERE module_key IN ('ai_cloud', 'ai_models')");
        }
    }

    public function down(): void
    {
        // Merged into `ai`; nothing to split back automatically.
    }
}
