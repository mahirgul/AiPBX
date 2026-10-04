<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/core/BaseRepository.php';
require_once dirname(__DIR__, 2) . '/src/repositories/QueueReportRepository.php';
require_once dirname(__DIR__, 2) . '/src/services/QueueStats.php';

/**
 * Queue Report Centre: queue_log events → one row per call → KPIs.
 * Event layouts follow Asterisk's queue_log (CONNECT data1 = hold time,
 * data3 = ring time; COMPLETE* data2 = talk time; ABANDON data3 = wait).
 */
final class QueueReportTest extends TestCase
{
    private const Q = 'test_qr_queue';
    private int $t0;

    protected function setUp(): void
    {
        getDB()->exec("DELETE FROM cc_queue_logs WHERE queue_name = '" . self::Q . "' OR call_id LIKE 'test-qr-%'");
        $this->t0 = strtotime('2026-01-15 09:00:00');
    }

    protected function tearDown(): void
    {
        getDB()->exec("DELETE FROM cc_queue_logs WHERE queue_name = '" . self::Q . "' OR call_id LIKE 'test-qr-%'");
    }

    private function ev(int $t, string $call, string $event, string $agent = 'NONE', array $data = []): void
    {
        getDB()->prepare("INSERT INTO cc_queue_logs (time_id, created_at, call_id, queue_name, agent, event, data1, data2, data3, data4, data5)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$t, date('Y-m-d H:i:s', $t), $call, self::Q, $agent, $event, $data[0] ?? '', $data[1] ?? '', $data[2] ?? '', $data[3] ?? '', $data[4] ?? '']);
    }

    private static function call(array $f): array
    {
        return $f + ['call_id' => 'x', 'queue_name' => 'q', 'enter_ts' => 0, 'caller' => '', 'agent' => '', 'answered' => 0, 'lost' => '',
            'wait' => 0, 'ring' => 0, 'talk' => 0, 'agent_hangup' => 0, 'transferred' => 0, 'position' => 1];
    }

    public function testCallsFromQueueLogEvents(): void
    {
        $t = $this->t0;
        // Answered after 12 s by 3001, talked 95 s, agent hung up.
        $this->ev($t, 'test-qr-1', 'ENTERQUEUE', 'NONE', ['', '05321112233', '1']);
        $this->ev($t + 5, 'test-qr-1', 'RINGNOANSWER', 'Temsilci 3002', ['15000']);
        $this->ev($t + 12, 'test-qr-1', 'CONNECT', 'Temsilci 3001', ['12', '1700000000.1', '4']);
        $this->ev($t + 107, 'test-qr-1', 'COMPLETEAGENT', 'Temsilci 3001', ['12', '95', '1']);
        // Caller hung up after 40 s.
        $this->ev($t + 60, 'test-qr-2', 'ENTERQUEUE', 'NONE', ['', '05329998877', '1']);
        $this->ev($t + 100, 'test-qr-2', 'ABANDON', 'NONE', ['1', '1', '40']);
        // Timed out after 300 s; entered the day before the period ends? no: inside.
        $this->ev($t + 200, 'test-qr-3', 'ENTERQUEUE', 'NONE', ['', '05329998877', '2']);
        $this->ev($t + 500, 'test-qr-3', 'EXITWITHTIMEOUT', 'NONE', ['1', '2', '300']);
        // Entered before the period: not counted.
        $this->ev($t - 7200, 'test-qr-0', 'ENTERQUEUE', 'NONE', ['', '05320000000', '1']);

        $calls = QueueReportRepository::calls($t - 60, $t + 3600, [self::Q]);
        $by = array_column($calls, null, 'call_id');
        $this->assertSame(['test-qr-1', 'test-qr-2', 'test-qr-3'], array_keys($by));

        $this->assertSame(1, $by['test-qr-1']['answered']);
        $this->assertSame('Temsilci 3001', $by['test-qr-1']['agent']);
        $this->assertSame([12, 4, 95, 1], [$by['test-qr-1']['wait'], $by['test-qr-1']['ring'], $by['test-qr-1']['talk'], $by['test-qr-1']['agent_hangup']]);
        $this->assertSame('05321112233', $by['test-qr-1']['caller']);
        $this->assertSame(['abandon', 40], [$by['test-qr-2']['lost'], $by['test-qr-2']['wait']]);
        $this->assertSame(['timeout', 300], [$by['test-qr-3']['lost'], $by['test-qr-3']['wait']]);

        $misses = QueueReportRepository::ringMisses($t - 60, $t + 3600, [self::Q]);
        $this->assertSame(['count' => 1, 'ring_ms' => 15000], $misses['3002']);

        $k = QueueStats::kpis($calls, 20);
        $this->assertSame([3, 1, 2], [$k['offered'], $k['answered'], $k['lost']]);
        $this->assertSame(33.3, $k['service_level']);
        $this->assertSame(12, $k['asa']);
        $this->assertSame(95, $k['aht']);
        $this->assertSame(300, $k['max_wait']);
    }

    public function testKpiDefinitions(): void
    {
        $calls = [
            self::call(['answered' => 1, 'wait' => 10, 'talk' => 60]),
            self::call(['answered' => 1, 'wait' => 25, 'talk' => 120]),
            self::call(['lost' => 'abandon', 'wait' => 3]),     // short abandon: not in the service level
            self::call(['lost' => 'abandon', 'wait' => 45]),
        ];
        $k = QueueStats::kpis($calls, 20);
        $this->assertSame(4, $k['offered']);
        $this->assertSame(1, $k['short_abandons']);
        $this->assertSame(33.3, $k['service_level'], '1 of 3 (4 offered - 1 short abandon) answered within 20 s');
        $this->assertSame(50.0, $k['answer_rate']);
        $this->assertSame(18, $k['asa']);
        $this->assertSame(90, $k['aht']);
        $this->assertSame(24, $k['avg_lost_wait']);
        $this->assertSame(0.0, QueueStats::kpis([], 20)['service_level'], 'no division by zero');
    }

    public function testAgents(): void
    {
        $calls = [
            self::call(['answered' => 1, 'agent' => 'Temsilci 3001', 'talk' => 100, 'ring' => 4, 'agent_hangup' => 1]),
            self::call(['answered' => 1, 'agent' => 'Local/3001@from-internal-pbx/n', 'talk' => 50, 'ring' => 6]),
            self::call(['answered' => 1, 'agent' => 'Temsilci 3002', 'talk' => 30, 'ring' => 2]),
            self::call(['lost' => 'abandon', 'wait' => 30]),
        ];
        $a = QueueStats::byAgent($calls, ['3002' => ['count' => 3, 'ring_ms' => 45000], '3005' => ['count' => 2, 'ring_ms' => 1]],
            ['3001' => ['count' => 2, 'seconds' => 900]], ['3002' => 4]);
        $this->assertSame(['3001', '3002', '3005'], array_map('strval', array_keys($a)), 'most answered first; idle agents with missed rings still listed');
        $this->assertSame([2, 66.7, 150, 75, 100, 5, 1], [$a['3001']['answered'], $a['3001']['share'], $a['3001']['talk_total'], $a['3001']['aht'], $a['3001']['talk_max'], $a['3001']['avg_ring'], $a['3001']['agent_hangups']]);
        $this->assertSame(100.0, $a['3001']['pickup_rate']);
        $this->assertSame(25.0, $a['3002']['pickup_rate'], '1 answered of 4 rings');
        $this->assertSame([2, 900], [$a['3001']['pause_count'], $a['3001']['pause_seconds']]);
        $this->assertSame(4, $a['3002']['notes']);
        $this->assertSame(0.0, $a['3005']['pickup_rate']);
    }

    public function testDistributionAndBuckets(): void
    {
        $t = $this->t0;
        $calls = [
            self::call(['enter_ts' => $t, 'answered' => 1, 'wait' => 5]),
            self::call(['enter_ts' => $t + 600, 'lost' => 'abandon', 'wait' => 25]),
            self::call(['enter_ts' => $t + 3600, 'answered' => 1, 'wait' => 400]),
            self::call(['enter_ts' => $t + 86400, 'lost' => 'timeout', 'wait' => 300]),
        ];
        $d = QueueStats::distribution($calls);
        $this->assertSame(['offered' => 3, 'answered' => 1, 'lost' => 2], $d['hours'][9]);
        $this->assertSame(['offered' => 1, 'answered' => 1, 'lost' => 0], $d['hours'][10]);
        $this->assertSame(['2026-01-15', '2026-01-16'], array_keys($d['days']));

        $b = QueueStats::waitBuckets($calls);
        $this->assertSame([1, 0], [$b[0]['answered'], $b[0]['lost']], '0-10 s');
        $this->assertSame(1, $b[2]['lost'], '20-30 s');
        $this->assertSame([1, 1], [end($b)['answered'], end($b)['lost']], '300 s and more');
    }

    public function testLostCallsAndCallBacks(): void
    {
        $t = $this->t0;
        $calls = [
            self::call(['call_id' => 'a', 'enter_ts' => $t, 'caller' => '05321112233', 'lost' => 'abandon']),
            self::call(['call_id' => 'b', 'enter_ts' => $t + 100, 'caller' => '05325556677', 'lost' => 'abandon']),
            self::call(['call_id' => 'c', 'enter_ts' => $t + 200, 'caller' => '+905325556677', 'answered' => 1]),
            self::call(['call_id' => 'd', 'enter_ts' => $t + 300, 'caller' => '05329990000', 'lost' => 'timeout']),
        ];
        // 0532 111 22 33 was called back from an extension as 90532…
        $lost = QueueStats::lostCalls($calls, ['5321112233' => [$t - 50, $t + 900]]);
        $by = array_column($lost, null, 'call_id');
        $this->assertSame(['d', 'b', 'a'], array_column($lost, 'call_id'), 'newest first');
        $this->assertSame(['called_back', $t + 900], [$by['a']['resolved_how'], $by['a']['resolved_at']], 'a call before the lost one does not count');
        $this->assertSame(['called_again', $t + 200], [$by['b']['resolved_how'], $by['b']['resolved_at']], '+90 and 0 prefixes are the same caller');
        $this->assertNull($by['d']['resolved_at']);

        $rep = QueueStats::repeatCallers($calls);
        $this->assertCount(1, $rep);
        $this->assertSame([2, 1, 1], [$rep[0]['calls'], $rep[0]['answered'], $rep[0]['lost']]);
    }

    public function testAgentAndNumberParsing(): void
    {
        $this->assertSame('3001', QueueStats::agentExt('Temsilci 3001'));
        $this->assertSame('3001', QueueStats::agentExt('Local/3001@from-internal-pbx/n'));
        $this->assertSame('3002', QueueStats::agentExt('PJSIP/3002-sip'));
        $this->assertSame('5321112233', QueueStats::normalizeNumber('+90 (532) 111 22 33'));
        $this->assertSame('5321112233', QueueStats::normalizeNumber('05321112233'));
        $this->assertSame('3001', QueueStats::normalizeNumber('3001'));
    }
}
