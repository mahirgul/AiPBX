<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** External AI agents (SIP) are gone: their table, the unpublished plugin's row and their pending syncs. */
final class RemoveExternalAiAgents extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('pbx_ai_agents')) {
            $this->table('pbx_ai_agents')->drop()->save();
        }
        if ($this->hasTable('sys_plugins')) {
            $this->execute("DELETE FROM sys_plugins WHERE slug = 'external-voice-ai'");
        }
        if ($this->hasTable('sys_pending_sync')) {
            $this->execute("DELETE FROM sys_pending_sync WHERE domain = 'ai_agents'");
        }
    }

    public function down(): void
    {
        // The feature is removed; nothing to restore.
    }
}
