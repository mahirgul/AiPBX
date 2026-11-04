<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** The extension form shows and can turn off P-Asserted-Identity. On, as the template has it. */
final class AddSipSendPaiToUsers extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->table('sys_users')->hasColumn('sip_send_pai')) {
            $this->execute('ALTER TABLE sys_users ADD COLUMN sip_send_pai TINYINT(1) NOT NULL DEFAULT 1 AFTER sip_auth_digest');
        }
    }

    public function down(): void
    {
        if ($this->table('sys_users')->hasColumn('sip_send_pai')) {
            $this->execute('ALTER TABLE sys_users DROP COLUMN sip_send_pai');
        }
    }
}
