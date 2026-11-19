<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Knowledge collections can be the PBX Assistant's source for one portal language
 * (assistant_language, e.g. "tr"); NULL = not used by the Assistant.
 */
final class AddAssistantLanguageToAiKnowledge extends AbstractMigration
{
    public function change(): void
    {
        $t = $this->table('ai_knowledge_collections');
        if (!$t->hasColumn('assistant_language')) {
            $t->addColumn('assistant_language', 'string', ['limit' => 8, 'null' => true, 'default' => null, 'after' => 'embedding_binding'])
              ->update();
        }
    }
}
