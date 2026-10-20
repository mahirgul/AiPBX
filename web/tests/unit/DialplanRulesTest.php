<?php

declare(strict_types=1);

namespace AiPBX\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DialplanRuleRepository;
use DialplanRuleService;

require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/src/repositories/DialplanRuleRepository.php';
require_once dirname(__DIR__, 2) . '/src/services/DialplanRuleService.php';
require_once dirname(__DIR__, 2) . '/src/sync/SyncOutboundDialplan.php';
require_once dirname(__DIR__, 2) . '/src/sync/SyncExtensions.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

final class DialplanRulesTest extends TestCase
{
    public function testAsteriskPatternToRegex(): void
    {
        $this->assertSame('/^\\+90.+$/', DialplanRuleRepository::asteriskPatternToRegex('_+90.'));
        $this->assertSame('/^00[1-9].+$/', DialplanRuleRepository::asteriskPatternToRegex('_00[1-9].'));
        $this->assertSame('/^5[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]$/', DialplanRuleRepository::asteriskPatternToRegex('_5XXXXXXXXX'));
        $this->assertSame('/^9.+$/', DialplanRuleRepository::asteriskPatternToRegex('_9.'));
    }

    public function testDesktopTrunkZeroNormalization(): void
    {
        $res = DialplanRuleRepository::simulate('005379902352', 'desktop');
        $this->assertTrue($res['matched'], 'Desktop 005379902352 should match desktop trunk strip rule');
        $this->assertSame('05379902352', $res['normalized']);
    }

    public function testMobileE164TurkeyNormalization(): void
    {
        $res = DialplanRuleRepository::simulate('+905379902352', 'mobile');
        $this->assertTrue($res['matched'], 'Mobile +905379902352 should match Turkey E.164 rule');
        $this->assertSame('05379902352', $res['normalized']);
    }

    public function testMobileE164InternationalNormalization(): void
    {
        $res = DialplanRuleRepository::simulate('+12125551234', 'mobile');
        $this->assertTrue($res['matched'], 'Mobile +12125551234 should match generic + rule');
        $this->assertSame('0012125551234', $res['normalized']);
    }

    public function testMobile10DigitGsmNormalization(): void
    {
        $res = DialplanRuleRepository::simulate('5379902352', 'mobile');
        $this->assertTrue($res['matched'], 'Mobile 5379902352 should match 10-digit GSM rule');
        $this->assertSame('05379902352', $res['normalized']);
    }

    public function testDirectDialWithoutRulePassesThrough(): void
    {
        $res = DialplanRuleRepository::simulate('05379902352', 'mobile');
        $this->assertFalse($res['matched'], '05379902352 should pass through without modification');
        $this->assertSame('05379902352', $res['normalized']);
    }

    public function testDialplanRuleServiceCrud(): void
    {
        $token = 'test-token';
        $_SESSION['csrf_token'] = $token;

        // Create
        $saveRes = DialplanRuleService::saveRule([
            'csrf_token' => $token,
            'client_type' => 'desktop',
            'rule_name' => 'Unit Test Rule 9',
            'match_pattern' => '_9.',
            'strip_front' => 1,
            'prepend' => '',
            'is_active' => 1,
            'description' => 'Unit test rule',
        ]);
        $this->assertTrue($saveRes['success'] ?? false, $saveRes['error'] ?? '');
        $found = DialplanRuleRepository::findWhere("rule_name = 'Unit Test Rule 9'");
        $this->assertNotEmpty($found);
        $ruleId = (int)$found[0]['id'];
        $this->assertGreaterThan(0, $ruleId);

        // Toggle
        $togRes = DialplanRuleService::toggleStatus($ruleId, $token);
        $this->assertTrue($togRes['success'] ?? false, $togRes['error'] ?? '');
        $updated = DialplanRuleRepository::findById($ruleId);
        $this->assertNotNull($updated);
        $this->assertSame(0, intval($updated['is_active']));

        // Delete
        $delRes = DialplanRuleService::deleteRule($ruleId, $token);
        $this->assertTrue($delRes['success'] ?? false, $delRes['error'] ?? '');
        $this->assertNull(DialplanRuleRepository::findById($ruleId));
    }

    public function testSyncOutboundDialplanGeneratesIngressContexts(): void
    {
        syncOutboundDialplan();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');

        $this->assertStringContainsString('[sub-norm-desktop-1]', $conf);
        $this->assertStringContainsString('[sub-norm-mobile-1]', $conf);
        $this->assertStringContainsString('[from-internal-desktop-1]', $conf);
        $this->assertStringContainsString('[from-internal-mobile-1]', $conf);
        $this->assertStringContainsString('[from-internal-web-1]', $conf);
        $this->assertStringContainsString('[from-internal-desktop]', $conf);
        $this->assertStringContainsString('[from-internal-mobile]', $conf);
        $this->assertStringContainsString('[from-internal-web]', $conf);
    }

    public function testSyncExtensionsSetsClientTypeAndIngressContext(): void
    {
        $db = getDB();
        $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, extension, sip_password, sip_auth_digest, extension_type, is_active, role) VALUES ('testnorm1', '', 'Test Norm 1', '8001', 'Pass8001!', 1, 'sip', 1, 'user') ON DUPLICATE KEY UPDATE is_active = 1")->execute();

        try {
            syncAllExtensions();
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/pjsip_endpoints.conf');

            $this->assertStringContainsString('context=from-internal-desktop', $conf);
            $this->assertStringContainsString('context=from-internal-mobile', $conf);
            $this->assertStringContainsString('context=from-internal-web', $conf);
            $this->assertStringContainsString('set_var=AIPBX_CLIENT_TYPE=desktop', $conf);
            $this->assertStringContainsString('set_var=AIPBX_CLIENT_TYPE=mobile', $conf);
            $this->assertStringContainsString('set_var=AIPBX_CLIENT_TYPE=web', $conf);
        } finally {
            $db->prepare("DELETE FROM sys_users WHERE extension = '8001'")->execute();
        }
    }
}
