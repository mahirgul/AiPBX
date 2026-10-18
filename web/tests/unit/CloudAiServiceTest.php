<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ai/CloudAiService.php';

/**
 * AI → Cloud services: speech to text request shapes per provider (fake
 * transport), engine choices, usage counting.
 */
final class CloudAiServiceTest extends TestCase
{
    private array $calls = [];
    private array $before = [];

    protected function setUp(): void
    {
        $this->before = getDB()->query("SELECT setting_key, setting_value FROM sys_settings WHERE setting_key LIKE 'ai_tts.%' OR setting_key LIKE 'ai_engine.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $_SESSION['user_id'] = null;
    }

    protected function tearDown(): void
    {
        TtsHttp::$transport = null;
        $db = getDB();
        $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'ai_tts.%' OR setting_key LIKE 'ai_engine.%'");
        $st = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)');
        foreach ($this->before as $k => $v) {
            $st->execute([$k, $v]);
        }
        $db->exec("DELETE FROM ai_cloud_usage WHERE provider LIKE 'test%' OR provider IN ('deepgram', 'groq')");
    }

    private function fake(array $answer): void
    {
        TtsHttp::$transport = function (string $method, string $url, array $headers, ?string $body) use ($answer) {
            $this->calls[] = compact('method', 'url', 'headers', 'body');
            return ['status' => 200, 'body' => json_encode($answer), 'type' => 'application/json'];
        };
    }

