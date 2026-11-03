<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

class LoginAlertTest extends TestCase
{
    public function testSameNetworkIsPerSubnet(): void
    {
        $this->assertTrue(LoginAlertService::sameNetwork('203.0.113.25', '203.0.113.200'));
        $this->assertFalse(LoginAlertService::sameNetwork('203.0.113.25', '203.0.114.25'));
        $this->assertTrue(LoginAlertService::sameNetwork('2001:db8:1:2::5', '2001:db8:1:2:ffff::9'));
        $this->assertFalse(LoginAlertService::sameNetwork('2001:db8:1:2::5', '2001:db8:1:3::5'));
        $this->assertFalse(LoginAlertService::sameNetwork('203.0.113.25', '2001:db8::1'));
        $this->assertFalse(LoginAlertService::sameNetwork('nonsense', '203.0.113.25'));
    }

    public function testOnlyAKnownAccountOnANewInternetNetworkCounts(): void
    {
        $known = ['198.51.100.7', '2001:db8:1:2::5'];
        $this->assertTrue(LoginAlertService::isNewNetwork('8.8.8.8', $known));
        $this->assertFalse(LoginAlertService::isNewNetwork('8.8.8.8', []), 'first sign-in');
        $this->assertFalse(LoginAlertService::isNewNetwork('8.8.8.8', ['8.8.8.9']), 'same /24');
        $this->assertFalse(LoginAlertService::isNewNetwork('10.1.2.3', $known), 'private address');
        $this->assertFalse(LoginAlertService::isNewNetwork('127.0.0.1', $known), 'loopback');
    }

    public function testTemplateCarriesTheFactsAndEscapesTheBrowser(): void
    {
        $m = MailTemplateService::render('login_new_ip', 'en', [
            'name' => 'N', 'username' => 'u1', 'ip' => '8.8.8.8', 'date' => 'D1',
            'browser' => '<script>alert(1)</script>', 'portal_link' => 'https://pbx.example.com',
        ]);
        $this->assertStringContainsString('8.8.8.8', $m['html']);
        $this->assertStringNotContainsString('<script>alert', $m['html']);
    }

    public function testSignInStillWorksAndLogsWhenTheAlertRuns(): void
    {
        $db = getDB();
        $name = 'zz_alert_' . bin2hex(random_bytes(3));
        try {
            logLoginAttempt('8.8.4.4', $name, 'SUCCESS');
            logLoginAttempt('1.1.1.1', $name, 'SUCCESS'); // new network, but no such user: no mail, no error
            $st = $db->prepare("SELECT COUNT(*) FROM sys_login_logs WHERE username = ? AND status = 'SUCCESS'");
            $st->execute([$name]);
            $this->assertSame(2, (int) $st->fetchColumn());
        } finally {
            $db->prepare('DELETE FROM sys_login_logs WHERE username = ?')->execute([$name]);
        }
    }

    public function testSwitchedOffByTheSetting(): void
    {
        $before = getSystemSetting(LoginAlertService::SETTING, '1');
        try {
            setSystemSetting(LoginAlertService::SETTING, '0');
            $this->assertFalse(LoginAlertService::enabled());
            setSystemSetting(LoginAlertService::SETTING, '1');
            $this->assertTrue(LoginAlertService::enabled());
        } finally {
            setSystemSetting(LoginAlertService::SETTING, (string) $before);
        }
    }
}
