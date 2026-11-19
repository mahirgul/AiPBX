<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Faz 6: Retire the legacy unified `ai` plugin and `ai_settings` RBAC key
 * after all consumers have migrated to `ailocal`, `aicloud`, `ai-voicemail`,
 * `ai-call-analysis`, `ai-tts`, `ai-apps`, and `/assistant?tab=settings`.
 */
final class RetireLegacyUnifiedAiPlugin extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("DELETE FROM sys_role_permissions WHERE module_key = 'ai_settings'");
        $this->execute("DELETE FROM sys_plugins WHERE slug = 'ai'");
    }

    public function down(): void
    {
        // One-way retirement; legacy unified plugin is removed.
    }
}
