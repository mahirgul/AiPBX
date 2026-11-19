<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateMobileOauthNonces extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('mobile_oauth_nonces')) {
            return;
        }
        $this->table('mobile_oauth_nonces', ['id' => false, 'primary_key' => ['nonce_hash']])
            ->addColumn('nonce_hash', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('expires_at', 'integer', ['signed' => false, 'null' => false])
            ->addIndex(['expires_at'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('mobile_oauth_nonces')) {
            $this->table('mobile_oauth_nonces')->drop()->save();
        }
    }
}
