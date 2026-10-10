<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * AI → Cloud services: this month's use of each cloud AI provider (seconds of
 * audio sent for speech to text, characters spoken, language-model tokens).
 */
final class CreateAiCloudUsage extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('ai_cloud_usage')) {
            return;
        }
        $this->table('ai_cloud_usage', ['id' => false, 'primary_key' => ['provider', 'month']])
            ->addColumn('provider', 'string', ['limit' => 32])
            ->addColumn('month', 'char', ['limit' => 7])
            ->addColumn('stt_seconds', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => 0])
            ->addColumn('tts_chars', 'biginteger', ['default' => 0])
            ->addColumn('llm_tokens', 'biginteger', ['default' => 0])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_cloud_usage')) {
            $this->table('ai_cloud_usage')->drop()->save();
        }
    }
}
