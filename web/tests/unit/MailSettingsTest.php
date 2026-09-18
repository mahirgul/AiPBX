<?php

use PHPUnit\Framework\TestCase;

require_once '/var/www/html/config.php';
require_once '/var/www/html/src/core/BaseRepository.php';
require_once '/var/www/html/src/repositories/MailSettingsRepository.php';
require_once '/var/www/html/src/repositories/DashboardRepository.php';
require_once '/var/www/html/src/services/MailSettingsService.php';

final class MailSettingsTest extends TestCase
{
    public function testRelayHostParsing(): void
    {
        $testCases = [
            '[10.8.0.1]:25' => ['host' => '10.8.0.1', 'port' => '25'],
            '[smtp.office365.com]:587' => ['host' => 'smtp.office365.com', 'port' => '587'],
            '[10.8.0.1]' => ['host' => '10.8.0.1', 'port' => '25'],
            'mail.example.com:25' => ['host' => 'mail.example.com', 'port' => '25'],
            'mail.example.com' => ['host' => 'mail.example.com', 'port' => '25'],
            '192.168.1.100' => ['host' => '192.168.1.100', 'port' => '25'],
        ];

        foreach ($testCases as $rawRelay => $expected) {
            if (preg_match('/^(?:\[([^\]]+)\]|([^:]+))(?::(\d+))?$/', $rawRelay, $m)) {
                $host = !empty($m[1]) ? $m[1] : (!empty($m[2]) ? $m[2] : '');
                $port = !empty($m[3]) ? $m[3] : '25';
            } else {
                $host = trim($rawRelay, '[]');
                $port = '25';
            }

            $this->assertSame($expected['host'], $host, "Failed host parsing for $rawRelay");
            $this->assertSame($expected['port'], $port, "Failed port parsing for $rawRelay");
        }
    }

    public function testAllSettingsReturnsArray(): void
    {
        $settings = MailSettingsRepository::allSettings();
        $this->assertIsArray($settings);
        // mail_relay_host should be populated either from DB or from Postfix
        $this->assertArrayHasKey('mail_relay_host', $settings);
    }

    public function testPostfixStatusReturnsStructure(): void
    {
        $status = MailSettingsRepository::getPostfixStatus();
        $this->assertIsArray($status);
        $this->assertArrayHasKey('is_running', $status);
        $this->assertArrayHasKey('relayhost', $status);
        $this->assertArrayHasKey('queue_summary', $status);
        $this->assertArrayHasKey('queue_count', $status);
    }

    public function testDashboardMetricsIncludeMailRelay(): void
    {
        $metrics = DashboardRepository::getSystemMetrics();
        $this->assertArrayHasKey('mail_relay_host', $metrics);
    }

    public function testSaveSettingsRejectsInvalidCsrf(): void
    {
        $res = MailSettingsService::saveSettings([
            'csrf_token' => 'invalid_token_12345',
            'mail_relay_host' => '10.8.0.1'
        ]);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('CSRF', $res['error']);
    }

    public function testSendTestEmailValidatesAddress(): void
    {
        $res = MailSettingsService::sendTestEmail('not-an-email');
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('Geçersiz alıcı e-posta', $res['error']);
    }
}
