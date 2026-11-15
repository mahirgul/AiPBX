<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/CdrAiService.php';
require_once __DIR__ . '/../../src/core/BaseRepository.php';
require_once __DIR__ . '/../../src/repositories/CdrReportRepository.php';

final class CdrAiServiceTest extends TestCase
{
    private array $beforeSettings = [];

    protected function setUp(): void
    {
        $db = getDB();
        $this->beforeSettings = $db->query(
            "SELECT setting_key, setting_value FROM sys_settings WHERE setting_key LIKE 'ai_tts.%' OR setting_key LIKE 'ai_engine.%' OR setting_key LIKE 'ai_limit.%'"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $db->exec("DELETE FROM asteriskcdr WHERE linkedid LIKE 'test-cdr-ai-%'");
        $db->exec("DELETE FROM cdr_ai WHERE call_id LIKE 'test-cdr-ai-%'");
        $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'cdr_qa_%'");
        $_SESSION['user_id'] = null;
    }

    protected function tearDown(): void
    {
        TtsHttp::$transport = null;
        CdrReportRepository::resetLookupMaps();
        $db = getDB();
        $db->exec("DELETE FROM cdr_ai WHERE call_id LIKE 'test-cdr-ai-%'");
        $db->exec("DELETE FROM asteriskcdr WHERE linkedid LIKE 'test-cdr-ai-%'");
        $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'ai_tts.%' OR setting_key LIKE 'ai_engine.%' OR setting_key LIKE 'ai_limit.%' OR setting_key LIKE 'cdr_qa_%'");
        $st = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)');
        foreach ($this->beforeSettings as $k => $v) {
            $st->execute([$k, $v]);
        }
        $db->exec("DELETE FROM ai_cloud_usage WHERE provider IN ('deepgram', 'openai')");
    }

