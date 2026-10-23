<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/CallQualityService.php';

/** Reports → Call quality: RTCP reports → call legs → problems of the health check. */
final class CallQualityServiceTest extends TestCase
{
    private const OWN = ['10.0.0.5'];

    protected function setUp(): void
    {
        getDB()->exec('DELETE FROM pbx_call_quality_legs');
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'call_quality_%'");
    }

    private function report(string $id, string $src, ?int $fraction, int $jitter = 0, string $ts = '2026-10-23 10:00:00'): array
    {
        return ['ts' => $ts, 'call_id' => $id, 'src' => $src, 'rtcp_fraction_lost' => $fraction, 'rtcp_jitter' => $jitter];
    }

    public function testDirectionComesFromWhoSentTheReport(): void
    {
        $legs = CallQualityService::aggregate([
            $this->report('a', '10.0.0.5:10000', 0, 3),          // we report what we received: in
            $this->report('a', '203.0.113.10:20000', 26, 9),     // they report what they received: out, ~10 %
            $this->report('a', '203.0.113.10:20000', 30, 4),
            $this->report('a', '203.0.113.10:20000', null),      // no counter: not a zero
        ], self::OWN, 5.0);
        $l = $legs['a'];
        $this->assertSame([1, 2], [$l['reports_in'], $l['reports_out']]);
        $this->assertSame([0, 2], [$l['bad_in'], $l['bad_out']]);
        $this->assertSame(11.72, $l['loss_out_max']);
        $this->assertSame('203.0.113.10', $l['peer']);
        $this->assertSame('poor', CallQualityService::level($l));
    }

    public function testLevels(): void
    {
        $base = ['bad_in' => 0, 'bad_out' => 0, 'loss_in_max' => 0, 'loss_out_max' => 0];
        $this->assertSame('good', CallQualityService::level($base));
        $this->assertSame('fair', CallQualityService::level(['loss_in_max' => 1.5] + $base));
        $this->assertSame('fair', CallQualityService::level(['bad_out' => 1, 'loss_out_max' => 9] + $base), 'one bad report is not enough');
        $this->assertSame('poor', CallQualityService::level(['bad_in' => 2] + $base));
    }

    public function testALegIsNamedFromItsInvite(): void
    {
        $trunks = ['VOIP' => ['203.0.113.10'], 'NEC' => ['10.12.0.0/16']];
        $exts = ['3001' => 0];
        $c = fn($src, $dst, $from, $to) => CallQualityService::classify(['src' => $src, 'dst' => $dst, 'from_uri' => $from, 'to_uri' => $to], self::OWN, $trunks, $exts);
        $this->assertSame(['trunk', 'VOIP'], $c('203.0.113.10:5060', '10.0.0.5:5060', '<sip:0532@x>', '<sip:3000@y>'));
        $this->assertSame(['trunk', 'NEC'], $c('10.0.0.5:5060', '10.12.3.4:5060', '<sip:3001@a>', '<sip:5123@b>'), 'CIDR of the match list');
        $this->assertSame(['extension', '3001'], $c('10.0.0.9:5060', '10.0.0.5:5060', '"Ali" <sip:3001@pbx>', '<sip:9000@pbx>'));
        $this->assertSame(['other', '2001:db8::1'], $c('[2001:db8::1]:5060', '10.0.0.5:5060', '<sip:777@a>', '<sip:888@b>'));
    }

    public function testProblemsNeedEnoughPoorLegsAndAFifthOfTheCalls(): void
    {
        $db = getDB();
        $ins = $db->prepare("INSERT INTO pbx_call_quality_legs (call_id, first_ts, last_ts, leg_type, leg_name, bad_out, loss_out_max, level)
            VALUES (?, NOW(), NOW(), 'trunk', ?, ?, ?, ?)");
        foreach (range(1, 3) as $i) {
            $ins->execute(["v$i", 'VOIP', 3, 12.5, 'poor']);
        }
        foreach (range(1, 2) as $i) {
            $ins->execute(["n$i", 'NEC', 3, 8, 'poor']);           // only two poor legs
        }
        foreach (range(1, 20) as $i) {
            $ins->execute(["b$i", 'BIG', 0, 0, 'good']);
        }
        foreach (range(1, 3) as $i) {
            $ins->execute(["bp$i", 'BIG', 2, 6, 'poor']);           // 3 of 23: under a fifth
        }
        $problems = CallQualityService::problems();
        $this->assertSame(['VOIP'], array_column($problems, 'leg_name'));
        $this->assertStringContainsString('VOIP', CallQualityService::alertTitle($problems[0]));
        $this->assertStringContainsString('12.5 %', CallQualityService::alertMessage($problems[0]));
        $this->assertStringContainsString(t('call_quality.where_to_carrier'), CallQualityService::alertMessage($problems[0]));
    }

    public function testOldLegsDoNotAlert(): void
    {
        getDB()->exec("INSERT INTO pbx_call_quality_legs (call_id, first_ts, last_ts, leg_type, leg_name, bad_in, level) VALUES
            ('o1', NOW() - INTERVAL 1 HOUR, NOW() - INTERVAL 1 HOUR, 'trunk', 'VOIP', 3, 'poor'),
            ('o2', NOW() - INTERVAL 1 HOUR, NOW() - INTERVAL 1 HOUR, 'trunk', 'VOIP', 3, 'poor'),
            ('o3', NOW() - INTERVAL 1 HOUR, NOW() - INTERVAL 1 HOUR, 'trunk', 'VOIP', 3, 'poor')");
        $this->assertSame([], CallQualityService::problems());
    }

    public function testSettingsAreClamped(): void
    {
        $_SESSION['csrf_token'] = 'cq-test';
        $res = CallQualityService::save(['csrf_token' => 'cq-test', 'poor_pct' => '90', 'alert_legs' => '0', 'keep_days' => '1']);
        $this->assertTrue($res['success']);
        $this->assertSame(['poor_pct' => 50.0, 'alert_legs' => 1, 'keep_days' => 7], CallQualityService::settings());
        $this->assertFalse(CallQualityService::save(['csrf_token' => 'wrong'])['success']);
    }

    public function testCidr(): void
    {
        $this->assertTrue(CallQualityService::inCidr('10.12.3.4', '10.12.0.0/16'));
        $this->assertFalse(CallQualityService::inCidr('10.13.0.1', '10.12.0.0/16'));
        $this->assertFalse(CallQualityService::inCidr('10.12.0.1', '10.12.0.1'), 'not a CIDR');
    }
}
