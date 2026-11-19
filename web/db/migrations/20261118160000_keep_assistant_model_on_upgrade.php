<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * With nothing chosen the PBX Assistant now uses rules only (no language model).
 * An installation that already runs the Assistant keeps "local default" as before.
 */
final class KeepAssistantModelOnUpgrade extends AbstractMigration
{
    public function up(): void
    {
        $on = $this->fetchRow("SELECT enabled FROM sys_plugins WHERE slug = 'ai-assistant'");
        $bound = $this->fetchRow("SELECT id FROM ai_bindings WHERE feature = 'assistant' AND scope_ref = 'global' AND task = 'chat' AND language = 'default'");
        $legacy = $this->fetchRow("SELECT setting_value FROM sys_settings WHERE setting_key = 'assistant.model'");
        if ($on && (int) $on['enabled'] === 1 && !$bound && (!$legacy || trim((string) $legacy['setting_value']) === '')) {
            $this->execute("INSERT INTO ai_bindings (feature, scope_ref, task, language, mode, model_ref_id, updated_at)
                            VALUES ('assistant', 'global', 'chat', 'default', 'local_default', NULL, NOW())");
        }
    }

    public function down(): void
    {
    }
}
