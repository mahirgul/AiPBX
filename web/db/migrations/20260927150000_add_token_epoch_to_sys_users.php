<?php

use Phinx\Migration\AbstractMigration;

/**
 * Per-user counter to be able to revoke mobile/chat session tokens. With 0
 * the signature is the same as the old form (existing tokens stay valid); it
 * increments on a password reset and invalidates every token issued until then.
 * Read by both PHP (api/mobile/auth_helper.php) and the Go chat service (chat/auth.go).
 */
final class AddTokenEpochToSysUsers extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('sys_users');
        if (!$table->hasColumn('token_epoch')) {
            $table->addColumn('token_epoch', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
                  ->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('sys_users');
        if ($table->hasColumn('token_epoch')) {
            $table->removeColumn('token_epoch')->update();
        }
    }
}
