<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** How an AI call ended and how many times the caller spoke: for the AI Agent statistics and CSV. */
final class AddAiRequestResult extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('ai_requests');
        if (!$table->hasColumn('result')) {
            $table->addColumn('result', 'string', ['limit' => 20, 'default' => '', 'null' => false])
                ->addColumn('turns', 'integer', ['limit' => 255, 'default' => 0, 'null' => false, 'signed' => false])
                ->update();
        }
    }
}
