<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/services/ai/AiJobService.php';
require_once __DIR__ . '/../../src/services/ai/CloudAiService.php';
require_once __DIR__ . '/../../src/sync/SyncOutboundDialplan.php';

final class CodexFindingsTest extends TestCase
{
    private PDO $db;
    private string $testProvider = 'zz_codex_quota_test';

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->db->exec("DELETE FROM ai_jobs WHERE idempotency_key LIKE 'codex-test-%'");
        $this->db->prepare('DELETE FROM ai_cloud_usage WHERE provider = ?')->execute([$this->testProvider]);
        $this->db->prepare("DELETE FROM sys_settings WHERE setting_key LIKE 'ai_cloud_%'")->execute();
    }

    protected function tearDown(): void
    {
        $this->db->exec("DELETE FROM ai_jobs WHERE idempotency_key LIKE 'codex-test-%'");
        $this->db->prepare('DELETE FROM ai_cloud_usage WHERE provider = ?')->execute([$this->testProvider]);
        $this->db->prepare("DELETE FROM pbx_trunks WHERE trunk_name = 'zz_codex_monthly'")->execute();
        AiJobService::consumeReservedQuota($this->testProvider, 'stt_seconds');
    }

    public function testCancelledAndReclaimedJobsCannotBeOverwrittenByOldLease(): void
    {
        $cancelledId = AiJobService::enqueue('ai_call_analysis', 'analyze', 'cdr', '88001', 'codex-test-cancel');
        $claimed = AiJobService::claimById($cancelledId, 'worker-a', 60);
        $this->assertNotNull($claimed);
        $leaseA = (string) $claimed['lease_owner'];
        $this->assertNotSame('', $leaseA);

        $this->assertTrue(AiJobService::cancel($cancelledId));
        $this->assertFalse(AiJobService::complete($cancelledId, ['ok' => true], $leaseA));
        $this->assertFalse(AiJobService::fail($cancelledId, 'late fail', true, $leaseA));
        $row = AiJobService::find($cancelledId);
        $this->assertSame('cancelled', $row['status'] ?? null);

        // Lease takeover fencing
        $leaseId = AiJobService::enqueue('ai_call_analysis', 'analyze', 'cdr', '88002', 'codex-test-lease');
        $first = AiJobService::claimById($leaseId, 'worker-1', 1);
        $this->assertNotNull($first);
        $lease1 = (string) $first['lease_owner'];

        $this->db->prepare("UPDATE ai_jobs SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 10 SECOND) WHERE id = ?")
            ->execute([$leaseId]);

        $second = AiJobService::claimById($leaseId, 'worker-2', 60);
        $this->assertNotNull($second);
        $lease2 = (string) $second['lease_owner'];
        $this->assertNotSame($lease1, $lease2);

        $this->assertFalse(AiJobService::complete($leaseId, ['stale' => true], $lease1));
        $this->assertFalse(AiJobService::fail($leaseId, 'stale err', false, $lease1));
        $this->assertTrue(AiJobService::complete($leaseId, ['fresh' => true], $lease2));
        $this->assertSame('succeeded', AiJobService::find($leaseId)['status'] ?? null);
    }

    public function testCloudQuotaReservationDoesNotBlockSelfOrDoubleCount(): void
    {
        $month = date('Y-m');
        $limitsJson = json_encode(['stt_min' => 1.0, 'tts_chars' => 0, 'llm_tokens' => 0]);
        $this->db->prepare("INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute(['ai_cloud_limits_' . $this->testProvider, $limitsJson]);
        $this->db->prepare('INSERT INTO ai_cloud_usage (provider, month, stt_seconds) VALUES (?, ?, 59)')
            ->execute([$this->testProvider, $month]);

        $this->assertTrue(AiJobService::checkAndReserveQuota($this->testProvider, 'stt', 1));
        // Must NOT block its own in-flight call at 59+1=60/60
        $this->assertFalse(CloudAiService::overLimit($this->testProvider, 'stt'));

        // Actual usage is 4s -> net total must be 59 + 4 = 63 (not 64)
        CloudAiService::count($this->testProvider, 'stt_seconds', 4.0);
        $used = (float) $this->db->query("SELECT stt_seconds FROM ai_cloud_usage WHERE provider = '{$this->testProvider}' AND month = '{$month}'")->fetchColumn();
        $this->assertEqualsWithDelta(63.0, $used, 0.01);

        // Reservation release on failure restores prior count
        $this->db->prepare('UPDATE ai_cloud_usage SET stt_seconds = 59 WHERE provider = ? AND month = ?')
            ->execute([$this->testProvider, $month]);
        $this->assertTrue(AiJobService::checkAndReserveQuota($this->testProvider, 'stt', 1));
        AiJobService::releaseReservedQuota($this->testProvider, 'stt_seconds');
        $restored = (float) $this->db->query("SELECT stt_seconds FROM ai_cloud_usage WHERE provider = '{$this->testProvider}' AND month = '{$month}'")->fetchColumn();
        $this->assertEqualsWithDelta(59.0, $restored, 0.01);
    }

    public function testFallbackOutboundRouteAppliesMonthlyTrunkLimit(): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM pbx_outbound_routes');
            $this->db->exec('UPDATE pbx_trunks SET is_active = 0');
            $this->db->prepare("INSERT INTO pbx_trunks (trunk_name, title, ip_address, is_active, monthly_minutes_limit, monthly_block) VALUES ('zz_codex_monthly', 'Codex Monthly', '127.0.0.1', 1, 100, 1)")
                ->execute();

            syncOutboundDialplan();
            $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');
            $this->assertStringContainsString('sub-trunk-limit,s,1(zz_codex_monthly,0,0,trunk,1)', $conf);
        } finally {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
        }
    }
}
