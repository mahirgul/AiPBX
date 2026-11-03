<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/src/sync/DialplanBuilders.php';
require_once dirname(__DIR__, 2) . '/src/services/TrunkService.php';
require_once dirname(__DIR__, 2) . '/src/sync/SyncOutboundDialplan.php';

final class TrunkCarrierNormalizationTest extends TestCase
{
    public function testBuildTrunkCarrierDialLineWithNoRules(): void
    {
        $line = buildTrunkCarrierDialLine(null);
        $this->assertStringContainsString('Set(AIPBX_TRUNK_DIAL=${AIPBX_DIAL_TARGET})', $line);

        $lineEmpty = buildTrunkCarrierDialLine(['strip' => '', 'add' => '']);
        $this->assertStringContainsString('Set(AIPBX_TRUNK_DIAL=${AIPBX_DIAL_TARGET})', $lineEmpty);
    }

    public function testARoutePrefixKeepsALeadingPlusOnly(): void
    {
        $this->assertSame('+1', cleanRoutePrefix(' +1 '));
        $this->assertSame('+12', cleanRoutePrefix('+1+2'));
        $this->assertSame('12', cleanRoutePrefix('1+2'));
        $this->assertSame('0', cleanRoutePrefix('0'));
        $this->assertSame('', cleanRoutePrefix('x;Hangup()'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('e164Numbers')]
    public function testE164Number(string $cc, string $number, string $expected): void
    {
        $plan = DialplanRuleService::getDialPlan($cc);
        $this->assertNotNull($plan);
        $this->assertSame($expected, e164DialNumber($number, $cc, $plan['trunk'], $plan['intl']));
    }

    /** @return array<string, array{string, string, string}> */
    public static function e164Numbers(): array
    {
        return [
            'tr national' => ['90', '05321234567', '+905321234567'],
            'tr without the zero' => ['90', '5321234567', '+905321234567'],
            'tr already with the code' => ['90', '905321234567', '+905321234567'],
            'tr international' => ['90', '00493012345678', '+493012345678'],
            'already e164' => ['90', '+905321234567', '+905321234567'],
            'tr emergency' => ['90', '112', '112'],
            'us ten digits' => ['1', '4155550100', '+14155550100'],
            'us with the one' => ['1', '14155550100', '+14155550100'],
            'us international' => ['1', '011905321234567', '+905321234567'],
            'us emergency' => ['1', '911', '911'],
            'italy keeps its zero' => ['39', '0612345678', '+390612345678'],
            'russia national' => ['7', '84951234567', '+74951234567'],
            'russia international' => ['7', '81049301234567', '+49301234567'],
            'service code' => ['90', '*21*1000', '*21*1000'],
        ];
    }

    public function testARouteCallerIdOverrideKeepsItsPlus(): void
    {
        $line = buildTrunkCallerIdLine(['trunk_name' => '', 'callerid_override' => '+43123456'], false);
        $this->assertStringContainsString('+43123456', $line);
    }

    /** An override that lost to the trunk's number and the user's was no override. */
    public function testARouteOverrideBeatsTheUserAndTheTrunk(): void
    {
        getDB()->prepare("INSERT INTO pbx_trunks (trunk_name, title, ip_address, outbound_caller_id) VALUES ('zz_ov_trunk', 'zz', '127.0.0.1', '905550000001')")->execute();
        try {
            $line = buildTrunkCallerIdLine(['trunk_name' => 'zz_ov_trunk', 'callerid_override' => '905550000002'], false);
            $this->assertStringContainsString('Set(CALLERID(num)=905550000002)', $line);
            $this->assertStringNotContainsString('905550000001', $line, 'the trunk number must not be used');
            $this->assertStringNotContainsString('CID_EXTERNAL', $line, 'the user number must not be used');
            $this->assertStringContainsString('CDR(inbound_trunk)', $line, 'a transit call keeps its caller');

            $without = buildTrunkCallerIdLine(['trunk_name' => 'zz_ov_trunk', 'callerid_override' => ''], false);
            $this->assertStringContainsString('CID_EXTERNAL', $without);
            $this->assertStringContainsString('905550000001', $without);
        } finally {
            getDB()->exec("DELETE FROM pbx_trunks WHERE trunk_name = 'zz_ov_trunk'");
        }
    }

    public function testATrunkWithACountrySendsTheNumberThroughTheSubroutine(): void
    {
        $line = buildTrunkCarrierDialLine(['strip' => '', 'add' => '', 'e164' => ['cc' => '1', 'trunk' => '1', 'intl' => '011']]);
        $this->assertStringContainsString('Gosub(sub-e164,s,1(${AIPBX_TRUNK_DIAL},1,1,011))', $line);
        $this->assertStringContainsString('Set(AIPBX_TRUNK_DIAL=${GOSUB_RETVAL})', $line);
        $this->assertStringContainsString('[sub-e164]', buildE164Subroutine());
    }

    public function testAnUnknownCountryIsNoPlan(): void
    {
        $this->assertNull(trunkE164Plan(''));
        $this->assertNull(trunkE164Plan('999'));
        $this->assertSame(['cc' => '90', 'trunk' => '0', 'intl' => '00'], trunkE164Plan('90'));
    }

    public function testBuildTrunkCarrierDialLineWithStripAndAdd(): void
    {
        // Strip '+90' (length 3) and prepend '0'
        $line = buildTrunkCarrierDialLine(['strip' => '+90', 'add' => '0']);
        $this->assertStringContainsString('Set(AIPBX_TRUNK_DIAL=${AIPBX_DIAL_TARGET})', $line);
        $this->assertStringContainsString('ExecIf($["${AIPBX_TRUNK_DIAL:0:3}" = "+90"]?Set(AIPBX_TRUNK_DIAL=0${AIPBX_TRUNK_DIAL:3}))', $line);
    }

    public function testBuildTrunkCarrierDialLineWithAddOnly(): void
    {
        // Prepend '00' without stripping
        $line = buildTrunkCarrierDialLine(['strip' => '', 'add' => '00']);
        $this->assertStringContainsString('Set(AIPBX_TRUNK_DIAL=00${AIPBX_TRUNK_DIAL})', $line);
    }

    public function testBuildTrunkCarrierDialLineWithStripOnly(): void
    {
        // Strip '0' (length 1) without prepending anything
        $line = buildTrunkCarrierDialLine(['strip' => '0', 'add' => '']);
        $this->assertStringContainsString('ExecIf($["${AIPBX_TRUNK_DIAL:0:1}" = "0"]?Set(AIPBX_TRUNK_DIAL=${AIPBX_TRUNK_DIAL:1}))', $line);
    }

    public function testSaveTrunkPersistsCarrierNormAnd100rel(): void
    {
        $csrf = 'carrier-norm-test-csrf';
        $_SESSION['csrf_token'] = $csrf;

        $trunkName = 'test_carrier_trk';
        $db = getDB();
        $db->exec("DELETE FROM pbx_trunks WHERE trunk_name = '{$trunkName}'");

        $res = TrunkService::saveTrunk([
            'csrf_token' => $csrf,
            'trunk_id' => 0,
            'trunk_name' => $trunkName,
            'title' => 'Test Carrier Trunk',
            'ip_address' => '192.168.100.50',
            'port' => 5060,
            'transport' => 'udp',
            'carrier_strip_prefix' => '+90',
            'carrier_add_prefix' => '0',
            'prack_100rel' => 'required',
            'dtmf_mode' => 'rfc4733',
            'timers' => 'yes',
            'is_active' => 1,
        ]);

        $this->assertTrue($res['success'], 'Save trunk failed: ' . ($res['message'] ?? ''));
        $row = $db->query("SELECT * FROM pbx_trunks WHERE trunk_name = '{$trunkName}'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotNull($row);
        $this->assertSame('+90', $row['carrier_strip_prefix']);
        $this->assertSame('0', $row['carrier_add_prefix']);
        $this->assertSame('required', $row['prack_100rel']);

        $db->exec("DELETE FROM pbx_trunks WHERE trunk_name = '{$trunkName}'");
    }

    public function testNormalizationPreFilterStartsEveryBlockWithItsExten(): void
    {
        $conf = buildDialplanNormalizationRules(1, [[
            'is_active' => 1, 'client_type' => 'all', 'rule_name' => 'TR mobile',
            'match_pattern' => '_05XXXXXXXXX', 'strip_front' => 1, 'prepend' => '90',
        ]]);
        // A "same =>" line right after a context header or a comment has no extension (Asterisk warns, line lost).
        $this->assertDoesNotMatchRegularExpression('/^(\[[^\]]+\]|;[^\n]*)\n same =>/m', $conf);
        $this->assertStringContainsString('Goto(from-internal-outbound-1,90${EXTEN:1},1)', $conf);
    }

    public function testSessionTimersOnlyValuesAsteriskAccepts(): void
    {
        // Asterisk refuses the whole endpoint on an unknown value (the trunk is gone).
        $this->assertSame('no', TrunkService::timersValue('never'));
        $this->assertSame('always', TrunkService::timersValue('Always'));
        $this->assertSame('required', TrunkService::timersValue('required'));
        $this->assertSame('yes', TrunkService::timersValue('bogus'));
    }

    public function testFallbackRouteWithoutRoutesStillChecksPermissionsAndLimits(): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $db->exec('UPDATE pbx_outbound_routes SET is_active = 0');
            $db->exec('UPDATE pbx_trunks SET is_active = 0');
            $db->exec("INSERT INTO pbx_trunks (trunk_name, title, ip_address, is_active, max_channels, max_calls_per_minute, sort_order)
                       VALUES ('fbtest', 'FB test', '203.0.113.9', 1, 2, 5, -100)");
            syncOutboundDialplan();
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');
        } finally {
            $db->rollBack();
            syncOutboundDialplan();
        }
        $this->assertStringContainsString('exten => _05XXXXXXXXX,1,NoOp(Fallback Mobile Outbound Call)', $conf);
        $fb = substr($conf, strpos($conf, 'Fallback Outbound Call'));
        $this->assertStringContainsString('Gosub(sub-check-dial-permission,s,1(', $fb);
        $this->assertStringContainsString('Gosub(sub-trunk-limit,s,1(fbtest,2,5,trunk))', $fb);
        $this->assertStringContainsString('Dial(PJSIP/${AIPBX_TRUNK_DIAL}@fbtest,', $fb);
        $this->assertStringContainsString('n(refused),Goto(sub-outbound-status,CONGESTION,1)', $fb);
    }
}
