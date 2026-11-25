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
        $this->before = getDB()->query("SELECT setting_key, setting_value FROM sys_settings WHERE setting_key LIKE 'ai_tts.%' OR setting_key LIKE 'ai_engine.%' OR setting_key LIKE 'ai_limit.%' OR setting_key LIKE 'ai_use.%' OR setting_key = 'ai_languages' OR setting_key = 'ai_cloud_accounts' OR setting_key LIKE 'ai_cloud_cache.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $_SESSION['user_id'] = null;
    }

    protected function tearDown(): void
    {
        TtsHttp::$transport = null;
        $ref = new ReflectionProperty(LocalAiService::class, 'modelsCache');
        $ref->setValue(null, null);
        $db = getDB();
        $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'ai_tts.%' OR setting_key LIKE 'ai_engine.%' OR setting_key LIKE 'ai_limit.%' OR setting_key LIKE 'ai_use.%' OR setting_key = 'ai_languages' OR setting_key = 'ai_cloud_accounts' OR setting_key LIKE 'ai_cloud_cache.%'");
        $st = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)');
        foreach ($this->before as $k => $v) {
            $st->execute([$k, $v]);
        }
        $db->exec("DELETE FROM ai_cloud_usage WHERE provider LIKE 'test%' OR provider IN ('deepgram', 'groq', 'openai', 'nvidia', 'qwen', 'openai_compat')");
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

    public function testLanguagesCanBeAddedAndDeleted(): void
    {
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key = 'ai_languages'");
        $this->assertSame(['tr', 'de', 'en'], array_keys(CloudAiService::languages()), 'built-in list until changed');
        $this->assertSame('tr', CloudAiService::language('xx'), 'unknown: the first language');
        $this->assertSame('de', CloudAiService::language('de-AT'));

        CloudAiService::addLanguage(['lang_code' => 'fr', 'lang_locale' => 'fr-CA', 'lang_name' => 'French']);
        $this->assertSame(['locale' => 'fr-CA', 'name' => 'French'], CloudAiService::languages()['fr']);
        $this->assertSame('French', CloudAiService::languageName('fr'));
        $jobs = array_filter(CloudAiService::jobs(), fn($j) => $j['lang'] === 'fr');
        $this->assertSame(['tts', 'stt', 'llm'], array_column(array_values($jobs), 'job'), 'every job gets a row for the new language');

        foreach ([['lang_code' => 'FR1', 'lang_name' => 'x'], ['lang_code' => 'fr', 'lang_name' => 'Again'],
                  ['lang_code' => 'it', 'lang_locale' => 'fr-FR', 'lang_name' => 'Italian'], ['lang_code' => 'it', 'lang_name' => '<b>']] as $bad) {
            try {
                CloudAiService::addLanguage($bad);
                $this->fail('accepted: ' . json_encode($bad));
            } catch (CloudAiException $e) {
                $this->addToAssertionCount(1);
            }
        }

        getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute(['ai_engine.stt.fr', 'cloud:groq']);
        CloudAiService::deleteLanguage('fr');
        $this->assertArrayNotHasKey('fr', CloudAiService::languages());
        $this->assertSame('', (string) getSystemSetting('ai_engine.stt.fr', ''), 'its engines go with it');
    }

    public function testAzureSpeechToTextUsesTheLocaleOfAnAddedLanguage(): void
    {
        CloudAiService::addLanguage(['lang_code' => 'pt', 'lang_locale' => 'pt-BR', 'lang_name' => 'Portuguese']);
        CloudAiService::save('azure', ['api_key' => 'az-key-123', 'region' => 'westeurope']);
        $this->fake(['DisplayText' => 'Olá']);
        $this->assertSame('Olá', CloudAiService::stt('azure', self::wav(1), 'pt')['text']);
        $this->assertStringContainsString('language=pt-BR', $this->calls[0]['url']);
    }

    public function testAFeatureCanUseItsOwnService(): void
    {
        CloudAiService::save('groq', ['api_key' => 'gsk-test-key']);
        CloudAiService::save('openai', ['api_key' => 'sk-test-key']);
        getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute(['ai_engine.stt.tr', 'cloud:openai']);
        $this->assertSame('cloud:openai', CloudAiService::engineFor('vm_stt', 'tr'), 'by language until chosen');

        CloudAiService::saveFeatureEngines(['use_vm_stt' => 'cloud:groq', 'use_cdr_llm' => '']);
        $this->assertSame('cloud:groq', CloudAiService::engineFor('vm_stt', 'tr'));
        $this->assertSame('cloud:openai', CloudAiService::engineFor('cdr_stt', 'tr'), 'other features unchanged');

        foreach ([['use_vm_stt' => 'cloud:polly'], ['use_qa_llm' => 'local:ema-lightning'], ['use_vm_llm' => 'cloud:../x']] as $bad) {
            try {
                CloudAiService::saveFeatureEngines($bad);
                $this->fail('accepted: ' . json_encode($bad));
            } catch (CloudAiException $e) {
                $this->addToAssertionCount(1);
            }
        }
        CloudAiService::save('groq', ['clear' => '1']);
        $this->assertSame('cloud:openai', CloudAiService::engineFor('vm_stt', 'tr'), 'a removed account falls back to the language engine');
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

    public function testCustomRealtimeSettingsStaySeparateFromChat(): void
    {
        require_once __DIR__ . '/../../src/services/ai/AiAppService.php';
        $db = getDB();
        $enabled = $db->query("SELECT enabled FROM sys_plugins WHERE slug = 'aicloud'")->fetchColumn();
        $db->exec("UPDATE sys_plugins SET enabled = 1 WHERE slug = 'aicloud'");
        PluginManager::resetCache();
        try {
            $id = CloudAiService::addAccount('openai_compat', 'Realtime fixture', [
                'base_url' => 'https://chat.example.test/v1',
                'model' => 'chat-model',
                'realtime_model' => 'voice-model',
                'realtime_protocol' => 'openai_ga',
                'realtime_ws_url' => 'wss://voice.example.test/realtime',
            ]);
            $app = [
                'id' => 99, 'title' => 'RT Agent', 'model_id' => 'local:piper-tr-fahrettin',
                'speed' => '1',
                'config' => json_encode(['conversation' => true, 'realtime_account' => 'cloud:' . $id]),
            ];
            $cfg = AiAppService::serviceConfig($app)['realtime_cloud'];
            $this->assertSame('voice-model', $cfg['model']);
            $this->assertSame('openai_ga', $cfg['protocol']);
            $this->assertSame('wss://voice.example.test/realtime', $cfg['ws_url']);
            $this->assertSame('chat-model', CloudAiService::llmSettings($id)['model']);

            foreach ([
                ['realtime_protocol' => 'unsupported'],
                ['realtime_ws_url' => 'https://voice.example.test/realtime'],
                ['realtime_ws_url' => 'wss://user:pass@voice.example.test/realtime'],
            ] as $invalid) {
                try {
                    CloudAiService::save($id, $invalid + ['model' => 'unwanted-change']);
                    $this->fail('Invalid Realtime configuration was accepted');
                } catch (CloudAiException $e) {
                    $this->assertSame('chat-model', CloudAiService::config($id)['model']);
                }
            }

            CloudAiService::save($id, [
                'base_url' => 'https://chat.example.test/v1', 'model' => 'chat-model',
                'realtime_model' => '', 'realtime_protocol' => '', 'realtime_ws_url' => '',
            ]);
            $cfg = AiAppService::serviceConfig($app)['realtime_cloud'];
            $this->assertSame('chat-model', $cfg['model']);
            $this->assertSame('', $cfg['protocol']);
            $this->assertArrayNotHasKey('ws_url', $cfg);
        } finally {
            if ($enabled !== false) {
                $db->prepare("UPDATE sys_plugins SET enabled = ? WHERE slug = 'aicloud'")->execute([$enabled]);
            }
            PluginManager::resetCache();
        }
    }

    public function testSeveralAccountsOfOneProvider(): void
    {
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'ai_tts.openai.%' OR setting_key = 'ai_cloud_accounts'");
        $first = CloudAiService::addAccount('openai', 'Sales', ['api_key' => 'sk-first-1234567890']);
        $second = CloudAiService::addAccount('openai', 'Support', ['api_key' => 'sk-second-1234567890']);
        $this->assertSame(['openai', 'openai_b'], [$first, $second], 'the first keeps the id engine choices know');
        $this->assertSame('openai', CloudAiService::typeOf($second));
        $this->assertSame('Support', CloudAiService::provider($second)['title']);
        $this->assertSame('OpenAI', CloudAiService::provider($second)['provider_title']);
        $this->assertSame('sk-second-1234567890', CloudAiService::config($second)['api_key']);
        $this->assertSame('sk-first-1234567890', CloudAiService::config($first)['api_key']);
        $this->assertContains($second, CloudAiService::withCap('llm'));
        // The AI service is told the provider, not the account.
        $this->assertSame('openai', CloudAiService::llmSettings($second)['provider']);
        $this->assertSame('OpenAiTts', (new ReflectionClass(AiTtsService::providerClass($second)))->getShortName());

        // Unsaved form fields are not an account: a failed save leaves nothing behind.
        try {
            CloudAiService::addAccount('openai_compat', 'Bad', ['base_url' => 'ftp://x', 'model' => 'm']);
            $this->fail('expected a refusal');
        } catch (CloudAiException $e) {
            $this->assertArrayNotHasKey('openai_compat', CloudAiService::accounts());
        }

        CloudAiService::setEnabled($second, false);
        $this->assertFalse(CloudAiService::configured($second), 'switched off');
        $this->assertNotContains($second, CloudAiService::withCap('llm'));
        $this->assertArrayHasKey($second, CloudAiService::accounts(), 'still listed, its settings kept');
        CloudAiService::setEnabled($second, true);
        $this->assertTrue(CloudAiService::configured($second));

        CloudAiService::deleteAccount($second);
        $this->assertArrayNotHasKey($second, CloudAiService::accounts());
        $this->assertSame('sk-first-1234567890', CloudAiService::config($first)['api_key'], 'the other account keeps its key');
        CloudAiService::deleteAccount($first);
        $this->assertArrayNotHasKey('openai', CloudAiService::accounts());
        $this->assertFalse(CloudAiService::configured('openai'));
        $this->assertSame('openai', CloudAiService::addAccount('openai', '', ['api_key' => 'sk-again-1234567890']), 'a deleted type id is free again');
        $this->assertSame('OpenAI', CloudAiService::label('openai'));
        $this->assertSame(['openai'], array_keys(array_filter(json_decode((string) getSystemSetting('ai_cloud_accounts', '{}'), true), fn($a) => $a['type'] === 'openai')), 'no mark left by a deletion');

        // Local Ollama works on its defaults alone: deleted, it must not come back by itself.
        $this->assertArrayHasKey('ollama', CloudAiService::accounts());
        CloudAiService::deleteAccount('ollama');
        $this->assertArrayNotHasKey('ollama', CloudAiService::accounts());
        $this->assertFalse(CloudAiService::configured('ollama'));
        $this->assertSame('ollama', CloudAiService::addAccount('ollama', '', []));
        $this->assertTrue(CloudAiService::configured('ollama'));
    }

    public function testAnAccountStoredBeforeTheListIsTheProvidersOwn(): void
    {
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key = 'ai_cloud_accounts'");
        CloudAiService::save('deepgram', ['api_key' => 'dg-old-1234567890']);
        $this->assertSame(['type' => 'deepgram', 'name' => '', 'enabled' => true], CloudAiService::accounts()['deepgram']);
        $this->assertContains('deepgram', CloudAiService::withCap('stt'));
        $this->expectException(CloudAiException::class);
        CloudAiService::typeOf('deepgram_b');
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
        $c = CloudAiService::gatewayConfig('openai_compat', [
            'base_url' => 'https://typed.example/v1/', 'model' => 'typed/model-1', 'api_key' => 'typed-key-123',
        ]);
        $this->assertSame('https://typed.example/v1/chat/completions', $c['url']);
        $this->assertSame('typed-key-123', $c['api_key']);
        $this->assertSame('typed/model-1', $c['model']);
        $saved = CloudAiService::gatewayConfig('openai_compat');
        $this->assertSame('https://saved.example/v1/chat/completions', $saved['url']);
        $this->assertSame('', $saved['api_key']);
        $this->expectException(CloudAiException::class);
        CloudAiService::gatewayConfig('openai_compat', ['base_url' => 'javascript:alert(1)']);
    }

    public function testMonthlyQuotaLimitsWarningExceededAndFallback(): void
    {
        CloudAiService::save('deepgram', [
            'api_key' => 'dg-key',
            'limit_stt_min' => '1',
        ]);
        CloudAiService::save('openai', [
            'api_key' => 'sk-test-123',
            'limit_tts_chars' => '100',
            'limit_llm_tokens' => '50',
        ]);
        $this->assertSame(['stt_min' => 1, 'tts_chars' => 0, 'llm_tokens' => 0], CloudAiService::limits('deepgram'));
        $this->assertSame(['stt_min' => 0, 'tts_chars' => 100, 'llm_tokens' => 50], CloudAiService::limits('openai'));

        $warn = CloudAiService::usageStatus('openai', ['tts_chars' => 85, 'llm_tokens' => 10]);
        $this->assertSame(85, $warn['pct']);
        $this->assertTrue($warn['warn']);
        $this->assertFalse($warn['exceeded']);

        // Count 60 seconds of STT on deepgram (1 minute limit reached) and 50 LLM tokens on openai.
        CloudAiService::count('deepgram', 'stt_seconds', 60.0);
        CloudAiService::count('openai', 'llm_tokens', 50);

        $this->assertTrue(CloudAiService::overLimit('deepgram', 'stt'));
        $this->assertTrue(CloudAiService::overLimit('openai', 'llm'));
        $this->assertFalse(CloudAiService::overLimit('openai', 'tts'));

        try {
            CloudAiService::stt('deepgram', self::wav(1), 'tr');
            $this->fail('STT over limit must be refused');
        } catch (CloudAiException $e) {
            $this->assertSame(t('ai_cloud.err_quota_exceeded'), $e->getMessage());
        }

        try {
            CloudAiService::chat('openai', 'hi');
            $this->fail('LLM over limit must be refused');
        } catch (CloudAiException $e) {
            $this->assertSame(t('ai_cloud.err_quota_exceeded'), $e->getMessage());
        }

        // Engine configured as cloud:deepgram falls back to a matching local model when over limit.
        CloudAiService::saveEngines(['engine_stt_tr' => 'cloud:deepgram']);
        $this->assertSame('cloud:deepgram', CloudAiService::engine('stt', 'tr', false));

        $tokFile = tempnam(sys_get_temp_dir(), 'ai_tok_');
        file_put_contents($tokFile, '0123456789abcdef');
        $prevEnv = getenv('AIPBX_AI_TOKEN_FILE');
        putenv('AIPBX_AI_TOKEN_FILE=' . $tokFile);
        $ref = new ReflectionProperty(LocalAiService::class, 'modelsCache');
        $ref->setValue(null, null);
        TtsHttp::$transport = function (string $method, string $url) {
            if (str_contains($url, '/v1/models')) {
                return ['status' => 200, 'body' => json_encode([
                    'models' => [
                        ['id' => 'vosk-tr-small', 'kind' => 'stt', 'state' => 'ready', 'languages' => ['tr']],
                    ],
                ]), 'type' => 'application/json'];
            }
            return ['status' => 404, 'body' => '{}', 'type' => 'application/json'];
        };
        try {
            $this->assertSame('local:vosk-tr-small', CloudAiService::engine('stt', 'tr'));
        } finally {
            putenv($prevEnv === false ? 'AIPBX_AI_TOKEN_FILE' : 'AIPBX_AI_TOKEN_FILE=' . $prevEnv);
            @unlink($tokFile);
            $ref->setValue(null, null);
        }

        // Clearing provider removes its limits too.
        CloudAiService::save('deepgram', ['clear' => '1']);
        $this->assertSame(['stt_min' => 0, 'tts_chars' => 0, 'llm_tokens' => 0], CloudAiService::limits('deepgram'));
    }
}
