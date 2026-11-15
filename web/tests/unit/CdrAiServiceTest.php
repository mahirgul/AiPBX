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
        $_SESSION['user_id'] = null;
    }

    protected function tearDown(): void
    {
        TtsHttp::$transport = null;
        CdrReportRepository::resetLookupMaps();
        $db = getDB();
        $db->exec("DELETE FROM cdr_ai WHERE call_id LIKE 'test-cdr-ai-%'");
        $db->exec("DELETE FROM asteriskcdr WHERE linkedid LIKE 'test-cdr-ai-%'");
        $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'ai_tts.%' OR setting_key LIKE 'ai_engine.%' OR setting_key LIKE 'ai_limit.%'");
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
}
