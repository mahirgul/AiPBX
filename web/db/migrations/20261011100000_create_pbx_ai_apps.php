<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * AI → Applications: AI features that take calls, each with its own internal
 * number and a destination afterwards. First type: "announcement" — a text
 * with values (dialplan variables, a lookup URL) spoken by a local voice model.
 */
final class CreatePbxAiApps extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('pbx_ai_apps')) {
            return;
        }
        $this->table('pbx_ai_apps')
            ->addColumn('title', 'string', ['limit' => 100])
            ->addColumn('app_type', 'string', ['limit' => 20, 'default' => 'announcement'])
            ->addColumn('internal_number', 'string', ['limit' => 10, 'null' => true, 'default' => null])
            ->addColumn('model_id', 'string', ['limit' => 64])
            ->addColumn('speed', 'decimal', ['precision' => 3, 'scale' => 2, 'default' => 1])
            ->addColumn('text_template', 'text')
            ->addColumn('lookup_url', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('fallback_text', 'text', ['null' => true])
            ->addColumn('dest_type', 'string', ['limit' => 30, 'default' => 'hangup'])
            ->addColumn('dest_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('max_concurrent', 'integer', ['default' => 4])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['internal_number'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('pbx_ai_apps')) {
            $this->table('pbx_ai_apps')->drop()->save();
        }
    }
}
