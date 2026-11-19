<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/services/ai/AiAppService.php';
require_once __DIR__ . '/../../src/services/QueueService.php';
require_once __DIR__ . '/../../src/sync/DialplanDestinationBuilder.php';
require_once __DIR__ . '/../../src/sync/SyncAiApps.php';

final class PreQueueAiAgentTest extends TestCase
{
    public function testDefaultAgentPresetIsMultiCallConversational(): void
    {
        $preset = AiAppService::defaultAgentPreset();
        $this->assertSame('voice_requests', $preset['app_type']);
        $this->assertSame(5, $preset['max_concurrent']);
        $cfg = json_decode((string) $preset['config'], true);
        $this->assertIsArray($cfg);
        $this->assertTrue($cfg['conversation']);
        $this->assertTrue($cfg['barge_in']);
    }

    public function testQueueSaveRejectsMissingPreQueueAiApp(): void
    {
        $_SESSION['csrf_token'] = 'test_csrf_preq';
        $res = QueueService::saveQueue([
            'csrf_token' => 'test_csrf_preq',
            'queue_id' => 0,
            'queue_name' => 'unit_preq_invalid',
            'title' => 'Unit PreQ Invalid',
            'pre_queue_ai_app_id' => 99999999,
        ]);
        $this->assertFalse($res['success']);
    }

    public function testPreQueueAiDialplanAndResumeContext(): void
    {
        $db = getDB();
        $db->prepare('INSERT INTO pbx_ai_apps (title, app_type, model_id, speed, max_concurrent, text_template, dest_type, dest_id, config, is_active) VALUES (?, ?, ?, 1.0, 5, ?, ?, ?, ?, 1)')
            ->execute([
                'Unit PreQ AI',
                'voice_requests',
                'local_default',
                'Hello',
                'hangup',
                '',
                json_encode(['conversation' => true, 'barge_in' => true, 'max_turns' => 6, 'instructions' => 'Help caller']),
            ]);
        $aiId = (int) $db->lastInsertId();

        $db->prepare('INSERT INTO pbx_queues (queue_name, title, strategy, timeout, retry, wrapuptime, musicclass, pre_queue_ai_app_id, is_active) VALUES (?, ?, ?, 15, 5, 0, ?, ?, 1)')
            ->execute(['unit_preq_q', 'Unit PreQ Queue', 'ringall', 'default', $aiId]);
        $qId = (int) $db->lastInsertId();

        try {
            $lines = buildDestinationLines('queue', (string) $qId);
            $this->assertStringContainsString("AIPBX_PRE_QUEUE_DONE_{$qId}", $lines);
            $this->assertStringContainsString("Set(__AIPBX_PRE_QUEUE_Q={$qId})", $lines);
            $this->assertStringContainsString("Goto(aipbx-ai-app-{$aiId},s,1)", $lines);
            $this->assertStringContainsString('AIPBX_AI_HANDOVER', $lines);
            $this->assertStringContainsString('Queue(unit_preq_q', $lines);

            $apps = AiAppService::all();
            $activeApps = array_values(array_filter($apps, fn($a) => (int) $a['id'] === $aiId));
            $conf = buildAiAppsDialplan($activeApps, 'testkey');
            $this->assertStringContainsString('GotoIf($["${AIPBX_PRE_QUEUE_Q}" != ""]?aipbx-queue-resume-${AIPBX_PRE_QUEUE_Q},s,1)', $conf);
            $this->assertStringContainsString("[aipbx-queue-resume-{$qId}]", $conf);
        } finally {
            $db->prepare('DELETE FROM pbx_queues WHERE id = ?')->execute([$qId]);
            $db->prepare('DELETE FROM pbx_ai_apps WHERE id = ?')->execute([$aiId]);
        }
    }
}
