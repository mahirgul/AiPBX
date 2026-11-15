<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * AI → External agents: a voice agent of another service (talku.ai, Ultravox,
 * OpenAI Realtime SIP, a LiveKit or Pipecat server…) at a SIP address, used as
 * a call destination. The call stays on this PBX (CDR, recording, limits); the
 * agent may hand it back with a SIP REFER, which goes to its hand-over
 * destination only.
 */
final class CreatePbxAiAgents extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('pbx_ai_agents')) {
            return;
        }
        $this->table('pbx_ai_agents')
            ->addColumn('title', 'string', ['limit' => 100])
            ->addColumn('sip_uri', 'string', ['limit' => 255])
            ->addColumn('transport', 'string', ['limit' => 5, 'default' => 'udp'])
            ->addColumn('auth_username', 'string', ['limit' => 100, 'default' => ''])
            ->addColumn('auth_password', 'text', ['null' => true])
            ->addColumn('max_concurrent', 'integer', ['default' => 4])
            ->addColumn('max_minutes', 'integer', ['default' => 15])
            ->addColumn('ring_seconds', 'integer', ['default' => 20])
            ->addColumn('handover_dest_type', 'string', ['limit' => 30, 'default' => ''])
            ->addColumn('handover_dest_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('fallback_dest_type', 'string', ['limit' => 30, 'default' => 'hangup'])
            ->addColumn('fallback_dest_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('pbx_ai_agents')) {
            $this->table('pbx_ai_agents')->drop()->save();
        }
    }
}
