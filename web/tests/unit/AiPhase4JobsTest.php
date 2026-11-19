<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ai/AiJobService.php';
require_once __DIR__ . '/../../src/services/ai/AiBindingService.php';
require_once __DIR__ . '/../../src/services/ai/CloudAiService.php';
require_once __DIR__ . '/../../src/services/VoicemailAiService.php';
require_once __DIR__ . '/../../src/services/CdrAiService.php';

/**
 * Faz 4 tests:
 * - plugins/ai-voicemail/plugin.json and plugins/ai-call-analysis/plugin.json manifests
 * - AiJobService idempotency, claim/lease expiry recovery, cancel, limited retry, non-retryable failure
 * - Deleted voicemail / deleted CDR safety (late job does not write to voicemail_ai / cdr_ai)
 * - VoicemailAiService / CdrAiService binding resolution (local_default never falls back to cloud)
 */
final class AiPhase4JobsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $prevSettings = [];

    protected function setUp(): void
    {
        $db = getDB();
        require_once __DIR__ . '/../../db/migrations/20261117170000_create_ai_jobs_and_split_vm_cdr.php';
        $adapter = new \Phinx\Db\Adapter\MysqlAdapter(['connection' => $db, 'name' => getenv('DB_NAME')]);
        $migration = new CreateAiJobsAndSplitVmCdr('testing', 20261117170000);
        $migration->setAdapter($adapter);
        $migration->up();

        $this->prevSettings = $db->query(
            "SELECT setting_key, setting_value FROM sys_settings WHERE setting_key IN ('ai_vm_transcribe', 'ai_vm_summary') OR setting_key LIKE 'ai_engine.%'"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->cleanupTestRows();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestRows();
        $db = getDB();
        $db->exec("DELETE FROM sys_settings WHERE setting_key IN ('ai_vm_transcribe', 'ai_vm_summary') OR setting_key LIKE 'ai_engine.%'");
        $st = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)');
        foreach ($this->prevSettings as $k => $v) {
            $st->execute([$k, $v]);
        }
    }

    private function cleanupTestRows(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM ai_jobs WHERE idempotency_key LIKE 'test-p4-%' OR idempotency_key IN ('vm:99999901', 'vm:9999901:1700000999', 'cdr:99999902')");
        $db->exec("DELETE FROM ai_bindings WHERE feature IN ('ai_voicemail', 'ai_call_analysis') AND scope_ref = 'global'");
        $db->exec("DELETE FROM voicemail_ai WHERE mailbox = '9999901'");
        $db->exec("DELETE FROM cdr_ai WHERE cdr_id = 99999902");
    }

    public function testPluginManifestsExistAndAreValid(): void
    {
        $root = dirname(__DIR__, 3);
        $expected = [
            'ai-voicemail' => '/ai-voicemail',
            'ai-call-analysis' => '/ai-call-analysis',
        ];

        foreach ($expected as $slug => $route) {
            $path = $root . '/plugins/' . $slug . '/plugin.json';
            $this->assertFileExists($path);
            $json = json_decode((string) file_get_contents($path), true);
            $this->assertIsArray($json);
            $this->assertSame($slug, $json['slug'] ?? null);
            $this->assertSame('ai', $json['category'] ?? null);
            $this->assertSame($route, $json['hooks']['admin_page']['route'] ?? null);
        }
    }

    public function testAiJobServiceIdempotencyPreventsDuplicates(): void
    {
        $id1 = AiJobService::enqueue(
            'ai_voicemail',
            'transcribe',
            'voicemail',
            '1001',
            'test-p4-idem-1',
            ['mailbox' => '100', 'origtime' => 1700000001],
            'local:whisper'
        );
        $this->assertGreaterThan(0, $id1);

        $id2 = AiJobService::enqueue(
            'ai_voicemail',
            'transcribe',
            'voicemail',
            '1001',
            'test-p4-idem-1',
            ['mailbox' => '100', 'origtime' => 1700000001],
            'local:whisper'
        );
        $this->assertSame($id1, $id2);

        $st = getDB()->prepare("SELECT COUNT(*) FROM ai_jobs WHERE idempotency_key = 'test-p4-idem-1'");
        $st->execute();
        $this->assertSame(1, (int) $st->fetchColumn());
    }

    public function testAiJobServiceClaimAndLeaseExpiryRecovery(): void
    {
        // Drain or isolate by inserting our test job and checking claimNext recovers expired lease
        $jobId = AiJobService::enqueue(
            'ai_call_analysis',
            'analyze',
            'cdr',
            '555',
            'test-p4-lease-1',
            ['cdr_id' => 555],
            'local:whisper',
            3
        );

        // Claim the job
        $claimed = AiJobService::claimNext('worker-a', 60);
        $this->assertNotNull($claimed);
        $this->assertSame($jobId, (int) $claimed['id']);
        $this->assertSame('running', $claimed['status']);
        $this->assertStringStartsWith('worker-a#', (string) $claimed['lease_owner']);
        $this->assertSame(1, (int) $claimed['attempts']);

        // While lease is active, another worker cannot claim it
        $none = AiJobService::claimNext('worker-b', 60);
        $this->assertNull($none);

        // Simulate worker crash / expired lease
        getDB()->prepare("UPDATE ai_jobs SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 10 SECOND) WHERE id = ?")
            ->execute([$jobId]);

        // claimNext recovers the expired running job directly
        $recovered = AiJobService::claimNext('worker-b', 60);
        $this->assertNotNull($recovered);
        $this->assertSame($jobId, (int) $recovered['id']);
        $this->assertSame('running', $recovered['status']);
        $this->assertStringStartsWith('worker-b#', (string) $recovered['lease_owner']);
        $this->assertSame(2, (int) $recovered['attempts']);

        // Expire again and test recoverStaleLeases()
        getDB()->prepare("UPDATE ai_jobs SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 10 SECOND) WHERE id = ?")
            ->execute([$jobId]);
        $count = AiJobService::recoverStaleLeases();
        $this->assertGreaterThanOrEqual(1, $count);

        $row = AiJobService::find($jobId);
        $this->assertNotNull($row);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['lease_owner']);
    }

    public function testAiJobServiceCancelLimitedRetryAndNonRetryableFailure(): void
    {
        // 1. Cancel
        $cancelId = AiJobService::enqueue('ai_voicemail', 'transcribe', 'voicemail', '2001', 'test-p4-cancel-1');
        $this->assertTrue(AiJobService::cancel($cancelId));
        $this->assertFalse(AiJobService::cancel($cancelId));
        $cancelledRow = AiJobService::find($cancelId);
        $this->assertNotNull($cancelledRow);
        $this->assertSame('cancelled', $cancelledRow['status']);

        // 2. Limited retry (max_attempts = 2)
        $retryId = AiJobService::enqueue('ai_voicemail', 'transcribe', 'voicemail', '2002', 'test-p4-retry-1', [], null, 2);

        $c1 = AiJobService::claimNext('worker-r', 60);
        $this->assertNotNull($c1);
        $this->assertSame($retryId, (int) $c1['id']);
        AiJobService::fail($retryId, 'transient timeout', true);
        $r1 = AiJobService::find($retryId);
        $this->assertNotNull($r1);
        $this->assertSame('pending', $r1['status']);
        $this->assertSame('transient timeout', $r1['last_error']);

        $c2 = AiJobService::claimNext('worker-r', 60);
        $this->assertNotNull($c2);
        $this->assertSame($retryId, (int) $c2['id']);
        AiJobService::fail($retryId, 'still failing', true);
        $r2 = AiJobService::find($retryId);
        $this->assertNotNull($r2);
        $this->assertSame('failed', $r2['status']);
        $this->assertNotNull($r2['finished_at']);

        // 3. Non-retryable failure fails immediately on first attempt
        $permId = AiJobService::enqueue('ai_call_analysis', 'analyze', 'cdr', '2003', 'test-p4-perm-1', [], null, 3);
        $c3 = AiJobService::claimNext('worker-r', 60);
        $this->assertNotNull($c3);
        $this->assertSame($permId, (int) $c3['id']);
        AiJobService::fail($permId, 'uncertain paid request', false);
        $r3 = AiJobService::find($permId);
        $this->assertNotNull($r3);
        $this->assertSame('failed', $r3['status']);
        $this->assertSame(1, (int) $r3['attempts']);
    }

    public function testDeletedVoicemailAndDeletedCdrSafety(): void
    {
        $db = getDB();
        $prevVmPlugin = $db->query("SELECT enabled FROM sys_plugins WHERE slug = 'ai-voicemail'")->fetchColumn();
        $db->exec("UPDATE sys_plugins SET enabled = 1 WHERE slug = 'ai-voicemail'");
        $db->exec("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('ai_vm_transcribe', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");

        try {
            // Deleted / nonexistent voicemail job must skip and never write to voicemail_ai
            $vmJobId = VoicemailAiService::enqueue(99999901, [
                'mailbox' => '9999901',
                'origtime' => 1700000999,
                'lang' => 'en',
            ]);
            $this->assertGreaterThan(0, $vmJobId);
            $vmJob = AiJobService::find($vmJobId);
            $this->assertNotNull($vmJob);

            $vmRes = VoicemailAiService::processJob($vmJob);
            $this->assertSame('voicemail_deleted', $vmRes['skipped'] ?? null);
            $this->assertNull(VoicemailAiService::find('9999901', 1700000999));

            // Deleted / nonexistent CDR job must skip and never write to cdr_ai
            $cdrJobId = CdrAiService::enqueue('99999902', ['lang' => 'en']);
            $this->assertGreaterThan(0, $cdrJobId);
            $cdrJob = AiJobService::find($cdrJobId);
            $this->assertNotNull($cdrJob);

            $cdrRes = CdrAiService::processJob($cdrJob);
            $this->assertSame('skipped', $cdrRes['status'] ?? null);
            $this->assertSame('cdr_deleted', $cdrRes['reason'] ?? null);
            $this->assertNull(CdrAiService::find(99999902));
        } finally {
            if ($prevVmPlugin !== false) {
                $db->prepare("UPDATE sys_plugins SET enabled = ? WHERE slug = 'ai-voicemail'")->execute([(int) $prevVmPlugin]);
            }
        }
    }

    public function testVoicemailJobsUseTheMatchingSpoolAudioAndAreScopedToTheMailbox(): void
    {
        $db = getDB();
        $base = tempnam(sys_get_temp_dir(), 'vm-job-test-');
        $this->assertNotFalse($base);
        $db->beginTransaction();
        try {
            $db->exec("UPDATE sys_plugins SET enabled = 1 WHERE slug = 'ai-voicemail'");
            $db->exec("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('ai_vm_transcribe', '1')
                ON DUPLICATE KEY UPDATE setting_value = '1'");
            AiBindingService::saveBinding('ai_voicemail', 'stt', 'off', null, 'default', 'global');
            $origtime = 1700000880;
            file_put_contents($base . '.txt', "origtime={$origtime}\n");
            file_put_contents($base . '.WAV', 'test audio');
            $payload = ['origtime' => $origtime, 'txt_path' => $base . '.txt', 'lang' => 'en'];
            $first = VoicemailAiService::enqueue(0, $payload + ['mailbox' => '9999901']);
            $second = VoicemailAiService::enqueue(0, $payload + ['mailbox' => '9999902']);
            $this->assertNotSame($first, $second);
            $this->assertSame($first, VoicemailAiService::enqueue(1234, $payload + ['mailbox' => '9999901']));

            // STT is off, so reaching the transcription error proves the audio was located without a provider call.
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Voicemail transcription produced empty result');
            $job = AiJobService::find($first);
            $this->assertNotNull($job);
            VoicemailAiService::processJob($job);
        } finally {
            $db->rollBack();
            unlink($base . '.WAV');
            unlink($base . '.txt');
            unlink($base);
        }
    }

    public function testLocalDefaultBindingNeverFallsBackToCloud(): void
    {
        // Configure legacy cloud engines as a trap: when ai_bindings has local_default,
        // neither VoicemailAiService nor CdrAiService may fall back to cloud.
        CloudAiService::saveEngines([
            'engine_stt_tr' => 'cloud:deepgram',
            'engine_llm_tr' => 'cloud:openai',
        ]);

        AiBindingService::saveBinding('ai_voicemail', 'stt', 'local_default', null, 'default', 'global');
        AiBindingService::saveBinding('ai_voicemail', 'chat', 'local_default', null, 'default', 'global');
        AiBindingService::saveBinding('ai_call_analysis', 'stt', 'local_default', null, 'default', 'global');
        AiBindingService::saveBinding('ai_call_analysis', 'chat', 'local_default', null, 'default', 'global');

        $vmStt = VoicemailAiService::resolveEngine('stt', 'tr', 'vm_stt');
        $vmChat = VoicemailAiService::resolveEngine('chat', 'tr', 'vm_llm');
        $cdrStt = CdrAiService::resolveSelection('stt', 'tr', 'cdr_stt');
        $cdrChat = CdrAiService::resolveSelection('chat', 'tr', 'cdr_llm');

        foreach ([$vmStt, $vmChat, $cdrStt, $cdrChat] as $resolved) {
            if ($resolved !== null) {
                $this->assertFalse(str_starts_with($resolved, 'cloud:'), "local_default must never fall back to cloud, got {$resolved}");
            }
        }

        // Also verify 'off' mode returns null even when legacy cloud engines are configured
        AiBindingService::saveBinding('ai_voicemail', 'stt', 'off', null, 'default', 'global');
        AiBindingService::saveBinding('ai_call_analysis', 'stt', 'off', null, 'default', 'global');
        $this->assertNull(VoicemailAiService::resolveEngine('stt', 'tr', 'vm_stt'));
        $this->assertNull(CdrAiService::resolveSelection('stt', 'tr', 'cdr_stt'));
    }
}
