<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Desk phone provisioning (roadmap item 9, #19).
 *
 * - pbx_phones:          one row per known phone (MAC); the random token is the
 *                        phone's provisioning URL, the web admin password is
 *                        generated per phone and kept encrypted (SecretBox).
 * - pbx_phones_waiting:  phones that asked for a configuration by MAC but are
 *                        not known yet ("waiting phones").
 * - pbx_phone_keys:      a user's key layout (page 0 = the phone itself,
 *                        1..n = expansion modules), written to the phone.
 * - pbx_phone_fetch_log: every configuration request, served or not. Never
 *                        holds a password: only the file name, the result
 *                        and the client.
 *
 * The 'phones' module is admin-only (auth.php circuit breaker): the pages show
 * provisioning URLs, and a URL hands out the SIP password.
 */
final class CreatePhoneProvisioning extends AbstractMigration
{
    public function up(): void
    {
        $this->table('pbx_phones', ['signed' => false])
            ->addColumn('mac', 'char', ['limit' => 12, 'null' => false])
            ->addColumn('model', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('user_id', 'integer', ['null' => true, 'default' => null])
            ->addColumn('token', 'char', ['limit' => 40, 'null' => false])
            ->addColumn('admin_password', 'string', ['limit' => 255, 'null' => false, 'default' => ''])
            ->addColumn('notes', 'string', ['limit' => 255, 'null' => false, 'default' => ''])
            ->addColumn('last_fetch_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('last_ip', 'string', ['limit' => 45, 'null' => true, 'default' => null])
            ->addColumn('last_user_agent', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['mac'], ['unique' => true])
            ->addIndex(['token'], ['unique' => true])
            ->addIndex(['user_id'])
            ->addForeignKey('user_id', 'sys_users', 'id', ['delete' => 'SET_NULL'])
            ->create();

        $this->table('pbx_phones_waiting', ['signed' => false])
            ->addColumn('mac', 'char', ['limit' => 12, 'null' => false])
            ->addColumn('vendor', 'string', ['limit' => 20, 'null' => false, 'default' => ''])
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => false, 'default' => ''])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => false, 'default' => ''])
            ->addColumn('request_count', 'integer', ['null' => false, 'default' => 1])
            ->addColumn('first_seen_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('last_seen_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['mac'], ['unique' => true])
            ->create();

        $this->table('pbx_phone_keys', ['signed' => false])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('page', 'integer', ['limit' => 255, 'null' => false, 'default' => 0])
            ->addColumn('position', 'integer', ['null' => false])
            ->addColumn('key_type', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('target', 'string', ['limit' => 64, 'null' => false, 'default' => ''])
            ->addColumn('label', 'string', ['limit' => 64, 'null' => false, 'default' => ''])
            ->addIndex(['user_id', 'page', 'position'], ['unique' => true])
            ->addForeignKey('user_id', 'sys_users', 'id', ['delete' => 'CASCADE'])
            ->create();

        $this->table('pbx_phone_fetch_log', ['signed' => false])
            ->addColumn('phone_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('mac', 'char', ['limit' => 12, 'null' => false, 'default' => ''])
            ->addColumn('file', 'string', ['limit' => 120, 'null' => false, 'default' => ''])
            ->addColumn('result', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => false, 'default' => ''])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => false, 'default' => ''])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['created_at'])
            ->addIndex(['ip', 'created_at'])
            ->addIndex(['phone_id'])
            ->create();

        // Existing installations only; fresh installs get the row from db/seed.sql.
        $this->execute(
            "INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
             SELECT 'admin', 'phones', 1, 1, 1, 1 FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM sys_role_permissions WHERE role_key = 'admin' AND module_key = 'phones')
               AND EXISTS (SELECT 1 FROM sys_roles WHERE role_key = 'admin')"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM sys_role_permissions WHERE module_key = 'phones'");
        $this->table('pbx_phone_fetch_log')->drop()->save();
        $this->table('pbx_phone_keys')->drop()->save();
        $this->table('pbx_phones_waiting')->drop()->save();
        $this->table('pbx_phones')->drop()->save();
    }
}
