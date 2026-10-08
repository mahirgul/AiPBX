<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * "Forgot your password?" requests are logged in sys_login_logs (status
 * RESET_REQ) for the per-IP limit and so they show in the audit log.
 */
final class AddResetRequestToLoginLogs extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE sys_login_logs MODIFY status ENUM('SUCCESS','FAILED','RESET_REQ') NOT NULL");
    }

    public function down(): void
    {
        $this->execute("DELETE FROM sys_login_logs WHERE status = 'RESET_REQ'");
        $this->execute("ALTER TABLE sys_login_logs MODIFY status ENUM('SUCCESS','FAILED') NOT NULL");
    }
}
