<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/FaxSendService.php';

/** The hourly limit stopped nothing on the live system, because there was none. */
final class FaxRefusalTest extends TestCase
{
    private const USER = 2000000001;

    protected function setUp(): void
    {
        $this->clean();
        setSystemSetting('fax_send_limit_per_hour', '2');
    }

    protected function tearDown(): void
    {
        $this->clean();
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key = 'fax_send_limit_per_hour'");
    }

    private function clean(): void
    {
        getDB()->prepare('DELETE FROM fax_sent WHERE user_id = ?')->execute([self::USER]);
        getDB()->prepare("DELETE FROM sys_audit_log WHERE user_id = ? AND entity_type = 'fax_sent'")->execute([self::USER]);
        getDB()->prepare("DELETE FROM sys_audit_log WHERE entity_type = 'fax_sent' AND entity_id = ?")->execute([(string) self::USER]);
    }

    private function sent(string $age = '0 MINUTE'): void
    {
        getDB()->prepare("INSERT INTO fax_sent (user_id, sender_extension, destination_number, pdf_path, tif_path, status, created_at)
            VALUES (?, '9000', '012345', '/x.pdf', '/x.tif', 'FAILED', NOW() - INTERVAL {$age})")->execute([self::USER]);
    }

    public function testBelowTheLimitTheFaxGoes(): void
    {
        $this->sent();
        $this->assertNull(FaxSendService::refusalFor('012345', '9000', self::USER, 'fax_user'));
    }

    public function testAtTheLimitTheFaxIsRefused(): void
    {
        $this->sent();
        $this->sent();
        $this->assertStringContainsString('2', (string) FaxSendService::refusalFor('012345', '9000', self::USER, 'fax_user'));
    }

    public function testAnOldFaxDoesNotCount(): void
    {
        $this->sent('2 HOUR');
        $this->sent('2 HOUR');
        $this->assertNull(FaxSendService::refusalFor('012345', '9000', self::USER, 'fax_user'));
    }

    public function testDeletingTheHistoryDoesNotFreeTheLimit(): void
    {
        $this->sent();
        getDB()->prepare("INSERT INTO sys_audit_log (entity_type, entity_id, entity_label, action, user_id, created_at)
            VALUES ('fax_sent', '1', 'x', 'delete', ?, NOW())")->execute([self::USER]);
        $this->assertNotNull(FaxSendService::refusalFor('012345', '9000', self::USER, 'fax_user'));
    }

    public function testAdministratorsAreNotLimited(): void
    {
        $this->sent();
        $this->sent();
        $this->assertNull(FaxSendService::refusalFor('012345', '9000', self::USER, 'admin'));
    }

    public function testZeroTurnsTheLimitOff(): void
    {
        setSystemSetting('fax_send_limit_per_hour', '0');
        $this->sent();
        $this->sent();
        $this->assertNull(FaxSendService::refusalFor('012345', '9000', self::USER, 'fax_user'));
    }
}
