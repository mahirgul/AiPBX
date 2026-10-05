<?php

use Phinx\Migration\AbstractMigration;

/**
 * Adds a purpose to the single-use mobile sign-in codes:
 *  - screen: the QR on the web "My Phone" screen (10 min)
 *  - email:  the "sign in to the mobile app" link in the invitation email (7 days)
 *  - google: the code Google sign-in hands back to the app (2 min) — the
 *            session token and SIP password used to travel in the aipbx://auth URL.
 * Existing rows become 'screen'; behaviour does not change.
 */
final class AddPurposeToSysUserQrTokens extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('sys_user_qr_tokens');
        if (!$table->hasColumn('purpose')) {
            $table->addColumn('purpose', 'string', ['limit' => 16, 'null' => false, 'default' => 'screen', 'after' => 'token'])
                  ->addIndex(['user_id', 'purpose'])
                  ->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('sys_user_qr_tokens');
        if ($table->hasColumn('purpose')) {
            $table->removeIndex(['user_id', 'purpose'])
                  ->removeColumn('purpose')
                  ->update();
        }
    }
}
