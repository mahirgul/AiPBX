<?php

use Phinx\Migration\AbstractMigration;

final class CreateTwoFactorAndPasskeyTables extends AbstractMigration
{
    public function up(): void
    {
        // 1. sys_users tablosuna 2FA alanlarını ekle
        if ($this->hasTable('sys_users')) {
            $table = $this->table('sys_users');
            if (!$table->hasColumn('two_factor_enabled')) {
                $table->addColumn('two_factor_enabled', 'boolean', ['default' => 0, 'null' => false, 'after' => 'is_active']);
            }
            if (!$table->hasColumn('two_factor_secret')) {
                $table->addColumn('two_factor_secret', 'string', ['limit' => 64, 'null' => true, 'default' => null, 'after' => 'two_factor_enabled']);
            }
            if (!$table->hasColumn('two_factor_recovery_codes')) {
                $table->addColumn('two_factor_recovery_codes', 'text', ['null' => true, 'default' => null, 'after' => 'two_factor_secret']);
            }
            if (!$table->hasColumn('two_factor_confirmed_at')) {
                $table->addColumn('two_factor_confirmed_at', 'datetime', ['null' => true, 'default' => null, 'after' => 'two_factor_recovery_codes']);
            }
            $table->save();
        }

        // 2. sys_user_passkeys (WebAuthn / FIDO2) tablosunu oluştur
        if (!$this->hasTable('sys_user_passkeys')) {
            $passkeys = $this->table('sys_user_passkeys', [
                'id' => true,
                'primary_key' => ['id'],
                'collation' => 'utf8mb4_general_ci'
            ]);
            $passkeys->addColumn('user_id', 'integer', ['limit' => 11, 'null' => false])
                     ->addColumn('credential_id', 'string', ['limit' => 255, 'null' => false])
                     ->addColumn('public_key', 'text', ['null' => false])
                     ->addColumn('counter', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
                     ->addColumn('device_name', 'string', ['limit' => 100, 'default' => 'Passkey', 'null' => false])
                     ->addColumn('transports', 'string', ['limit' => 255, 'null' => true])
                     ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                     ->addColumn('last_used_at', 'datetime', ['null' => true, 'default' => null])
                     ->addIndex(['credential_id'], ['unique' => true])
                     ->addIndex(['user_id'])
                     ->addForeignKey('user_id', 'sys_users', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                     ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('sys_user_passkeys')) {
            $this->table('sys_user_passkeys')->drop()->save();
        }

        if ($this->hasTable('sys_users')) {
            $table = $this->table('sys_users');
            if ($table->hasColumn('two_factor_confirmed_at')) {
                $table->removeColumn('two_factor_confirmed_at');
            }
            if ($table->hasColumn('two_factor_recovery_codes')) {
                $table->removeColumn('two_factor_recovery_codes');
            }
            if ($table->hasColumn('two_factor_secret')) {
                $table->removeColumn('two_factor_secret');
            }
            if ($table->hasColumn('two_factor_enabled')) {
                $table->removeColumn('two_factor_enabled');
            }
            $table->save();
        }
    }
}
