<?php

use Phinx\Migration\AbstractMigration;

final class CreateSysUserQrTokens extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('sys_user_qr_tokens')) {
            $table = $this->table('sys_user_qr_tokens', [
                'id' => true,
                'primary_key' => ['id'],
                'collation' => 'utf8mb4_unicode_ci'
            ]);

            $table->addColumn('user_id', 'integer', ['limit' => 11, 'null' => false])
                  ->addColumn('token', 'string', ['limit' => 64, 'null' => false])
                  ->addColumn('expires_at', 'datetime', ['null' => false])
                  ->addColumn('used_at', 'datetime', ['null' => true, 'default' => null])
                  ->addColumn('device_name', 'string', ['limit' => 128, 'null' => true, 'default' => null])
                  ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true, 'default' => null])
                  ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                  ->addIndex(['token'], ['unique' => true])
                  ->addIndex(['user_id'])
                  ->addIndex(['token', 'expires_at'])
                  ->addForeignKey('user_id', 'sys_users', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                  ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('sys_user_qr_tokens')) {
            $this->table('sys_user_qr_tokens')->drop()->save();
        }
    }
}
