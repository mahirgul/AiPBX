<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateAiGatewayTickets extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('ai_gateway_tickets')) {
            return;
        }
        $this->table('ai_gateway_tickets', ['id' => false, 'primary_key' => 'request_id'])
            ->addColumn('request_id', 'string', ['limit' => 32])
            ->addColumn('user_id', 'integer')
            ->addColumn('token_epoch', 'integer', ['default' => 0])
            ->addColumn('feature', 'string', ['limit' => 30])
            ->addColumn('selection', 'string', ['limit' => 100])
            ->addColumn('config_revision', 'string', ['limit' => 64])
            ->addColumn('payload_hash', 'string', ['limit' => 64])
            ->addColumn('draft_sealed', 'text', ['null' => true])
            ->addColumn('expires_at', 'integer', ['signed' => false])
            ->addColumn('consumed_at', 'integer', ['null' => true, 'signed' => false])
            ->addIndex('expires_at')->create();
    }

    public function down(): void
    {
        if ($this->hasTable('ai_gateway_tickets')) {
            $this->table('ai_gateway_tickets')->drop()->save();
        }
    }
}
