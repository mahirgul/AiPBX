<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/core/BaseRepository.php';
require_once __DIR__ . '/../../src/repositories/CdrReportRepository.php';
require_once __DIR__ . '/../../src/services/CdrReportService.php';
require_once __DIR__ . '/../../src/services/CdrCallAnalyzer.php';

final class CdrReportTest extends TestCase
{
    private static int $user1Id = 0;
    private static int $user2Id = 0;

    public static function setUpBeforeClass(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM sys_users WHERE extension IN ('9931', '9932')");

        $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, is_active) 
            VALUES ('test_rep_1', 'pass', 'Temsilci Bir', 't1@example.com', 'user', '9931', 1)")->execute();
        self::$user1Id = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, is_active) 
            VALUES ('test_rep_2', 'pass', 'Temsilci Iki', 't2@example.com', 'user', '9932', 1)")->execute();
        self::$user2Id = (int)$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        $db = getDB();
        if (self::$user1Id) {
            $db->exec("DELETE FROM sys_users WHERE id = " . self::$user1Id);
        }
        if (self::$user2Id) {
            $db->exec("DELETE FROM sys_users WHERE id = " . self::$user2Id);
        }
    }

    protected function setUp(): void
    {
        getDB()->exec("DELETE FROM asteriskcdr WHERE linkedid LIKE 'test-cdr-group-%'");
    }

    protected function tearDown(): void
    {
        getDB()->exec("DELETE FROM asteriskcdr WHERE linkedid LIKE 'test-cdr-group-%'");
    }

    public function testMultiLegCallGroupedIntoSingleMasterRowWithAccurateStats(): void
    {
        $db = getDB();
        $linkedid = 'test-cdr-group-call-1';
        $now = date('Y-m-d H:i:s');

        // Leg 1: Queue attempt to 9931 (missed/timeout)
        $db->prepare("INSERT INTO asteriskcdr 
            (calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata, billsec, duration, disposition, linkedid, uniqueid, did)
            VALUES (?, '\"Customer\" <05051112233>', '05051112233', '9931', 'from-internal-pbx', 'Local/9931@from-internal-pbx-01;2', 'PJSIP/9931-sip-01', 'Dial', 'PJSIP/9931-sip,15', 0, 15, 'NO ANSWER', ?, ?, '13000')"
        )->execute([$now, $linkedid, $linkedid . '-leg1']);

        // Leg 2: Queue attempt to 9932 WebRTC (cancelled because SIP answered)
        $db->prepare("INSERT INTO asteriskcdr 
            (calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata, billsec, duration, disposition, linkedid, uniqueid, did)
            VALUES (?, '\"Customer\" <05051112233>', '05051112233', '9932', 'from-internal-pbx', 'Local/9932@from-internal-pbx-02;2', 'PJSIP/9932-webrtc-01', 'Dial', 'PJSIP/9932-webrtc,15', 0, 5, 'NO ANSWER', ?, ?, '13000')"
        )->execute([$now, $linkedid, $linkedid . '-leg2']);

        // Leg 3: Queue attempt to 9932 SIP (ANSWERED)
        $db->prepare("INSERT INTO asteriskcdr 
            (calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata, billsec, duration, disposition, linkedid, uniqueid, did)
            VALUES (?, '\"Customer\" <05051112233>', '05051112233', '9932', 'from-internal-pbx', 'Local/9932@from-internal-pbx-02;2', 'PJSIP/9932-sip-01', 'Dial', 'PJSIP/9932-sip,15', 0, 5, 'ANSWERED', ?, ?, '13000')"
        )->execute([$now, $linkedid, $linkedid . '-leg3']);

        // Leg 4: Queue master leg with voice recording
        $db->prepare("INSERT INTO asteriskcdr 
            (calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata, billsec, duration, disposition, linkedid, uniqueid, did, userfield)
            VALUES (?, '\"Customer\" <05051112233>', '05051112233', 't', 'app-ivr-1', 'PJSIP/neco-01', 'Local/9932@from-internal-pbx-02;1', 'Queue', 'queue_cc,tT,,,1800', 25, 30, 'ANSWERED', ?, ?, '13000', ?)"
        )->execute([$now, $linkedid, $linkedid . '-master', '/tmp/test_rec.wav']);

        // Test Grouped Search
        $results = CdrReportRepository::search(true, '', null, null, '', '', '05051112233', 1, 10, '', 'grouped');
        $this->assertCount(1, $results, 'Multi-leg call should be grouped into exactly 1 master row');

        $master = $results[0];
        $this->assertEquals($linkedid, $master['linkedid']);
        $this->assertEquals('05051112233', $master['caller_num']);
        $this->assertEquals('9932', $master['agent_extension'], 'Answered agent should be 9932');
        $this->assertEquals('Temsilci Iki', $master['agent_name']);
        $this->assertEquals('ANSWERED', $master['status'], 'Call outcome should be ANSWERED');
        $this->assertEquals('sip', $master['device_type']);
        $this->assertEquals('/tmp/test_rec.wav', $master['recording_path']);
        $this->assertGreaterThanOrEqual(4, $master['total_legs']);
        $this->assertCount(4, $master['legs'], 'All 4 legs should be attached for timeline');

        // Test Grouped Summary
        $ozet = CdrReportRepository::ozet(true, '', null, null, '', '', '05051112233', '', 'grouped');
        $this->assertEquals(1, $ozet['toplam'], 'Summary should count 1 unique call');
        $this->assertEquals(1, $ozet['cevaplanan'], 'Summary should count 1 answered call');
        $this->assertEquals(0, $ozet['cevapsiz'], 'False missed calls should NOT be counted in grouped summary');

        // Test Raw Search returns all 4 rows
        $rawResults = CdrReportRepository::search(true, '', null, null, '', '', '05051112233', 1, 10, '', 'raw');
        $this->assertCount(4, $rawResults, 'Raw mode should return all 4 individual legs');
    }

    /**
     * Direction and trunk filters run in SQL (paging and totals must match);
     * they have to classify calls the same way CdrCallAnalyzer does.
     */
    public function testDirectionAndTrunkFiltersMatchTheAnalyzer(): void
    {
        $db = getDB();
        $now = date('Y-m-d H:i:s');
        $ins = $db->prepare("INSERT INTO asteriskcdr
            (calldate, clid, src, dst, did, dcontext, channel, dstchannel, lastapp, lastdata, billsec, duration, disposition, linkedid, uniqueid, accountcode)
            VALUES (?, '', ?, ?, ?, 'ctx', ?, ?, 'Dial', ?, 10, 12, ?, ?, ?, ?)");
        $call = function (string $name, array $legs) use ($ins, $now) {
            foreach ($legs as $i => $l) {
                $ins->execute([$now, $l[0], $l[1], $l[2], $l[3], $l[4], $l[5], $l[6] ?? 'ANSWERED', "test-cdr-group-dir-{$name}", "test-cdr-group-dir-{$name}-{$i}", $l[7] ?? '']);
            }
        };
        // src, dst, did, channel, dstchannel, lastdata, disposition, accountcode
        $call('transit', [['0599000001', '9611', '9611', 'PJSIP/VOIPX-00003b2c', 'PJSIP/nectipx-00003b2d', 'PJSIP/9611@nectipx,60']]);
        $call('inbound', [
            ['0599000002', '9931', '9931', 'PJSIP/nectipx-000018b8', 'PJSIP/9931-sip-000018b9', 'PJSIP/9931-sip/sip:x'],
            ['0599000002', '8807', '9931', 'PJSIP/nectipx-000018b8', 'PJSIP/nectipx-000018c3', 'PJSIP/8807@nectipx,60', 'ANSWERED', '9931'],
        ]);
        $call('outbound', [['9931', '0599000003', '', 'PJSIP/9931-webrtc-00000001', 'PJSIP/VOIPX-00000002', 'PJSIP/0599000003@VOIPX,60']]);
        $call('internal', [['9931', '9932', '', 'PJSIP/9931-sip-00000003', 'PJSIP/9932-mob-webrtc-00000004', 'PJSIP/9932-mob-webrtc', 'NO ANSWER']]);

        $search = fn(string $dir, string $trunk = '') => array_column(
            CdrReportRepository::search(true, '', null, null, '', '', 'test-cdr-group-dir-', 1, 50, '', 'grouped', $dir, $trunk),
            'linkedid'
        );
        $this->assertSame(['test-cdr-group-dir-transit'], $search(CdrCallAnalyzer::TRANSIT));
        $this->assertSame(['test-cdr-group-dir-inbound'], $search(CdrCallAnalyzer::INBOUND));
        $this->assertSame(['test-cdr-group-dir-outbound'], $search(CdrCallAnalyzer::OUTBOUND));
        $this->assertSame(['test-cdr-group-dir-internal'], $search(CdrCallAnalyzer::INTERNAL));

        $byTrunk = $search('', 'nectipx');
        sort($byTrunk);
        $this->assertSame(['test-cdr-group-dir-inbound', 'test-cdr-group-dir-transit'], $byTrunk, 'in or out on the trunk');

        // The list's flow (PHP) agrees with the SQL direction of every row.
        foreach (CdrReportRepository::search(true, '', null, null, '', '', 'test-cdr-group-dir-', 1, 50, '', 'grouped') as $row) {
            $this->assertSame($row['direction'], $row['flow']['direction'], $row['linkedid']);
        }
        $inbound = CdrReportRepository::search(true, '', null, null, '', '', 'test-cdr-group-dir-inbound', 1, 50, '', 'grouped')[0];
        $this->assertSame('9931', $inbound['agent_extension'], 'the extension that answered, not the transfer target');
        $this->assertTrue($inbound['flow']['transferred']);

        $ozet = CdrReportRepository::ozet(true, '', null, null, '', '', 'test-cdr-group-dir-', '', 'grouped');
        $this->assertSame([4, 1, 1, 1, 1], [$ozet['toplam'], $ozet['gelen'], $ozet['giden'], $ozet['dahili'], $ozet['transit']]);
    }

    public function testDeleteCdrRemovesAllLegsOfLinkedCall(): void
    {
        $db = getDB();
        $linkedid = 'test-cdr-group-delete-1';
        $now = date('Y-m-d H:i:s');

        // Insert 2 legs
        $db->prepare("INSERT INTO asteriskcdr 
            (calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata, billsec, duration, disposition, linkedid, uniqueid)
            VALUES (?, '3001', '3001', '3002', 'from-internal-pbx', 'PJSIP/3001-01', 'PJSIP/3002-01', 'Dial', 'PJSIP/3002', 10, 15, 'ANSWERED', ?, ?)"
        )->execute([$now, $linkedid, $linkedid . '-l1']);
        $delId = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO asteriskcdr 
            (calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata, billsec, duration, disposition, linkedid, uniqueid)
            VALUES (?, '3001', '3001', '3002', 'from-internal-pbx', 'Local/3002', '', 'Hangup', '', 0, 0, 'ANSWERED', ?, ?)"
        )->execute([$now, $linkedid, $linkedid . '-l2']);

        $_SESSION['csrf_token'] = 'test-token';
        $deleted = CdrReportService::deleteCdr($delId, 'test-token', true);
        $this->assertTrue($deleted);

        // Verify both legs were deleted
        $remaining = (int)$db->query("SELECT COUNT(*) FROM asteriskcdr WHERE linkedid = '{$linkedid}'")->fetchColumn();
        $this->assertEquals(0, $remaining, 'All legs belonging to the linkedid should be deleted');
    }
}
