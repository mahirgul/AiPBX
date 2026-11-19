<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/services/TrunkUsageService.php';
require_once dirname(__DIR__, 2) . '/src/services/health/HealthChecks.php';

final class TrunkUsageServiceTest extends TestCase
{
    public function testLimitStates(): void
    {
        $this->assertSame('none', TrunkUsageService::evaluate([], 500)['state']);
        $t = ['monthly_minutes_limit' => 1000, 'monthly_warn_percent' => 80];
        $this->assertSame(['minutes' => 500, 'cost' => 0.0, 'percent' => 50, 'state' => 'ok'], TrunkUsageService::evaluate($t, 500));
        $this->assertSame('warn', TrunkUsageService::evaluate($t, 800)['state']);
        $this->assertSame('over', TrunkUsageService::evaluate($t, 1000)['state']);

        // The cost limit counts only with a price; the higher of the two decides.
        $c = ['monthly_cost_limit' => 100, 'monthly_warn_percent' => 90];
        $this->assertFalse(TrunkUsageService::limited($c));
        $c['monthly_cost_per_minute'] = 0.05;
        $r = TrunkUsageService::evaluate($c + ['monthly_minutes_limit' => 10000], 1900);
        $this->assertSame(95.0, $r['cost']);
        $this->assertSame(95, $r['percent']);
        $this->assertSame('warn', $r['state']);
    }

    public function testMinutesAreCountedPerCallRoundedUpForThatTrunkOnly(): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $db->exec("INSERT INTO pbx_trunks (trunk_name, title, ip_address, is_active)
                VALUES ('zztrunk', 'Usage test', '127.0.0.1', 1)");
            $now = date('Y-m-d H:i:s');
            $ins = $db->prepare("INSERT INTO asteriskcdr (calldate, src, dst, channel, dstchannel, disposition, duration, billsec, uniqueid)
                                 VALUES (?, '1001', '0212', 'PJSIP/1001-00000001', ?, ?, ?, ?, ?)");
            $ins->execute([$now, 'PJSIP/zztrunk-0000000a', 'ANSWERED', 70, 61, 'zz.1']);      // 2 minutes
            $ins->execute([$now, 'PJSIP/zztrunk-0000000b', 'ANSWERED', 30, 30, 'zz.2']);      // 1
            $ins->execute([$now, 'PJSIP/zztrunk-0000000c', 'NO ANSWER', 30, 0, 'zz.3']);      // 0
            $ins->execute([$now, 'PJSIP/zztrunk2-0000000d', 'ANSWERED', 300, 300, 'zz.4']);   // another trunk
            $ins->execute([date('Y-m-d H:i:s', strtotime('-40 days')), 'PJSIP/zztrunk-0000000e', 'ANSWERED', 600, 600, 'zz.5']);
            $ins->execute([$now, 'PJSIP/1001-0000000f', 'ANSWERED', 30, 30, 'zz.6']);
            $month = date('Y-m-01');
            $this->assertSame(3, TrunkUsageService::monthlyMinutes('zztrunk', $month));
            $db->exec("DELETE FROM asteriskcdr WHERE uniqueid IN ('zz.1', 'zz.2', 'zz.3', 'zz.5', 'zz.6')");
            $this->assertSame(3, TrunkUsageService::monthlyMinutes('zztrunk', $month));
            $this->assertSame(1, (int) $db->query("SELECT COUNT(*) FROM asteriskcdr WHERE uniqueid = 'zz.4'")->fetchColumn());
            $this->assertSame(3, (int) $db->query("SELECT minutes FROM pbx_deleted_trunk_usage
                WHERE trunk_name = 'zztrunk' AND month_start = '{$month}'")->fetchColumn());
            $this->assertFalse($db->query("SELECT minutes FROM pbx_deleted_trunk_usage WHERE trunk_name = '1001'")->fetchColumn());
        } finally {
            $db->rollBack();
        }
    }

    public function testOutboundCallsAskForTheMonthlyFlagExceptEmergencyRoutes(): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $db->exec('DELETE FROM pbx_outbound_routes');
            $db->exec('DELETE FROM pbx_trunks');
            $db->exec("INSERT INTO pbx_trunks (trunk_name, title, ip_address, is_active, monthly_minutes_limit, monthly_block) VALUES ('zz_month', 'Test', '127.0.0.1', 1, 100, 1)");
            $json = json_encode([['trunk_name' => 'zz_month']]);
            $db->prepare("INSERT INTO pbx_outbound_routes (route_name, match_pattern, route_group, is_active, trunks_json, is_emergency, sort_order) VALUES ('zz_out', '_0X.', 1, 1, ?, 0, 1), ('zz_112', '112', 1, 1, ?, 1, 0)")
                ->execute([$json, $json]);
            syncOutboundDialplan();
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');
            $this->assertStringContainsString('Gosub(sub-trunk-limit,s,1(zz_month,0,0,trunk,1))', $conf);
            foreach (explode('exten => ', $conf) as $block) {
                if (str_starts_with($block, '112,')) {
                    $this->assertStringNotContainsString('sub-trunk-limit', $block, 'the emergency route must not check the monthly flag');
                }
            }
        } finally {
            $db->rollBack();
            syncOutboundDialplan();
        }
        $this->assertStringContainsString('(month),Set(TRUNK_LIMIT=monthly)', trunkLimitSubroutine());
    }

    public function testHealthCheckNamesAMonthlyRefusal(): void
    {
        $now = 1_800_000_000;
        $out = "/trunkblock/zz_month : " . ($now - 60) . ":4:monthly:" . ($now - 30) . "\n1 results found.";
        [$state, $msg] = HealthChecks::trunkLimitsStatus($out, $now);
        $this->assertSame('warn', $state);
        $this->assertStringContainsString('zz_month', $msg);
    }
}
