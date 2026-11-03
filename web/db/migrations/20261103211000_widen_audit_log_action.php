<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** "password_reset_request" did not fit, so those audit rows were never written. */
final class WidenAuditLogAction extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE sys_audit_log MODIFY action VARCHAR(40) NOT NULL');
    }

    public function down(): void
    {
    }
}
