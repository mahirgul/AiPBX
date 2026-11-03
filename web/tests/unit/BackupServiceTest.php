<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/src/services/BackupService.php';

final class BackupServiceTest extends TestCase
{
    public function testOnlyTheNamesOfBackupsAreAccepted(): void
    {
        $this->assertTrue(BackupService::validName('aipbx-20261103-0230'));
        $this->assertTrue(BackupService::validName('aipbx-20261103-0230-before-restore'));
        foreach (['', '../../etc', 'aipbx-20261103-0230/../x', "aipbx-20261103-0230\n", 'aipbx-x', 'aipbx-20261103-0230-A b'] as $bad) {
            $this->assertFalse(BackupService::validName($bad), $bad);
        }
    }

    public function testTheListIsParsedNewestFirstAndSkipsWhatIsNotABackup(): void
    {
        $rows = BackupService::parseList("aipbx-20261101-0230\t100\t1000\t1.60.14\t0\n"
            . "../evil\t1\t1\t1\t1\n"
            . "aipbx-20261102-0230-before-restore\t200\t3000\t\t0\n"
            . "aipbx-20261102-0300-upload\t300\t2000\t1.60.13\t1\n");
        $this->assertSame(['aipbx-20261102-0230-before-restore', 'aipbx-20261102-0300-upload', 'aipbx-20261101-0230'], array_column($rows, 'name'));
        $this->assertTrue($rows[0]['safety']);
        $this->assertTrue($rows[1]['uploaded']);
    }

    public function testARestoreNeedsTheTypedNameAndThePassword(): void
    {
        $db = getDB();
        $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, role, is_active) VALUES (?, ?, 'Bk', 'admin', 1)")
            ->execute(['bk_test_' . bin2hex(random_bytes(4)), password_hash('Right-pass-1', PASSWORD_DEFAULT)]);
        $id = (int) $db->lastInsertId();
        try {
            $name = 'aipbx-20261103-0230';
            $this->assertFalse(BackupService::restore(['name' => '../x', 'confirm_name' => '../x'], $id)['success']);
            $wrongName = BackupService::restore(['name' => $name, 'confirm_name' => 'aipbx-20261103-0231', 'password' => 'Right-pass-1'], $id);
            $this->assertSame(t('backup.err_type'), $wrongName['error']);
            $wrongPass = BackupService::restore(['name' => $name, 'confirm_name' => $name, 'password' => 'nope'], $id);
            $this->assertSame(t('backup.err_password'), $wrongPass['error']);
        } finally {
            $db->prepare('DELETE FROM sys_users WHERE id = ?')->execute([$id]);
            unset($_SESSION['backup_password_failures']);
        }
    }

    public function testAnUploadTokenCannotBeAPath(): void
    {
        $this->assertSame(t('backup.err_upload_state'), BackupService::uploadFinish('../../etc/passwd')['error']);
        $this->assertSame(t('backup.err_upload_state'), BackupService::uploadChunk('../x', 0, [])['error']);
    }
}
