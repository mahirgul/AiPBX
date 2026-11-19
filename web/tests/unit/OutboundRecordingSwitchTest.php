<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/services/PrivacyService.php';

/** Privacy → "Record outbound calls": on by default (the old behaviour); a user's own choice wins either way. */
final class OutboundRecordingSwitchTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::load();
        $_SESSION['csrf_token'] = 'rec';
    }

    protected function tearDown(): void
    {
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key = 'privacy_record_outbound'");
    }

    private function outbound(): string
    {
        syncOutboundDialplan();
        return (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');
    }

    public function testOnByDefaultAndOffWhenSwitchedOff(): void
    {
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key = 'privacy_record_outbound'");
        $this->assertStringContainsString('MixMonitor(${REC_FILE}', $this->outbound());

        $res = PrivacyService::saveNotice(['csrf_token' => 'rec', 'notice_mode' => 'off', 'record_outbound' => '0']);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        // Off: recorded only for a user who chose "always" (their endpoint sets AIPBX_REC_POLICY).
        $this->assertStringContainsString('"${AIPBX_REC_POLICY}" != "always"]?norec)', $this->outbound());

        $this->assertTrue(PrivacyService::saveNotice(['csrf_token' => 'rec', 'notice_mode' => 'off', 'record_outbound' => '1'])['success']);
        $on = $this->outbound();
        $this->assertStringContainsString('MixMonitor(${REC_FILE}', $on);
        // On: everyone but a user who chose "never".
        $this->assertStringContainsString('"${AIPBX_REC_POLICY}" = "never"]?norec)', $on);
    }

    public function testACallerWithoutTheFieldLeavesItAlone(): void
    {
        setSystemSetting('privacy_record_outbound', '0');
        PrivacyService::saveNotice(['csrf_token' => 'rec', 'notice_mode' => 'off']);
        $this->assertSame('0', getSystemSetting('privacy_record_outbound', '1'));
    }
}
