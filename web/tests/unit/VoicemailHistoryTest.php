<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/VoicemailAiService.php';
require_once __DIR__ . '/../../src/services/ai/AiJobService.php';

final class VoicemailHistoryTest extends TestCase
{
    public function testJobsAndFinishedResultsAreOneListWithOneRowPerVoicemail(): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $mailbox = '9099901';
            $db->prepare('INSERT INTO voicemail_ai (mailbox, origtime, transcript, summary, engine) VALUES (?, 1700000100, ?, ?, ?)')
                ->execute([$mailbox, 'Please call me back.', 'Caller wants a call back.', 'cloud:groq']);
            // a finished job of that voicemail (the result is the row), a queued one and a failed one of two others
            $job = static fn (string $key, int $origtime, string $status, ?string $error) => $db->prepare(
                "INSERT INTO ai_jobs (feature, task, entity_type, entity_id, idempotency_key, status, attempts, max_attempts, payload_json, last_error, created_at, updated_at)
                 VALUES ('ai_voicemail', 'transcribe', 'voicemail', ?, ?, ?, 1, 3, ?, ?, NOW(), NOW())"
            )->execute(["{$mailbox}:{$origtime}", $key, $status, json_encode(['mailbox' => $mailbox, 'origtime' => $origtime]), $error]);
            $job('h-done', 1700000100, 'succeeded', null);
            $job('h-queued', 1700000200, 'pending', null);
            $job('h-failed', 1700000300, 'failed', 'Voicemail audio unavailable');

            $rows = [];
            foreach (VoicemailAiService::history(200) as $r) {
                if ($r['mailbox'] === $mailbox) {
                    $rows[$r['origtime']] = $r;
                }
            }
            $this->assertCount(3, $rows, 'one row per voicemail, not one for the job and one for the result');
            $this->assertSame(['succeeded', 'Caller wants a call back.', 'Please call me back.', 'cloud:groq'],
                [$rows[1700000100]['status'], $rows[1700000100]['summary'], $rows[1700000100]['transcript'], $rows[1700000100]['engine']]);
            $this->assertGreaterThan(0, $rows[1700000100]['job_id']);
            $this->assertSame('pending', $rows[1700000200]['status']);
            $this->assertGreaterThan(0, $rows[1700000200]['job_id'], 'a queued job can be cancelled');
            $this->assertSame(['failed', 'Voicemail audio unavailable'], [$rows[1700000300]['status'], $rows[1700000300]['error']]);
        } finally {
            $db->rollBack();
        }
    }
}
