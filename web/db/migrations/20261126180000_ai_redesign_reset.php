<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The AI redesign (one account per feature, no default layer, Realtime or cascade agents, knowledge bases of one text,
 * local TTS only) starts clean: nothing is carried over. The applications stay (number, title, destination) with their AI
 * settings empty, so a call goes to the destination until the administrator sets the agent up again; the old
 * model choices, default bindings, per-language engines and tool files are removed.
 */
final class AiRedesignReset extends AbstractMigration
{
    public function up(): void
    {
        // An agent's instructions may now be long (60,000 characters).
        if ($this->hasTable('pbx_ai_apps')) {
            $this->execute('ALTER TABLE pbx_ai_apps MODIFY config MEDIUMTEXT NULL, MODIFY text_template MEDIUMTEXT NOT NULL DEFAULT \'\'');
            $this->execute("UPDATE pbx_ai_apps SET config = NULL, model_id = ''");
        }
        if ($this->hasTable('ai_bindings')) {
            $this->execute('DELETE FROM ai_bindings');
        }
        if ($this->hasTable('ai_knowledge_docs')) {
            $this->execute("DELETE FROM ai_knowledge_docs WHERE filename = 'agent-tools.txt'");
        }
        if ($this->hasTable('ai_knowledge_collections')) {
            // Local meaning models are gone: those bases search by keywords until an embedding account is chosen.
            $this->execute("UPDATE ai_knowledge_collections SET embedding_binding = NULL WHERE embedding_binding LIKE 'local:%'");
        }
        $this->execute("DELETE FROM sys_settings WHERE setting_key LIKE 'ai\\_engine.%' OR setting_key LIKE 'ai\\_use.%'
                        OR setting_key IN ('assistant.model', 'ai_hf_token')");
    }

    public function down(): void
    {
    }
}
