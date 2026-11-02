<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/src/audit_log.php';
require_once dirname(__DIR__, 2) . '/src/services/SsoOnlyService.php';
require_once dirname(__DIR__, 2) . '/src/services/SamlAuthService.php';

final class SsoOnlyTest extends TestCase
{
    protected function tearDown(): void
    {
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key IN ('auth_sso_only', 'auth_sso_provider', 'auth_sso_recovery')");
        unset($_SESSION['sso_recovery_until'], $_SESSION['auth_method']);
    }

    public function testIfTheProviderIsNotEnabledLocalSignInStaysOpen(): void
    {
        getDB()->exec("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('auth_sso_only', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
        $this->assertFalse(SsoOnlyService::isActive(), 'a disabled provider must not lock everyone out');
        $this->assertFalse(SsoOnlyService::localSignInBlocked());
    }

    public function testItCannotBeTurnedOnWithoutAWorkingProvider(): void
    {
        $_SESSION['csrf_token'] = 'x';
        $result = SsoOnlyService::saveSettings(['csrf_token' => 'x', 'auth_sso_only' => '1', 'auth_sso_provider' => 'saml']);
        $this->assertFalse($result['success']);
        $this->assertSame('0', getSystemSetting('auth_sso_only', '0'));
    }

    public function testARecoveryLinkWorksOnce(): void
    {
        $token = SsoOnlyService::createRecoveryToken();
        $this->assertFalse(SsoOnlyService::useRecoveryToken('wrong'));
        $this->assertFalse(SsoOnlyService::inRecovery());
        $this->assertTrue(SsoOnlyService::useRecoveryToken($token));
        $this->assertTrue(SsoOnlyService::inRecovery());
        unset($_SESSION['sso_recovery_until']);
        $this->assertFalse(SsoOnlyService::useRecoveryToken($token), 'used once');
        $this->assertFalse(SsoOnlyService::inRecovery());
    }

    public function testAnExpiredRecoveryLinkFails(): void
    {
        $token = bin2hex(random_bytes(32));
        getDB()->prepare("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('auth_sso_recovery', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([hash('sha256', $token) . ':' . (time() - 1)]);
        $this->assertFalse(SsoOnlyService::useRecoveryToken($token));
    }

    public function testTheTokenIsNotStored(): void
    {
        $token = SsoOnlyService::createRecoveryToken();
        $stored = (string) getDB()->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'auth_sso_recovery'")->fetchColumn();
        $this->assertStringNotContainsString($token, $stored);
    }
}
