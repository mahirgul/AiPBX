<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * External AI agents (AI → External agents) become the official plugin `external-voice-ai`,
 * off on every installation: nobody uses it yet, and an administrator turns it on under Plugins.
 * Calls already routed to an agent keep working; only the menu and the destination lists hide it.
 */
final class ExternalVoiceAiPlugin extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('sys_plugins')) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $this->execute(
            "INSERT IGNORE INTO sys_plugins (slug, name, version, source, enabled, worker_enabled, config_version, installed_at, updated_at)
             VALUES ('external-voice-ai', 'External Voice AI (SIP)', '1.0.0', 'official', 0, 0, 1, '{$now}', '{$now}')"
        );
    }

    public function down(): void
    {
        if ($this->hasTable('sys_plugins')) {
            $this->execute("DELETE FROM sys_plugins WHERE slug = 'external-voice-ai'");
        }
    }
}