    private static function wav(float $seconds, int $rate = 16000): string
    {
        $pcm = str_repeat("\0\0", (int) ($seconds * $rate));
        return 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16) . 'data' . pack('V', strlen($pcm)) . $pcm;
    }

    public function testWavSeconds(): void
    {
        $this->assertEqualsWithDelta(2.5, CloudAiService::wavSeconds(self::wav(2.5)), 0.001);
        $this->assertSame(0.0, CloudAiService::wavSeconds('nope'));
    }

    public function testDeepgramRequestAndUsage(): void
    {
        CloudAiService::save('deepgram', ['api_key' => 'dg-key']);
        $this->assertSame(['deepgram'], array_values(array_intersect(CloudAiService::withCap('stt'), ['deepgram'])));
        $this->fake(['results' => ['channels' => [['alternatives' => [['transcript' => 'iki havlu lütfen']]]]]]);
        $r = CloudAiService::stt('deepgram', self::wav(1.5), 'tr');
        $this->assertSame('iki havlu lütfen', $r['text']);
        $c = $this->calls[0];
        $this->assertStringStartsWith('https://api.deepgram.com/v1/listen?', $c['url']);
        $this->assertStringContainsString('language=tr', $c['url']);
        $this->assertSame('Token dg-key', $c['headers']['Authorization']);
        $this->assertSame('audio/wav', $c['headers']['Content-Type']);
        $this->assertEqualsWithDelta(1.5, CloudAiService::usageThisMonth()['deepgram']['stt_seconds'], 0.01);
    }

    public function testGroqSendsMultipartWhisper(): void
    {
        CloudAiService::save('groq', ['api_key' => 'gq']);
        $this->fake(['text' => ' Klima çalışmıyor. ']);
        $r = CloudAiService::stt('groq', self::wav(1), 'de');
        $this->assertSame('Klima çalışmıyor.', $r['text']);
        $c = $this->calls[0];
        $this->assertSame('https://api.groq.com/openai/v1/audio/transcriptions', $c['url']);
        $this->assertStringStartsWith('multipart/form-data; boundary=', $c['headers']['Content-Type']);
        $this->assertStringContainsString('name="language"' . "\r\n\r\nde", $c['body']);
        $this->assertStringContainsString('whisper-large-v3-turbo', $c['body']);
    }

    public function testProviderWithoutSttOrKeyIsRefused(): void
    {
        $this->expectException(CloudAiException::class);
        CloudAiService::stt('openrouter', self::wav(1), 'tr');
    }

    public function testEnginesAreValidated(): void
    {
        CloudAiService::saveEngines([
            'engine_tts_tr' => 'local:ema-lightning',
            'engine_stt_tr' => 'cloud:deepgram',
            'engine_stt_de' => 'cloud:nonexistent',
            'engine_tts_en' => "local:../../etc",
        ]);
        $this->assertSame('local:ema-lightning', CloudAiService::engine('tts', 'tr'));
        $this->assertSame('cloud:deepgram', CloudAiService::engine('stt', 'tr'));
        $this->assertSame('', CloudAiService::engine('stt', 'de'));
        $this->assertSame('', CloudAiService::engine('tts', 'en'));
    }

    public function testSecretsAreEncryptedAndMasked(): void
    {
        CloudAiService::save('google_ai', ['api_key' => 'AIzaSyTestKey12345']);
        $raw = (string) getSystemSetting('ai_tts.google_ai.api_key', '');
        $this->assertStringNotContainsString('AIzaSyTestKey12345', $raw);
        $page = array_column(CloudAiService::forPage(), null, 'id')['google_ai'];
        $this->assertTrue($page['configured']);
        $this->assertSame('', $page['fields'][0]['value']);
        $this->assertNotSame('', $page['fields'][0]['masked']);
    }

    public function testOpenAiCompatibleChats(): void
    {
        // NVIDIA: fixed address, model from the account (default when empty).
        CloudAiService::save('nvidia', ['api_key' => 'nvapi-test-123']);
        $this->fake(['choices' => [['message' => ['content' => ' Merhaba ']]], 'usage' => ['total_tokens' => 7]]);
        $this->assertSame('Merhaba', CloudAiService::chat('nvidia', 'hi'));
        $c = end($this->calls);
        $this->assertSame('https://integrate.api.nvidia.com/v1/chat/completions', $c['url']);
        $this->assertSame('Bearer nvapi-test-123', $c['headers']['Authorization']);
        $this->assertSame('meta/llama-3.3-70b-instruct', json_decode($c['body'], true)['model']);

        // Qwen: the default (international) endpoint and the model typed in.
        CloudAiService::save('qwen', ['api_key' => 'sk-qwen-123', 'model' => 'qwen-turbo']);
        CloudAiService::chat('qwen', 'hi');
        $c = end($this->calls);
        $this->assertSame(CloudAiService::QWEN_ENDPOINT . '/chat/completions', $c['url']);
        $this->assertSame('qwen-turbo', json_decode($c['body'], true)['model']);

        // Own address without a key (e.g. Ollama): no Authorization header.
        CloudAiService::save('openai_compat', ['base_url' => 'http://10.0.0.5:11434/v1/', 'model' => 'qwen2.5:7b']);
        $this->assertTrue(CloudAiService::configured('openai_compat'));
        CloudAiService::chat('openai_compat', 'hi');
        $c = end($this->calls);
        $this->assertSame('http://10.0.0.5:11434/v1/chat/completions', $c['url']);
        $this->assertArrayNotHasKey('Authorization', $c['headers']);
        $this->assertSame(['provider' => 'openai_compat', 'api_key' => '', 'model' => 'qwen2.5:7b', 'base_url' => 'http://10.0.0.5:11434/v1'],
            CloudAiService::llmSettings('openai_compat'));
    }

    public function testOpenAiSpeechModelIsNotTheLanguageModel(): void
    {
        CloudAiService::save('openai', ['api_key' => 'sk-test-1234567890', 'model' => 'gpt-4o-mini-tts']);
        $this->assertSame(['provider' => 'openai', 'api_key' => 'sk-test-1234567890'], CloudAiService::llmSettings('openai'));
        $this->fake(['choices' => [['message' => ['content' => 'ok']]]]);
        CloudAiService::chat('openai', 'hi');
        $this->assertSame('gpt-4.1-mini', json_decode(end($this->calls)['body'], true)['model']);
    }

    public function testBaseUrlAndModelChecked(): void
    {
        $this->assertSame('https://api.deepseek.com/v1', CloudAiService::validBaseUrl('https://api.deepseek.com/v1/'));
        foreach (['ftp://x/v1', 'http://user:pw@x/v1', 'https://x/v1?a=1', 'javascript:alert(1)', ''] as $bad) {
            $this->assertNull(CloudAiService::validBaseUrl($bad), $bad);
        }
        $this->expectException(CloudAiException::class);
        CloudAiService::save('openai_compat', ['base_url' => 'https://x/v1', 'model' => 'bad model']);
    }

    public function testTypedValuesReachTheTestCallBeforeBeingSaved(): void
    {
        CloudAiService::save('openai_compat', ['base_url' => 'https://saved.example/v1', 'model' => 'saved-model']);
        $this->fake(['choices' => [['message' => ['content' => 'OK']]]]);

        // Everything typed but not yet saved is what the Test button exercises.
        CloudAiService::test('openai_compat', [
            'base_url' => 'https://typed.example/v1/',
            'model'    => 'typed/model-1',
            'api_key'  => 'typed-key-123',
        ]);
        $c = end($this->calls);
        $this->assertSame('https://typed.example/v1/chat/completions', $c['url']);
        $this->assertSame('Bearer typed-key-123', $c['headers']['Authorization']);
        $this->assertSame('typed/model-1', json_decode($c['body'], true)['model']);

        // Blank fields fall back to the stored account (a later Test without
        // the key in the field must not pretend the account has no key).
        CloudAiService::test('openai_compat', []);
        $c = end($this->calls);
        $this->assertSame('https://saved.example/v1/chat/completions', $c['url']);
        $this->assertArrayNotHasKey('Authorization', $c['headers']);

        // A typed bad address is refused exactly as Save refuses it.
        try {
            CloudAiService::test('openai_compat', ['base_url' => 'javascript:alert(1)']);
            $this->fail('invalid typed base_url must be refused');
        } catch (CloudAiException $e) {
            $this->assertSame(t('ai_cloud.err_base_url'), $e->getMessage());
        }
    }
}
