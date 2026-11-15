<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Call quality review (Auto-QA, CdrAiService): a score of a transcribed call
 * against the administrator's criteria, the caller's mood, whether the matter
 * was resolved, a short note, and whether the review ran by itself.
 */
final class AddQaToCdrAi extends AbstractMigration
{
    public function change(): void
    {
        $t = $this->table('cdr_ai');
        foreach ([
            ['qa_score', 'smallinteger', ['null' => true, 'default' => null]],
            ['qa_sentiment', 'string', ['limit' => 10, 'default' => '']],
            ['qa_resolved', 'boolean', ['null' => true, 'default' => null]],
            ['qa_notes', 'string', ['limit' => 500, 'default' => '']],
            ['source', 'string', ['limit' => 10, 'default' => 'manual']],
        ] as [$name, $type, $opt]) {
            if (!$t->hasColumn($name)) {
                $t->addColumn($name, $type, $opt);
            }
        }
        $t->update();
    }
}
