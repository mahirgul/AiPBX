<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/core/BaseRepository.php';
require_once __DIR__ . '/../../src/repositories/QueueLogRepository.php';

final class QueueLogGroupedTest extends TestCase
{
    private static int $user1Id = 0;
    private static int $user2Id = 0;

    public static function setUpBeforeClass(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM sys_users WHERE extension IN ('8811', '8812')");

        $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, is_active) 
            VALUES ('test_q_agent1', 'pass', 'Ahmet Temsilci', 'a1@example.com', 'user', '8811', 1)")->execute();
        self::$user1Id = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, is_active) 
            VALUES ('test_q_agent2', 'pass', 'Ayse Temsilci', 'a2@example.com', 'user', '8812', 1)")->execute();
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
        getDB()->exec("DELETE FROM cc_queue_logs WHERE call_id LIKE 'test-q-call-%'");
    }

    protected function tearDown(): void
    {
        getDB()->exec("DELETE FROM cc_queue_logs WHERE call_id LIKE 'test-q-call-%'");
    }

    public function testGroupedQueueCallsIntoSingleMasterRowWithChronologicalJourney(): void
    {
        $db = getDB();
        $baseTs = time() - 300;
        $callId1 = 'test-q-call-answered-1';

        // Call 1: Entered -> Rang 8811 (no answer) -> Connected to 8812 -> Complete
        $stmt = $db->prepare("INSERT INTO cc_queue_logs 
            (time_id, created_at, call_id, queue_name, agent, event, data1, data2, data3, data4) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        // 1. ENTERQUEUE
        $stmt->execute([$baseTs, date('Y-m-d H:i:s', $baseTs), $callId1, 'Destek', 'NONE', 'ENTERQUEUE', '', '05329998877', '1', '']);
        // 2. RINGNOANSWER (8811 rang 15s)
        $stmt->execute([$baseTs + 15, date('Y-m-d H:i:s', $baseTs + 15), $callId1, 'Destek', 'PJSIP/8811', 'RINGNOANSWER', '15000', '', '', '']);
        // 3. CONNECT (8812 answered after 35s wait)
        $stmt->execute([$baseTs + 35, date('Y-m-d H:i:s', $baseTs + 35), $callId1, 'Destek', 'PJSIP/8812', 'CONNECT', '35', 'agent-ch-1', '5', '']);
        // 4. COMPLETECALLER (120s talk)
        $stmt->execute([$baseTs + 155, date('Y-m-d H:i:s', $baseTs + 155), $callId1, 'Destek', 'PJSIP/8812', 'COMPLETECALLER', '35', '120', '1', '']);

        // Call 2: Abandoned call
        $callId2 = 'test-q-call-abandon-2';
        $baseTs2 = $baseTs + 200;
        $stmt->execute([$baseTs2, date('Y-m-d H:i:s', $baseTs2), $callId2, 'Satis', 'NONE', 'ENTERQUEUE', '', '05443332211', '1', '']);
        $stmt->execute([$baseTs2 + 10, date('Y-m-d H:i:s', $baseTs2 + 10), $callId2, 'Satis', 'PJSIP/8811', 'RINGNOANSWER', '10000', '', '', '']);
        $stmt->execute([$baseTs2 + 25, date('Y-m-d H:i:s', $baseTs2 + 25), $callId2, 'Satis', 'NONE', 'ABANDON', '1', '1', '25', '']);

        $agentMap = QueueLogRepository::agentMap();

        // Test Grouped Mode
        $resGrouped = QueueLogRepository::searchAndParse($baseTs - 10, $baseTs2 + 50, '', '', 'test-q-call-', $agentMap, 'grouped');
        $this->assertEquals('grouped', $resGrouped['view_mode']);
        $this->assertCount(2, $resGrouped['logs'], '2 different calls should be grouped into exactly 2 rows');

        // Verify Call 1 (Answered)
        $call1 = null;
        $call2 = null;
        foreach ($resGrouped['logs'] as $c) {
            if ($c['call_id'] === $callId1) $call1 = $c;
            if ($c['call_id'] === $callId2) $call2 = $c;
        }

        $this->assertNotNull($call1);
        $this->assertEquals('CONNECTED', $call1['status']);
        $this->assertEquals('05329998877', $call1['caller_num']);
        $this->assertEquals('Destek', $call1['queue_name']);
        $this->assertEquals('8812', $call1['agent_ext']);
        $this->assertEquals('Ayse Temsilci', $call1['agent_name']);
        $this->assertEquals(35, $call1['hold_sec']);
        $this->assertEquals(120, $call1['talk_sec']);
        $this->assertEquals(155, $call1['total_sec']);
        $this->assertCount(4, $call1['steps']);
        $this->assertEquals('ENTERQUEUE', $call1['steps'][0]['event']);
        $this->assertEquals('RINGNOANSWER', $call1['steps'][1]['event']);
        $this->assertEquals('CONNECT', $call1['steps'][2]['event']);
        $this->assertEquals('COMPLETECALLER', $call1['steps'][3]['event']);

        // Verify Call 2 (Abandoned)
        $this->assertNotNull($call2);
        $this->assertEquals('ABANDON', $call2['status']);
        $this->assertEquals('05443332211', $call2['caller_num']);
        $this->assertEquals('Satis', $call2['queue_name']);
        $this->assertEquals(25, $call2['hold_sec']);
        $this->assertEquals(0, $call2['talk_sec']);
        $this->assertCount(3, $call2['steps']);

        // Verify Stats
        $this->assertEquals(2, $resGrouped['stat_total_enter']);
        $this->assertEquals(1, $resGrouped['stat_connected']);
        $this->assertEquals(1, $resGrouped['stat_abandon']);
        $this->assertEquals(2, $resGrouped['stat_ring_no_answer']);
        $this->assertEquals(35, $resGrouped['avg_holdtime']);
        $this->assertEquals(120, $resGrouped['avg_talktime']);

        // Test Raw Mode Fallback
        $resRaw = QueueLogRepository::searchAndParse($baseTs - 10, $baseTs2 + 50, '', '', 'test-q-call-', $agentMap, 'raw');
        $this->assertEquals('raw', $resRaw['view_mode']);
        $this->assertCount(7, $resRaw['logs'], 'Raw mode should return all 7 individual events');
        $this->assertEquals(7, $resRaw['total'], 'Raw mode total count should be 7');

        // Test Pagination in Grouped Mode
        $resPage1 = QueueLogRepository::searchAndParse($baseTs - 10, $baseTs2 + 50, '', '', 'test-q-call-', $agentMap, 'grouped', 1, 1);
        $this->assertCount(1, $resPage1['logs'], 'Page size 1 should return exactly 1 grouped call');
        $this->assertEquals(2, $resPage1['total'], 'Total grouped calls should remain 2');

        $resPage2 = QueueLogRepository::searchAndParse($baseTs - 10, $baseTs2 + 50, '', '', 'test-q-call-', $agentMap, 'grouped', 2, 1);
        $this->assertCount(1, $resPage2['logs'], 'Page 2 should return the second call');
        $this->assertNotEquals($resPage1['logs'][0]['call_id'], $resPage2['logs'][0]['call_id']);

        // Test Pagination in Raw Mode
        $resRawPage1 = QueueLogRepository::searchAndParse($baseTs - 10, $baseTs2 + 50, '', '', 'test-q-call-', $agentMap, 'raw', 1, 3);
        $this->assertCount(3, $resRawPage1['logs'], 'Page 1 with limit 3 should return 3 raw logs');
        $this->assertEquals(7, $resRawPage1['total'], 'Total raw count should remain 7');
    }
}
