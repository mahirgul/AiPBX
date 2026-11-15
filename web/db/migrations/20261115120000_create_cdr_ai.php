<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Transcript and summary of a recorded call (CdrAiService): made on demand from
 * the recording player and indexed for CDR report searches.
 */
final class CreateCdrAi extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('cdr_ai')) {
            return;
        }
        $this->table('cdr_ai', ['id' => false, 'primary_key' => ['cdr_id'], 'collation' => 'utf8mb4_general_ci'])
            ->addColumn('cdr_id', 'integer', ['signed' => false])
            ->addColumn('call_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('transcript', 'text', ['null' => true])
            ->addColumn('summary', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('engine', 'string', ['limit' => 100, 'default' => ''])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['call_id'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('cdr_ai')) {
            $this->table('cdr_ai')->drop()->save();
        }
    }
}
