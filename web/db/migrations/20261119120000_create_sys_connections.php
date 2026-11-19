<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Integration -> Connections: an outside system (Odoo, a CRM, n8n) that reads the PBX with
 * its own API token and receives the call events on its own signed webhook. The row owns
 * both: removing the connection removes exactly them.
 */
final class CreateSysConnections extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('sys_connections')) {
            return;
        }
        $this->table('sys_connections', ['signed' => false, 'collation' => 'utf8mb4_general_ci'])
            ->addColumn('uuid', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('kind', 'string', ['limit' => 20, 'null' => false, 'default' => 'generic'])
            ->addColumn('base_url', 'string', ['limit' => 500, 'null' => false])
            ->addColumn('api_token_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('webhook_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created_by', 'integer', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('rotated_at', 'datetime', ['null' => true])
            ->addIndex(['uuid'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('sys_connections')) {
            $this->table('sys_connections')->drop()->save();
        }
    }
}
