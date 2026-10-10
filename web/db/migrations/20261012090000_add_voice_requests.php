<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * AI applications of type "voice_requests": their settings (greeting, the
 * requests with keywords, examples, reply, e-mail and destination) as JSON,
 * and the log of recognised requests (who called, what was said, which
 * request, handled or not).
 */
final class AddVoiceRequests extends AbstractMigration
{
    public function up(): void
    {
        $apps = $this->table('pbx_ai_apps');
        if (!$apps->hasColumn('config')) {
            $apps->addColumn('config', 'text', ['null' => true, 'after' => 'fallback_text'])->update();
        }
        if (!$this->hasTable('ai_requests')) {
            $this->table('ai_requests')
                ->addColumn('app_id', 'integer')
                ->addColumn('call_uuid', 'char', ['limit' => 36])
                ->addColumn('caller', 'string', ['limit' => 40, 'default' => ''])
                ->addColumn('caller_name', 'string', ['limit' => 80, 'default' => ''])
                ->addColumn('intent_id', 'string', ['limit' => 40, 'default' => ''])
                ->addColumn('intent_name', 'string', ['limit' => 100, 'default' => ''])
                ->addColumn('transcript', 'text', ['null' => true])
                ->addColumn('score', 'decimal', ['precision' => 4, 'scale' => 3, 'default' => 0])
                ->addColumn('mail_sent', 'boolean', ['default' => false])
                ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('handled_at', 'timestamp', ['null' => true, 'default' => null])
                ->addColumn('handled_by', 'integer', ['null' => true, 'default' => null])
                ->addIndex(['call_uuid'], ['unique' => true])
                ->addIndex(['app_id', 'created_at'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('ai_requests')) {
            $this->table('ai_requests')->drop()->save();
        }
        $apps = $this->table('pbx_ai_apps');
        if ($apps->hasColumn('config')) {
            $apps->removeColumn('config')->update();
        }
    }
}
