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
    /**
     * Every test starts from the Turkey (+90) defaults: the rows left by the
     * migrations depend on the installation's country, and other tests load
     * other countries' defaults.
     */
    protected function setUp(): void
    {
        $_SESSION['csrf_token'] = 'test-token';
        $res = DialplanRuleService::loadDefaults('test-token', '90');
        $this->assertTrue($res['success'] ?? false, $res['error'] ?? '');
    }

    public function testAsteriskPatternToRegex(): void
    {
        $this->assertSame('/^\\+90.+$/', DialplanRuleRepository::asteriskPatternToRegex('_+90.'));
        $this->assertSame('/^00[1-9].+$/', DialplanRuleRepository::asteriskPatternToRegex('_00[1-9].'));
        $this->assertSame('/^5[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]$/', DialplanRuleRepository::asteriskPatternToRegex('_5XXXXXXXXX'));
        $this->assertSame('/^9.+$/', DialplanRuleRepository::asteriskPatternToRegex('_9.'));
    }

    public function testDesktopInternationalNumberIsNotStrippedByDefault(): void
    {
        // AiPBX's international format is 00 + country code: the "strip the
        // outside-line 0" rule is loaded switched off, 0049... stays 0049...
        $res = DialplanRuleRepository::simulate('00493012345678', 'desktop');
        $this->assertFalse($res['matched'], 'Desktop international 0049... must not be rewritten by default');
        $this->assertSame('00493012345678', $res['normalized']);
    }

    public function testDesktopTrunkZeroNormalizationWhenSwitchedOn(): void
    {
        getDB()->exec("UPDATE pbx_dialplan_rules SET is_active = 1 WHERE client_type = 'desktop' AND match_pattern = '_00[1-9].'");
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

        getDB()->exec("DELETE FROM pbx_dialplan_rules WHERE match_pattern = '_9.'");

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

    public function testSwitchedOffWritesNoRulesButKeepsContexts(): void
    {
        $_SESSION['csrf_token'] = 'test-token';
        $off = DialplanRuleService::setEnabled('test-token', false);
        $this->assertTrue($off['success'] ?? false, $off['error'] ?? '');
        try {
            $this->assertFalse(DialplanRuleService::isEnabled());
            syncOutboundDialplan();
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');
            $this->assertStringContainsString('[from-internal-mobile-1]', $conf);
            $this->assertStringContainsString('[sub-norm-mobile-1]', $conf);
            $this->assertStringNotContainsString('Pre-filter [mobile] rule', $conf);
            $this->assertFalse(DialplanRuleRepository::simulateAll('+12125551234')['enabled']);
        } finally {
            DialplanRuleService::setEnabled('test-token', true);
        }
        $this->assertTrue(DialplanRuleService::isEnabled());
        syncOutboundDialplan();
        $this->assertStringContainsString('Pre-filter [mobile] rule', (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf'));
    }

    /**
     * Presets follow the country's dialling plan (country_calling_codes.json):
     * national prefix 1 / none / 8, international prefix 011 / 00 / 810.
     *
     * @return array<string, array{string, string, string, string}>
     */
    public static function dialPlanCases(): array
    {
        return [
            'US national'      => ['1', 'mobile', '+12125551234', '12125551234'],
            'US international' => ['1', 'mobile', '+442071234567', '011442071234567'],
            'Italy national'   => ['39', 'mobile', '+390612345678', '0612345678'],
            'Italy intl'       => ['39', 'web', '+12125551234', '0012125551234'],
            'Russia national'  => ['7', 'desktop', '+74951234567', '84951234567'],
            'Russia intl'      => ['7', 'desktop', '+905321234567', '810905321234567'],
            'Japan intl'       => ['81', 'mobile', '+12125551234', '01012125551234'],
            'Turkey national'  => ['90', 'web', '+902121234567', '02121234567'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dialPlanCases')]
    public function testPresetsFollowCountryDialPlan(string $cc, string $device, string $dialled, string $expected): void
    {
        $res = DialplanRuleService::loadDefaults('test-token', $cc);
        $this->assertTrue($res['success'] ?? false, $res['error'] ?? '');
        $sim = DialplanRuleRepository::simulate($dialled, $device);
        $this->assertTrue($sim['matched'], "$dialled should match a $cc preset");
        $this->assertSame($expected, $sim['normalized']);
    }

    public function testNoPresetsForUnknownDialPlan(): void
    {
        $this->assertNull(DialplanRuleService::getDialPlan('55'));
        $this->assertSame([], DialplanRuleService::getDefaultPresets('55'));
        $res = DialplanRuleService::loadDefaults('test-token', '55');
        $this->assertFalse($res['success'] ?? true, 'Brazil has no known plan: nothing must be loaded');
        // Outside-line 0 rules only for 0/00 countries
        $usPatterns = array_column(DialplanRuleService::getDefaultPresets('1'), 2);
        $this->assertNotContains('_000.', $usPatterns);
        $this->assertContains('_000.', array_column(DialplanRuleService::getDefaultPresets('49'), 2));
    }

    public function testPresetsAreEnglish(): void
    {
        foreach (DialplanRuleService::getDefaultPresets('90') as $p) {
            $this->assertDoesNotMatchRegularExpression('/[çğıöşüÇĞİÖŞÜ]/u', $p[1] . ' ' . $p[9], 'Preset texts must be English');
        }
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

    public function testWebNormalizationRules(): void
    {
        $resTr = DialplanRuleRepository::simulate('+905379902352', 'web');
        $this->assertTrue($resTr['matched']);
        $this->assertSame('05379902352', $resTr['normalized']);

        $resIntl = DialplanRuleRepository::simulate('+12125551234', 'web');
        $this->assertTrue($resIntl['matched']);
        $this->assertSame('0012125551234', $resIntl['normalized']);

        $res10 = DialplanRuleRepository::simulate('5379902352', 'web');
        $this->assertTrue($res10['matched']);
        $this->assertSame('05379902352', $res10['normalized']);
    }

    public function testPatternSpecificityScoring(): void
    {
        $scoreExact = DialplanRuleRepository::calculatePatternSpecificity('_+90.');
        $scoreGeneric = DialplanRuleRepository::calculatePatternSpecificity('_+X.');
        $this->assertGreaterThan($scoreGeneric, $scoreExact, '_+90. must be more specific than _+X.');

        // Verify simulation picks _+90. over _+X. for Turkish mobile
        $res = DialplanRuleRepository::simulate('+905321234567', 'mobile');
        $this->assertTrue($res['matched']);
        $this->assertSame('_+90.', $res['rule']['match_pattern']);
        $this->assertSame('05321234567', $res['normalized']);
    }

    public function testPatternValidationRejectsInvalidPatterns(): void
    {
        $resEmpty = DialplanRuleService::validateRule(['rule_name' => '', 'match_pattern' => '_']);
        $this->assertFalse($resEmpty['valid']);

        $resBare = DialplanRuleService::validateRule(['rule_name' => 'Bare Underscore', 'match_pattern' => '_']);
        $this->assertFalse($resBare['valid']);

        $resUnbalanced = DialplanRuleService::validateRule(['rule_name' => 'Bad Brackets', 'match_pattern' => '_00[1-9']);
        $this->assertFalse($resUnbalanced['valid']);

        $resEmptyBrackets = DialplanRuleService::validateRule(['rule_name' => 'Empty Brackets', 'match_pattern' => '_00[].']);
        $this->assertFalse($resEmptyBrackets['valid']);

        $resBadChars = DialplanRuleService::validateRule(['rule_name' => 'Bad Chars', 'match_pattern' => '_00@abc!']);
        $this->assertFalse($resBadChars['valid']);

        $resBadStrip = DialplanRuleService::validateRule(['rule_name' => 'Bad Strip', 'match_pattern' => '_00.', 'strip_front' => 99]);
        $this->assertFalse($resBadStrip['valid']);

        $resGood = DialplanRuleService::validateRule(['rule_name' => 'Good Rule', 'match_pattern' => '_00[1-9].', 'strip_front' => 1]);
        $this->assertTrue($resGood['valid']);
    }

    public function testLoadDefaultsDmlSafety(): void
    {
        $token = 'test-token';
        $_SESSION['csrf_token'] = $token;

        // No country, no presets (there is no Turkey default any more)
        $none = DialplanRuleService::loadDefaults($token, '');
        $this->assertFalse($none['success'] ?? true);
        $this->assertSame([], DialplanRuleService::getDefaultPresets(''));

        $res = DialplanRuleService::loadDefaults($token, '90');
        $this->assertTrue($res['success'] ?? false, $res['error'] ?? '');

        // Verify all 3 client types have default presets loaded
        $desktop = DialplanRuleRepository::allActiveOrdered('desktop');
        $mobile = DialplanRuleRepository::allActiveOrdered('mobile');
        $web = DialplanRuleRepository::allActiveOrdered('web');

        $this->assertNotEmpty($desktop, 'Desktop presets should be loaded');
        $this->assertNotEmpty($mobile, 'Mobile presets should be loaded');
        $this->assertNotEmpty($web, 'Web presets should be loaded');
    }

    public function testOutboundGroup2ContextIsolation(): void
    {
        $db = getDB();
        $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, extension, sip_password, sip_auth_digest, extension_type, is_active, role, outbound_group) VALUES ('testnormgrp2', '', 'Test Norm Grp2', '8999', 'Pass8999!', 1, 'sip', 1, 'user', 2) ON DUPLICATE KEY UPDATE outbound_group = 2, is_active = 1")->execute();

        try {
            syncAllExtensions();
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/pjsip_endpoints.conf');

            $this->assertStringContainsString('context=from-internal-desktop-2', $conf);
            $this->assertStringContainsString('context=from-internal-mobile-2', $conf);
            $this->assertStringContainsString('context=from-internal-web-2', $conf);
        } finally {
            $db->prepare("DELETE FROM sys_users WHERE extension = '8999'")->execute();
            syncAllExtensions();
        }
    }

    public function testCallForwardingPreservesPlusAndRoutesToIngress(): void
    {
        require_once dirname(__DIR__, 2) . '/src/sync/DialplanExtensionBuilder.php';

        $db = getDB();
        $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, extension, sip_password, sip_auth_digest, extension_type, is_active, role, call_forward_number, outbound_group) VALUES ('testcf1', '', 'Test CF 1', '8998', 'Pass8998!', 1, 'sip', 1, 'user', '+905321112233', 1) ON DUPLICATE KEY UPDATE call_forward_number = '+905321112233', outbound_group = 1, is_active = 1")->execute();

        try {
            $lines = \buildDndCfCheckLines('8998', $db);
            $this->assertTrue($lines['terminal']);
            $joined = implode("\n", $lines['lines']);
            $this->assertStringContainsString('Goto(from-internal-desktop,+905321112233,1)', $joined);
        } finally {
            $db->prepare("DELETE FROM sys_users WHERE extension = '8998'")->execute();
        }
    }

    public function testSimulateAllClients(): void
    {
        $res = DialplanRuleRepository::simulateAll('+905379902352');
        $this->assertArrayHasKey('desktop', $res);
        $this->assertArrayHasKey('mobile', $res);
        $this->assertArrayHasKey('web', $res);

        // All three client types match +905379902352 -> 05379902352 via _+90. preset
        $this->assertTrue($res['desktop']['matched']);
        $this->assertSame('05379902352', $res['desktop']['normalized']);
        $this->assertSame('desktop', $res['desktop']['client_type']);

        $this->assertTrue($res['mobile']['matched']);
        $this->assertSame('05379902352', $res['mobile']['normalized']);
        $this->assertSame('mobile', $res['mobile']['client_type']);

        $this->assertTrue($res['web']['matched']);
        $this->assertSame('05379902352', $res['web']['normalized']);
        $this->assertSame('web', $res['web']['client_type']);

        // An unmatched number (e.g., standard internal extension 1001) passes through unmatched on all 3
        $resExt = DialplanRuleRepository::simulateAll('1001');
        $this->assertFalse($resExt['desktop']['matched']);
        $this->assertSame('1001', $resExt['desktop']['normalized']);
        $this->assertFalse($resExt['mobile']['matched']);
        $this->assertSame('1001', $resExt['mobile']['normalized']);
        $this->assertFalse($resExt['web']['matched']);
        $this->assertSame('1001', $resExt['web']['normalized']);
    }

    public function testLoadDefaultsCustomCountryCode(): void
    {
        $token = 'test-token';
        $_SESSION['csrf_token'] = $token;

        // Test Country Calling Codes Dataset
        $countries = DialplanRuleService::getAllCountries();
        $this->assertNotEmpty($countries);
        $this->assertArrayHasKey('43', $countries);
        $this->assertArrayHasKey('90', $countries);
        $this->assertArrayHasKey('49', $countries);

        $atInfo = DialplanRuleService::getCountryInfo('43');
        $this->assertSame('Avusturya', $atInfo['name_tr']);
        $this->assertSame('Austria', $atInfo['name_en']);
        $this->assertSame('AT', $atInfo['iso2']);
        $this->assertSame('🇦🇹', $atInfo['flag']);

        // Load Austria presets (country code 43)
        $resAt = DialplanRuleService::loadDefaults($token, '43');
        $this->assertTrue($resAt['success'] ?? false, $resAt['error'] ?? '');
        $this->assertSame('43', getSystemSetting('dialplan_rules_country_code'));

        $atMobile = DialplanRuleRepository::allActiveOrdered('mobile');
        $foundAt = false;
        foreach ($atMobile as $rule) {
            if ($rule['match_pattern'] === '_+43.') {
                $foundAt = true;
                $this->assertSame(3, (int)$rule['strip_front']);
                $this->assertSame('0', $rule['prepend']);
                $this->assertStringContainsString('Austria', $rule['rule_name']);
            }
        }
        $this->assertTrue($foundAt, 'Mobile should have _+43. Austria rule');

        // Test simulation for Austria number
        $simAt = DialplanRuleRepository::simulateAll('+436641234567');
        $this->assertTrue($simAt['mobile']['matched']);
        $this->assertSame('06641234567', $simAt['mobile']['normalized']);
        $this->assertTrue($simAt['web']['matched']);
        $this->assertSame('06641234567', $simAt['web']['normalized']);

        // Load German presets (country code 49)
        $res = DialplanRuleService::loadDefaults($token, '49');
        $this->assertTrue($res['success'] ?? false, $res['error'] ?? '');

        // Verify sys_settings saved '49'
        $this->assertSame('49', getSystemSetting('dialplan_rules_country_code'));

        // Verify Germany rule was created for mobile and web
        $mobileRules = DialplanRuleRepository::allActiveOrdered('mobile');
        $foundDe = false;
        foreach ($mobileRules as $rule) {
            if ($rule['match_pattern'] === '_+49.') {
                $foundDe = true;
                $this->assertSame(3, (int)$rule['strip_front']); // '+' and '49' = 3
                $this->assertSame('0', $rule['prepend']);
                $this->assertStringContainsString('Germany', $rule['rule_name']);
            }
        }
        $this->assertTrue($foundDe, 'Mobile should have _+49. rule with strip 3 and prepend 0');

        // Test simulation for Germany number
        $simAll = DialplanRuleRepository::simulateAll('+491701234567');
        $this->assertTrue($simAll['mobile']['matched']);
        $this->assertSame('01701234567', $simAll['mobile']['normalized']);
        $this->assertTrue($simAll['web']['matched']);
        $this->assertSame('01701234567', $simAll['web']['normalized']);

        // Restore back to default Turkey (90)
        $restore = DialplanRuleService::loadDefaults($token, '90');
        $this->assertTrue($restore['success'] ?? false, $restore['error'] ?? '');
        $this->assertSame('90', getSystemSetting('dialplan_rules_country_code'));
    }
}
