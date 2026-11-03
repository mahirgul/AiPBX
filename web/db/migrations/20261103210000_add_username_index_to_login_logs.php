<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Each successful sign-in now looks up the account's earlier addresses (LoginAlertService). */
final class AddUsernameIndexToLoginLogs extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE sys_login_logs ADD INDEX idx_username_status (username, status, created_at)');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE sys_login_logs DROP INDEX idx_username_status');
    }
}
