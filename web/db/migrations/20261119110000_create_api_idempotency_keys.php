<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A repeated API request with the same client_request_id (a CRM retrying a click-to-call
 * after a timeout) gets the first answer back instead of a second call.
 */
final class CreateApiIdempotencyKeys extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('api_idempotency_keys')) {
            return;
        }
        $this->table('api_idempotency_keys', ['id' => false, 'primary_key' => ['key_hash'], 'collation' => 'utf8mb4_general_ci'])
            ->addColumn('key_hash', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('response_json', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['created_at'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('api_idempotency_keys')) {
            $this->table('api_idempotency_keys')->drop()->save();
        }
    }
}
