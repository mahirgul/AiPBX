<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/services/MailSettingsService.php';
require_once __DIR__ . '/../../src/services/FaxMailSettingsService.php';
require_once __DIR__ . '/../../src/services/MailTemplateService.php';

/**
 * The sender address goes to sendmail as "-f <address>": it was never checked,
 * so a space in it added sendmail options of its own.
 */
final class MailSenderAddressTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        $_SESSION['csrf_token'] = 'mail';
        foreach (['mail_from_address', 'fax_email_from_address'] as $k) {
            $this->saved[$k] = getSystemSetting($k, '');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            setSystemSetting($k, $v);
        }
    }

    public function testInvalidSenderIsRefusedOnSave(): void
    {
        $res = MailSettingsService::saveSettings(['csrf_token' => 'mail', 'mail_from_address' => 'pbx@example.com -X/tmp/x']);
        $this->assertFalse($res['success']);
        $res = FaxMailSettingsService::saveSettings(['csrf_token' => 'mail', 'fax_email_from_address' => 'fax@example.com -f x']);
        $this->assertFalse($res['success']);
    }

    public function testAStoredInvalidAddressNeverReachesSendmail(): void
    {
        setSystemSetting('mail_from_address', 'pbx@example.com -X/tmp/x');
        [$addr] = MailTemplateService::sender('portal');
        $this->assertSame('no-reply@example.com', $addr);

        setSystemSetting('mail_from_address', 'pbx@example.com');
        $this->assertSame('pbx@example.com', MailTemplateService::sender('portal')[0]);
    }
}