    private static function wav(float $seconds, int $rate = 8000): string
    {
        $pcm = str_repeat("\0\0", (int) ($seconds * $rate));
        return 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16) . 'data' . pack('V', strlen($pcm)) . $pcm;
    }

    public function testTranscribeSummarizeSearchAndDelete(): void
    {
        $db = getDB();
        $db->prepare(
            "INSERT INTO asteriskcdr (calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata, billsec, duration, disposition, linkedid, uniqueid, userfield)
             VALUES (NOW(), '\"Ali\" <05051234567>', '05051234567', '100', 'from-internal-pbx', 'PJSIP/tr1-01', 'PJSIP/100-01', 'Dial', 'PJSIP/100', 42, 45, 'ANSWERED', 'test-cdr-ai-1', 'test-cdr-ai-1', '')"
        )->execute();
        $cdrId = (int) $db->lastInsertId();

        CloudAiService::save('deepgram', ['api_key' => 'dg-cdr-key']);
        CloudAiService::save('openai', ['api_key' => 'sk-cdr-key']);
        CloudAiService::saveEngines([
            'engine_stt_tr' => 'cloud:deepgram',
            'engine_llm_tr' => 'cloud:openai',
        ]);

        TtsHttp::$transport = function (string $method, string $url): array {
            if (str_contains($url, 'deepgram.com')) {
                return [
                    'status' => 200,
                    'body' => json_encode(['results' => ['channels' => [['alternatives' => [['transcript' => 'Klima arızası için teknik servis istiyorum.']]]]]]),
                    'type' => 'application/json',
                ];
            }
            if (str_contains($url, 'openai.com')) {
                return [
                    'status' => 200,
                    'body' => json_encode(['choices' => [['message' => ['content' => 'Müşteri klima arızası nedeniyle teknik servis talep etti.']]], 'usage' => ['total_tokens' => 24]]),
                    'type' => 'application/json',
                ];
            }
            return ['status' => 404, 'body' => '{}', 'type' => 'application/json'];
        };

        $item = CdrAiService::forCdr($cdrId, 'tr', false, self::wav(1.0));
        $this->assertNotNull($item);
        $this->assertSame($cdrId, $item['cdr_id']);
        $this->assertSame('Klima arızası için teknik servis istiyorum.', $item['transcript']);
        $this->assertSame('Müşteri klima arızası nedeniyle teknik servis talep etti.', $item['summary']);
        $this->assertSame('cloud:deepgram', $item['engine']);

        $found = CdrAiService::find($cdrId);
        $this->assertNotNull($found);
        $this->assertSame($item['summary'], $found['summary']);

        $batch = CdrAiService::forCdrIds([$cdrId, 99999999]);
        $this->assertArrayHasKey($cdrId, $batch);
        $this->assertArrayNotHasKey(99999999, $batch);
        $this->assertSame('cloud:deepgram', $batch[$cdrId]['engine']);

        // Search in grouped and raw CDR report views by AI transcript and summary text.
        $grouped = CdrReportRepository::search(true, '', null, null, '', '', 'klima arızası', 1, 20, '', 'grouped');
        $this->assertContains($cdrId, array_map('intval', array_column($grouped, 'id')));

        $raw = CdrReportRepository::search(true, '', null, null, '', '', 'talep etti', 1, 20, '', 'raw');
        $this->assertContains($cdrId, array_map('intval', array_column($raw, 'id')));

        CdrAiService::deleteForCdr($cdrId, 'test-cdr-ai-1');
        $this->assertNull(CdrAiService::find($cdrId));
    }

    public function testQualityReview(): void
    {
        // No criteria: no review, and nothing is asked.
        $this->assertNull(CdrAiService::review('Merhaba', 'tr', fn() => $this->fail('asked without criteria')));

        CloudAiService::save('openai', ['api_key' => 'sk-cdr-key']);
        CloudAiService::saveEngines(['engine_llm_tr' => 'cloud:openai']);
        $_SESSION['csrf_token'] = 'qa';
        CdrAiService::saveQaSettings(['qa_criteria' => 'Greets with the company name.', 'qa_auto' => '1', 'qa_daily_max' => '5']);
        $this->assertSame(['criteria' => 'Greets with the company name.', 'auto' => true, 'daily_max' => 5], CdrAiService::qaSettings());

        $asked = '';
        $qa = CdrAiService::review('Merhaba, ACME teknik destek.', 'tr', function (string $p) use (&$asked) {
            $asked = $p;
            return 'Sure: {"score": 87.6, "sentiment": "positive", "resolved": true, "notes": "Şirket adıyla karşıladı."}';
        });
        $this->assertSame(['qa_score' => 88, 'qa_sentiment' => 'positive', 'qa_resolved' => true, 'qa_notes' => 'Şirket adıyla karşıladı.'], $qa);
        $this->assertStringContainsString('Greets with the company name.', $asked);
        $this->assertStringContainsString('ACME teknik destek', $asked);

        // An odd answer is no review, a wrong mood is left out, the score is kept in 0-100.
        $this->assertNull(CdrAiService::review('x', 'tr', fn() => 'I think it was fine'));
        $odd = CdrAiService::review('x', 'tr', fn() => '{"score": 250, "sentiment": "angry", "resolved": "maybe"}');
        $this->assertSame([100, '', null], [$odd['qa_score'], $odd['qa_sentiment'], $odd['qa_resolved']]);

        // Automatic: switched off without criteria.
        CdrAiService::saveQaSettings(['qa_criteria' => '', 'qa_auto' => '1']);
        $this->assertFalse(CdrAiService::qaSettings()['auto']);
        $this->assertSame(0, CdrAiService::autoReview());
    }

    public function testAutomaticReviewTriesEachCallOnceAndKeepsTheDailyMaximum(): void
    {
        $db = getDB();
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $db->prepare(
                "INSERT INTO asteriskcdr (calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata, billsec, duration, disposition, linkedid, uniqueid, userfield)
                 VALUES (NOW() - INTERVAL 10 MINUTE, '', '0505', '100', 'from-internal-pbx', 'PJSIP/tr1-01', 'PJSIP/100-01', 'Dial', 'PJSIP/100', 42, 45, 'ANSWERED', ?, ?, '/nonexistent/test-cdr-ai.wav')"
            )->execute(["test-cdr-ai-q{$i}", "test-cdr-ai-q{$i}"]);
            $ids[] = (int) $db->lastInsertId();
        }
        $_SESSION['csrf_token'] = 'qa';
        CdrAiService::saveQaSettings(['qa_criteria' => 'Polite.', 'qa_auto' => '1', 'qa_daily_max' => '2']);
        $db->exec("DELETE FROM cdr_ai WHERE source = 'auto'");
        CdrAiService::autoReview();
        $tried = $db->query("SELECT cdr_id, transcript FROM cdr_ai WHERE source = 'auto'")->fetchAll(PDO::FETCH_KEY_PAIR);
        // The newest two (the daily maximum); no audio: remembered with no transcript, not tried again.
        $this->assertSame([$ids[1] => '', $ids[2] => ''], array_intersect_key($tried, array_flip($ids)) + [], 'newest two only');
        $this->assertCount(2, $tried);
        $this->assertSame(0, CdrAiService::autoReview(), 'the maximum of the day is reached');
        $db->exec("DELETE FROM cdr_ai WHERE cdr_id IN (" . implode(',', $ids) . ")");
    }


    public function testQualityByAgent(): void
    {
        $db = getDB();
        $ids = [];
        foreach ([[90, 1, 'positive'], [60, 0, 'negative'], [null, null, '']] as $i => [$score, $resolved, $mood]) {
            $db->prepare(
                "INSERT INTO asteriskcdr (calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata, billsec, duration, disposition, linkedid, uniqueid, userfield)
                 VALUES (NOW(), '', '0505', '100', 'from-internal-pbx', 'PJSIP/tr1-01', 'PJSIP/100-01', 'Dial', 'PJSIP/100', 42, 45, 'ANSWERED', ?, ?, '')"
            )->execute(["test-cdr-ai-a{$i}", "test-cdr-ai-a{$i}"]);
            $ids[] = $id = (int) $db->lastInsertId();
            $db->prepare("INSERT INTO cdr_ai (cdr_id, call_id, transcript, qa_score, qa_resolved, qa_sentiment) VALUES (?, ?, 'x', ?, ?, ?)")
               ->execute([$id, "test-cdr-ai-a{$i}", $score, $resolved, $mood]);
        }
        $ext = (string) $db->query('SELECT agent_extension FROM cdrs WHERE id = ' . $ids[0])->fetchColumn();
        $row = array_values(array_filter(CdrAiService::qaByAgent(date('Y-m-d 00:00:00')), fn($r) => $r['extension'] === $ext))[0] ?? null;
        $this->assertNotNull($row, 'agent row');
        $this->assertSame([2, 75, 50, 1], [$row['calls'], $row['avg'], $row['resolved_pct'], $row['negative']]);   // the unreviewed call is not counted
        $this->assertSame([], array_filter(CdrAiService::qaByAgent(date('Y-m-d H:i:s', time() + 3600)), fn($r) => $r['extension'] === $ext));
        $db->exec('DELETE FROM cdr_ai WHERE cdr_id IN (' . implode(',', $ids) . ')');
    }

}
