<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Roadmap 2.2: External database connections
 * Stores connections to MySQL / MariaDB and PostgreSQL (encrypted password,
 * timeout, test connection button). Used by IVR data step and plugins.
 */
final class CreateExternalDbs extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('pbx_external_dbs')) {
            return;
        }

        $this->table('pbx_external_dbs', [
            'id' => true,
            'primary_key' => ['id'],
            'collation' => 'utf8mb4_general_ci',
            'encoding' => 'utf8mb4'
        ])
        ->addColumn('name', 'string', ['limit' => 100])
        ->addColumn('driver', 'string', ['limit' => 32, 'default' => 'mysql'])
        ->addColumn('host', 'string', ['limit' => 255, 'default' => 'localhost'])
        ->addColumn('port', 'integer', ['default' => 3306])
        ->addColumn('database_name', 'string', ['limit' => 128])
        ->addColumn('username', 'string', ['limit' => 128])
        ->addColumn('password_encrypted', 'text', ['null' => true])
        ->addColumn('timeout_sec', 'integer', ['default' => 3])
        ->addColumn('charset', 'string', ['limit' => 32, 'default' => 'utf8mb4'])
        ->addColumn('ssl_mode', 'string', ['limit' => 32, 'default' => 'prefer'])
        ->addColumn('is_active', 'boolean', ['default' => true])
        ->addColumn('last_tested_at', 'datetime', ['null' => true])
        ->addColumn('last_test_status', 'string', ['limit' => 32, 'null' => true])
        ->addColumn('last_test_message', 'text', ['null' => true])
        ->addColumn('created_at', 'datetime')
        ->addColumn('updated_at', 'datetime')
        ->addColumn('created_by', 'integer', ['null' => true])
        ->addIndex(['name'])
        ->addIndex(['is_active'])
        ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('pbx_external_dbs')) {
            $this->table('pbx_external_dbs')->drop()->save();
        }
    }
}
