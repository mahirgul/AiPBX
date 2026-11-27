<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ai/AiAppService.php';
require_once __DIR__ . '/../../src/sync/SyncAiApps.php';

/** AI → Applications: validation, text encoding for the dialplan, the generated contexts. */
final class AiAppServiceTest extends TestCase
{
    /** @var array<string, int|false> */
    private array $prevPlugins = [];

    protected function setUp(): void
    {
        $db = getDB();
        foreach (['ailocal', 'aicloud'] as $slug) {
            $prev = $db->query("SELECT enabled FROM sys_plugins WHERE slug = '{$slug}'")->fetchColumn();
            $this->prevPlugins[$slug] = $prev === false ? false : (int) $prev;
            $db->exec("UPDATE sys_plugins SET enabled = 1 WHERE slug = '{$slug}'");
        }
        if (class_exists('PluginManager')) {
            PluginManager::resetCache();
        }
    }

    protected function tearDown(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM pbx_ai_apps WHERE title LIKE 'Test app%'");
        $db->exec("DELETE FROM sys_pending_sync WHERE entity_type = 'ai_app'");
        foreach ($this->prevPlugins as $slug => $prev) {
            if ($prev !== false) {
                $db->prepare("UPDATE sys_plugins SET enabled = ? WHERE slug = ?")->execute([$prev, $slug]);
            }
        }
        if (class_exists('PluginManager')) {
            PluginManager::resetCache();
        }
    }

    private static function form(array $over = []): array
    {
        return $over + ['title' => 'Test app', 'model_id' => 'ema-lightning', 'speed' => '1',
            'text_template' => 'Sayın {caller_name}, borcunuz {amount} liradır.', 'dest_type' => 'hangup',
            'dest_id' => '', 'max_concurrent' => '4', 'is_active' => '1', 'internal_number' => ''];
    }

    public function testValidation(): void
    {
        $v = AiAppService::validate(self::form(['speed' => '9', 'max_concurrent' => '500']));
        $this->assertSame(['2', '50'], [$v['speed'], $v['max_concurrent']]);
        foreach ([['title' => ''], ['model_id' => '../x'], ['text_template' => ''], ['lookup_url' => 'ftp://x/'],
                  ['text_template' => str_repeat('a', 2001)], ['dest_type' => 'Bad Type']] as $bad) {
            try {
                AiAppService::validate(self::form($bad));
                $this->fail('accepted ' . json_encode($bad));
            } catch (\Exception $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        foreach (['engine:tr', 'local_default', 'cloud_default'] as $gone) {       // the default and per-language layers are gone
            try {
                AiAppService::validate(self::form(['model_id' => $gone]));
                $this->fail("accepted {$gone}");
            } catch (\Exception $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testDialplanEncodingKeepsTheParserSafe(): void
    {
        $enc = AiAppService::dialplanEncode('Sayın {caller_name}; borcunuz (1,5) {amount} $ liradır, {did}.');
        $this->assertStringNotContainsString(';', $enc);
        $this->assertStringNotContainsString(',', str_replace('${URIENCODE(${CDR(did)})}', '', $enc));
        $this->assertStringContainsString('%7Bcaller_name%7D', $enc);
        $this->assertStringContainsString('%7Bamount%7D', $enc);   // lookup values stay for the service
        $this->assertSame(1, substr_count($enc, '${URIENCODE(${CDR(did)})}'));
    }

    public function testDialplan(): void
    {
        $conf = buildAiAppsDialplan([['id' => 7, 'title' => 'Borç', 'model_id' => 'ema-lightning', 'speed' => '1.10',
            'max_concurrent' => 3, 'text_template' => 'Merhaba {caller_name}', 'lookup_url' => 'https://crm.example/x?a=1',
            'fallback_text' => '', 'dest_type' => 'hangup', 'dest_id' => '']], str_repeat('ab', 32), false);
        $this->assertStringContainsString('[aipbx-ai-app-7]', $conf);
        $this->assertStringContainsString('Set(AI_CALL=${CURL(http://127.0.0.1:8790/v1/calls/start,app=7&key=' . str_repeat('ab', 32) . '&model=ema-lightning&speed=1.10&max=3&', $conf);
        $this->assertStringContainsString('&lookup=https%3A%2F%2Fcrm.example%2Fx%3Fa%3D1&', $conf);
        $this->assertStringContainsString('AudioSocket(${AI_CALL},127.0.0.1:8791)', $conf);
        $this->assertStringContainsString('Set(CURLOPT(httptimeout)=5)', $conf);
        // Braces and brackets of the text never reach the dialplan raw (Asterisk counts them).
        $odd = buildAiAppsDialplan([['id' => 8, 'title' => 'x', 'model_id' => 'ema-lightning', 'speed' => '1', 'max_concurrent' => 1,
            'text_template' => 'a ( b { c ) d } e', 'lookup_url' => '', 'fallback_text' => '', 'dest_type' => 'hangup', 'dest_id' => '']], 'k');
        $text = explode('&text=', $odd)[1];
        $this->assertSame(0, preg_match('/[(){}]/', explode('&lookup=', $text)[0]));
        $this->assertMatchesRegularExpression('/same => n\(done\),NoOp/', $conf);
        // Without the service's key the calls go straight on.
        $off = buildAiAppsDialplan([['id' => 7, 'title' => 'x', 'model_id' => 'ema-lightning', 'speed' => '1', 'max_concurrent' => 3,
            'text_template' => 'x', 'lookup_url' => '', 'fallback_text' => '', 'dest_type' => 'hangup', 'dest_id' => '']], '');
        $this->assertStringNotContainsString('CURL(', $off);
        $this->assertStringContainsString('[aipbx-ai-app-7]', $off);
    }

    public function testDestinationLinesAndNumber(): void
    {
        $this->assertStringContainsString('Goto(aipbx-ai-app-12,s,1)', buildDestinationLines('ai_app', '12'));
        $_SESSION['user_id'] = null;
        $id = AiAppService::save(self::form(['internal_number' => '7299']));
        $this->assertSame('7299', AiAppService::get($id)['internal_number']);
        $this->expectException(\Exception::class);
        AiAppService::save(self::form(['title' => 'Test app 2', 'internal_number' => '7299']));
    }

    public function testSelfDestinationRefused(): void
    {
        $this->expectException(\Exception::class);
        AiAppService::validate(self::form(['dest_type' => 'ai_app', 'dest_id' => '5']), 5);
    }

    private static function requestsForm(array $over = []): array
    {
        SecretBox::useKey(str_repeat('k', 32));
        CloudAiService::save('openai', ['api_key' => 'sk-requests-test-123']);        // speech to text is a cloud account now
        return self::form($over + ['app_type' => 'voice_requests', 'greeting' => 'Merhaba, nasıl yardımcı olabilirim?',
            'retry_text' => 'Tekrar söyler misiniz?', 'not_understood_text' => 'Resepsiyona aktarıyorum.', 'stt_model' => 'cloud:openai',
            'listen_seconds' => '7', 'retries' => '1', 'threshold' => '0.5', 'text_template' => '',
            'intents' => [
                ['name' => 'Havlu', 'keywords' => 'havlu', 'examples' => "İki havlu daha alabilir miyim?", 'reply' => 'Havlu talebiniz alındı.', 'email' => 'kat@example.com'],
                ['name' => 'Arıza', 'keywords' => 'çalışmıyor, bozuk', 'examples' => '', 'reply' => 'Teknik servis geliyor.', 'dest_type' => 'hangup'],
                ['name' => '', 'keywords' => 'ignored'],
            ]]);
    }

    public function testVoiceRequestsValidation(): void
    {
        $v = AiAppService::validate(self::requestsForm());
        $c = json_decode($v['config'], true);
        $this->assertSame(['havlu', 'ariza'], array_column($c['intents'], 'id'));
        $this->assertSame(['çalışmıyor', 'bozuk'], $c['intents'][1]['keywords']);
        $none = json_decode(AiAppService::validate(self::requestsForm(['intents' => [['name' => 'None', 'keywords' => 'x']]]))['config'], true);
        $this->assertSame('none_request', $none['intents'][0]['id']);
        $this->assertSame('Merhaba, nasıl yardımcı olabilirim?', $v['text_template']);
        foreach ([['intents' => []], ['stt_model' => '../x'], ['greeting' => ''],
                  ['intents' => [['name' => 'X', 'keywords' => '', 'examples' => '']]],
                  ['intents' => [['name' => 'X', 'keywords' => 'a', 'email' => 'not-mail']]]] as $bad) {
            try {
                AiAppService::validate(self::requestsForm($bad));
                $this->fail('accepted ' . json_encode($bad));
            } catch (\Exception $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testVoiceRequestsDialplanAndServiceConfig(): void
    {
        $v = AiAppService::validate(self::requestsForm());
        $app = ['id' => 9, 'title' => 'Resepsiyon'] + $v;
        $conf = buildAiAppsDialplan([$app], str_repeat('cd', 32));
        $this->assertStringContainsString('[aipbx-ai-app-9]', $conf);
        $this->assertStringContainsString('app=9&key=' . str_repeat('cd', 32) . '&max=4&', $conf);
        $this->assertStringNotContainsString('&text=', $conf);
        $this->assertStringContainsString('Set(AI_INTENT=${CURL(http://127.0.0.1:8790/v1/calls/${AI_CALL}/result?key=', $conf);
        $this->assertStringContainsString('System(/usr/local/bin/ai_request.php 9 ${FILTER(0-9a-f-,${AI_CALL})} ', $conf);
        $this->assertStringNotContainsString('CALLERID(name)', $conf);
        $this->assertStringContainsString('GotoIf($["${AI_INTENT}" = "havlu"]?req_havlu)', $conf);
        $this->assertStringContainsString('same => n(req_ariza),NoOp', $conf);
        $this->assertStringContainsString('same => n(fallback),NoOp', $conf);

        $cfg = AiAppService::serviceConfig($app);
        $this->assertSame('voice_requests', $cfg['type']);
        $this->assertSame('cloud:openai', $cfg['stt_model']);
        $this->assertSame(['id', 'name', 'keywords', 'examples', 'reply'], array_keys($cfg['intents'][0]));
        $this->assertFalse($cfg['barge_in']);
        $barge = AiAppService::serviceConfig(['id' => 9, 'title' => 'Resepsiyon'] + AiAppService::validate(self::requestsForm() + ['barge_in' => '1']));
        $this->assertTrue($barge['barge_in']);
    }

    public function testConversationSettingsAndDialplan(): void
    {
        $form = self::requestsForm() + ['conversation' => '1', 'instructions' => 'Open 9-18.', 'max_turns' => '40',
            'tools' => "order | order status | https://crm.example.com/o\n\n"];
        try {
            AiAppService::validate($form);
            $this->fail('a conversation without a cloud model');
        } catch (\Exception $e) {
            $this->assertStringContainsString('cloud', $e->getMessage());
        }
        $c = AiAppService::validateConversation($form, 'cloud:openai');
        $this->assertSame([true, 12, [['name' => 'order', 'description' => 'order status', 'url' => 'https://crm.example.com/o']]],
            [$c['conversation'], $c['max_turns'], $c['tools']]);
        foreach (['x | y', 'Bad Name | y | https://h.example.com', 'a | y | ftp://h', 'a | y | http://127.0.0.1/x',
                  'a | y | http://192.168.1.5/x', 'a | y | http://localhost/x', "a | y | https://h.example.com\na | z | https://h.example.com"] as $bad) {
            try {
                AiAppService::validateConversation(['conversation' => '1', 'tools' => $bad], 'cloud:openai');
                $this->fail('accepted ' . $bad);
            } catch (\Exception $e) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(['conversation' => false], AiAppService::validateConversation([], 'auto'));

        // Without a usable cloud model (not set up, or over its limit) the service gets no conversation.
        $app = ['id' => 9, 'title' => 'Resepsiyon', 'model_id' => 'ema-lightning', 'speed' => '1', 'dest_type' => 'hangup', 'dest_id' => '', 'max_concurrent' => 4,
            'app_type' => 'voice_requests', 'config' => json_encode(['greeting' => 'Hi', 'stt_model' => 'cloud:openai', 'understand' => 'auto',
            'intents' => [], 'conversation' => true, 'tools' => []])];
        $this->assertArrayNotHasKey('conversation', AiAppService::serviceConfig($app));
        $conf = buildAiAppsDialplan([$app], str_repeat('cd', 32));
        $this->assertStringContainsString('GotoIf($["${AI_INTENT}" = "_end"]?conv_end)', $conf);
        $this->assertStringContainsString('same => n(conv_end),NoOp(AI application 9: conversation ended)', $conf);
        $this->assertStringContainsString('&context=${URIENCODE(${AI_SUMMARY})}', $conf);
        $this->assertStringContainsString('Set(__AI_SUMMARY=${CURL(http://127.0.0.1:8790/v1/calls/${AI_CALL}/summary?key=', $conf);
    }

    public function testPushAppsPutsAndDeletes(): void
    {
        $tok = tempnam(sys_get_temp_dir(), 'tok');
        file_put_contents($tok, str_repeat('ef', 32));
        putenv('AIPBX_AI_TOKEN_FILE=' . $tok);
        $calls = [];
        TtsHttp::$transport = function ($method, $url, $headers, $body) use (&$calls) {
            $calls[] = $method . ' ' . parse_url($url, PHP_URL_PATH);
            return ['status' => 200, 'body' => $method === 'GET' ? '{"apps":[{"id":3},{"id":5}]}' : '{}', 'type' => 'application/json'];
        };
        try {
            LocalAiService::pushApps([5 => ['type' => 'voice_requests'], 7 => ['type' => 'voice_requests']]);
        } finally {
            TtsHttp::$transport = null;
            putenv('AIPBX_AI_TOKEN_FILE');
            unlink($tok);
        }
        $this->assertSame(['GET /v1/apps', 'PUT /v1/apps/5', 'PUT /v1/apps/7', 'DELETE /v1/apps/3'], $calls);
    }

    public function testCloudSpeechToTextUsesScopedCredentialReference(): void
    {
        $db = getDB();
        $before = $db->query("SELECT setting_key, setting_value FROM sys_settings WHERE setting_key LIKE 'ai_tts.deepgram.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        try {
            CloudAiService::save('deepgram', ['api_key' => 'dg-test-key-123']);
            $v = AiAppService::validate(self::requestsForm(['stt_model' => 'cloud:deepgram', 'stt_lang' => 'de']));
            $cfg = AiAppService::serviceConfig(['id' => 1, 'title' => 'x', 'model_id' => 'ema-lightning', 'speed' => '1'] + $v);
            $this->assertSame('cloud:deepgram', $cfg['stt_model']);
            $this->assertSame(['provider' => 'deepgram', 'secret_ref' => 'app:1:stt:deepgram'], $cfg['stt_cloud']);
            $this->expectException(\Exception::class);
            AiAppService::validate(self::requestsForm(['stt_model' => 'cloud:openrouter']));   // no speech to text
        } finally {
            $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'ai_tts.deepgram.%'");
            $st = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)');
            foreach ($before as $k => $val) {
                $st->execute([$k, $val]);
            }
        }
    }

    public function testCloudTextToSpeechPayloadAndQuotaFallback(): void
    {
        $db = getDB();
        $before = $db->query("SELECT setting_key, setting_value FROM sys_settings WHERE setting_key LIKE 'ai_tts.openai.%' OR setting_key LIKE 'ai_limit.openai.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $tok = tempnam(sys_get_temp_dir(), 'tok');
        file_put_contents($tok, str_repeat('ab', 16));
        $prevEnv = getenv('AIPBX_AI_TOKEN_FILE');
        putenv('AIPBX_AI_TOKEN_FILE=' . $tok);
        $ref = new ReflectionProperty(LocalAiService::class, 'modelsCache');
        $ref->setValue(null, null);
        TtsHttp::$transport = function (string $method, string $url) {
            if (str_contains($url, '/v1/models')) {
                return ['status' => 200, 'body' => json_encode([
                    'models' => [
                        ['id' => 'piper-tr-fahrettin', 'kind' => 'tts', 'state' => 'ready', 'languages' => ['tr']],
                        ['id' => 'vosk-tr-small', 'kind' => 'stt', 'state' => 'ready', 'languages' => ['tr']],
                    ],
                ]), 'type' => 'application/json'];
            }
            return ['status' => 404, 'body' => '{}', 'type' => 'application/json'];
        };
        try {
            CloudAiService::save('openai', ['api_key' => 'sk-openai-test-123', 'model' => 'gpt-4o-mini-tts']);
            $v = AiAppService::validate(self::requestsForm(['model_id' => 'cloud:openai', 'stt_lang' => 'tr']));
            $cfg = AiAppService::serviceConfig(['id' => 2, 'title' => 'CloudApp', 'speed' => '1'] + $v);
            $this->assertSame('cloud:openai', $cfg['tts_model']);
            $this->assertSame('tr', $cfg['language']);
            $this->assertSame(['provider' => 'openai', 'secret_ref' => 'app:2:tts:openai'], $cfg['tts_cloud']);

            // Announcement resolveModel($model) keeps $allowCloud = false: no cloud voice, and no other voice instead.
            $this->assertSame('', AiAppService::resolveModel('cloud:openai'));

            // When OpenAI TTS quota is exhausted the app has no voice (it is not ready), never another one.
            CloudAiService::save('openai', ['limit_tts_chars' => '100']);
            CloudAiService::count('openai', 'tts_chars', 100);
            $fallbackCfg = AiAppService::serviceConfig(['id' => 2, 'title' => 'CloudApp', 'speed' => '1'] + $v);
            $this->assertSame('', $fallbackCfg['tts_model']);
            $this->assertArrayNotHasKey('tts_cloud', $fallbackCfg);

            $this->expectException(\Exception::class);
            AiAppService::validate(self::requestsForm(['model_id' => 'cloud:deepgram'])); // deepgram has no TTS
        } finally {
            TtsHttp::$transport = null;
            putenv($prevEnv === false ? 'AIPBX_AI_TOKEN_FILE' : 'AIPBX_AI_TOKEN_FILE=' . $prevEnv);
            @unlink($tok);
            $ref->setValue(null, null);
            $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'ai_tts.openai.%' OR setting_key LIKE 'ai_limit.openai.%'");
            $db->exec("DELETE FROM ai_cloud_usage WHERE provider = 'openai'");
            $st = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)');
            foreach ($before as $k => $val) {
                $st->execute([$k, $val]);
            }
        }
    }

    public function testBuildAiAppsDialplanSupportsAriStasisAndAudioSocketFallback(): void
    {
        $apps = [
            ['id' => 11, 'title' => 'Announce', 'app_type' => 'announcement', 'model_id' => 'piper-tr-fahrettin', 'speed' => '1', 'text_template' => 'Hi', 'lookup_url' => '', 'fallback_text' => '', 'max_concurrent' => 2, 'dest_type' => 'hangup', 'dest_id' => ''],
        ];
        $key = str_repeat('a', 32);

        $ariDialplan = buildAiAppsDialplan($apps, $key, true);
        $this->assertStringContainsString('Stasis(aipbx-ai,11,${AI_CALL})', $ariDialplan);
        $this->assertStringNotContainsString('AudioSocket(${AI_CALL}', $ariDialplan);

        $legacyDialplan = buildAiAppsDialplan($apps, $key, false);
        $this->assertStringContainsString('AudioSocket(${AI_CALL},127.0.0.1:8791)', $legacyDialplan);
        $this->assertStringNotContainsString('Stasis(aipbx-ai,', $legacyDialplan);
    }

    public function testCallTextForTheRequestLogAndMail(): void
    {
        $call = ['summary' => 'Wants a room', 'transcripts' => ['hello', ''],
                 'conversation' => [['role' => 'caller', 'text' => 'hello'], ['role' => 'line', 'text' => 'the caller said nothing'],
                                    ['role' => 'assistant', 'text' => 'How can I help?'], ['role' => 'lookup', 'text' => 'x']]];
        $this->assertSame('Wants a room / hello', AiAppService::callText($call, false, 'en'));
        $this->assertSame('Wants a room / Caller: hello / Agent: How can I help?', AiAppService::callText($call, true, 'en'));
        $this->assertSame('Arayan: hello / Ajan: How can I help?', AiAppService::callText(['conversation' => $call['conversation']], true, 'tr'));
    }

    public function testWizardBarLeadsBackFromTheStepPages(): void
    {
        require_once dirname(__DIR__, 2) . '/src/services/ai/BuiltinAiAgentService.php';
        $saved = [$_SESSION['ai_wizard_return'] ?? null, $_GET['wizard_bar'] ?? null];
        try {
            unset($_SESSION['ai_wizard_return'], $_GET['wizard_bar']);
            $this->assertNull(BuiltinAiAgentService::wizardBar('/ai-local'));          // the wizard was never opened
            $_SESSION['ai_wizard_return'] = 1;
            $this->assertNull(BuiltinAiAgentService::wizardBar('/extensions'));        // not a page of the wizard
            $bar = BuiltinAiAgentService::wizardBar('/ai-local/');
            if ($bar !== null) {                                                     // null only when every step is done
                $this->assertSame(7, $bar['total']);
                $this->assertArrayHasKey($bar['step'], BuiltinAiAgentService::wizardSteps());
                $this->assertLessThan($bar['total'], $bar['done']);
            }
            $_GET['wizard_bar'] = 'off';                                             // the close link
            $this->assertNull(BuiltinAiAgentService::wizardBar('/ai-local'));
            $this->assertArrayNotHasKey('ai_wizard_return', $_SESSION);
        } finally {
            [$flag, $get] = $saved;
            unset($_SESSION['ai_wizard_return'], $_GET['wizard_bar']);
            if ($flag !== null) { $_SESSION['ai_wizard_return'] = $flag; }
            if ($get !== null) { $_GET['wizard_bar'] = $get; }
        }
    }

    public function testBuiltinAgentListeningSettingsAreClamped(): void
    {
        require_once dirname(__DIR__, 2) . '/src/services/ai/BuiltinAiAgentService.php';
        // an agent saved before these settings: asks twice, the standard detectors, and a goodbye ends the call
        $this->assertEquals(['retries' => 2, 'silence_seconds' => 4, 'listen_seconds' => 10, 'line_profile' => 'normal',
                             'barge_profile' => 'normal', 'end_on_goodbye' => true,
                             'speech_detection' => 'standard', 'end_of_turn' => 'fixed'], BuiltinAiAgentService::listening([]));
        $saved = BuiltinAiAgentService::listening(['retries' => '0', 'silence_seconds' => '99', 'listen_seconds' => '1',
                                                    'line_profile' => 'faint',
                                                    'speech_detection' => 'silero', 'end_of_turn' => 'smart', 'end_on_goodbye' => '1']);
        $this->assertEquals(['retries' => 0, 'silence_seconds' => 10, 'listen_seconds' => 5, 'line_profile' => 'faint',
                             'barge_profile' => 'normal', 'end_on_goodbye' => true,
                             'speech_detection' => 'silero', 'end_of_turn' => 'smart'], $saved);
        $noisy = BuiltinAiAgentService::listening(['silence_seconds' => '6', 'line_profile' => 'noisy']);
        $this->assertSame(['noisy', 'noisy'], [$noisy['line_profile'], $noisy['barge_profile']]);
        $this->assertSame('faint', BuiltinAiAgentService::listening(['line_profile' => 'noisy', 'barge_profile' => 'faint'])['barge_profile']);
        $this->assertSame('normal', BuiltinAiAgentService::listening(['silence_seconds' => '6', 'line_profile' => 'loud'])['line_profile']);
        // a saved form with the box cleared keeps it off; unknown detector names fall back to the standard ones
        $off = BuiltinAiAgentService::listening(['silence_seconds' => '4']);
        $this->assertSame([false, 'standard', 'fixed'], [$off['end_on_goodbye'], $off['speech_detection'], $off['end_of_turn']]);
        $odd = BuiltinAiAgentService::listening(['silence_seconds' => '4', 'speech_detection' => 'x', 'end_of_turn' => 'y']);
        $this->assertSame(['standard', 'fixed'], [$odd['speech_detection'], $odd['end_of_turn']]);
    }

    public function testBuiltinAgentStatsCsvAndWizard(): void
    {
        require_once dirname(__DIR__, 2) . '/src/services/ai/BuiltinAiAgentService.php';
        $db = getDB();
        $db->beginTransaction();
        try {
            $db->exec("INSERT INTO pbx_ai_apps (title, app_type, model_id, dest_type, dest_id, is_active, config)
                       VALUES ('Stats agent', 'builtin_agent', 'local:x', 'hangup', '', 1, '{}')");
            $id = (int) $db->lastInsertId();
            $insert = $db->prepare('INSERT INTO ai_requests (app_id, call_uuid, caller, transcript, result, turns) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ([['matched', 3, 'a'], ['ended', 2, 'b'], ['silence', 0, 'c'], ['matched', 1, 'd']] as $i => [$result, $turns, $said]) {
                $insert->execute([$id, sprintf('00000000-0000-0000-0000-%012d', 900 + $i), $i === 1 ? '=1+2' : '100', $said, $result, $turns]);
            }
            $s = BuiltinAiAgentService::stats()[$id];
            $this->assertSame([4, 2, 1, 1, 0], [$s['calls'], $s['handed'], $s['ended'], $s['missed'], $s['hangup']]);
            $this->assertEquals(2.0, $s['turns']);                          // the silent call does not count
            $csv = BuiltinAiAgentService::callsCsv();
            $this->assertSame('conversation', $csv[0][7]);
            $this->assertContains("'=1+2", array_column($csv, 2));          // no spreadsheet formula
            $steps = BuiltinAiAgentService::wizardSteps();
            $this->assertSame(['plugins', 'models', 'voice', 'knowledge', 'agent', 'number', 'test'], array_keys($steps));
            $this->assertTrue($steps['agent']['done']);
            $this->assertTrue($steps['test']['done']);                      // a call with turns
        } finally {
            $db->rollBack();
        }
    }

    public function testRealtimeConfigPayloadWhenOpenAiEnabled(): void
    {
        $db = getDB();
        $before = $db->query("SELECT setting_key, setting_value FROM sys_settings WHERE setting_key LIKE 'ai_tts.openai.%' OR setting_key LIKE 'ai_tts.google_ai.%'")->fetchAll(\PDO::FETCH_KEY_PAIR);
        SecretBox::useKey(str_repeat('k', 32));
        try {
            CloudAiService::save('openai', ['api_key' => 'sk-test-realtime-123', 'realtime_voice' => 'coral']);   // the voice is the account's
            $cfg = AiAppService::serviceConfig([
                'id' => 99,
                'title' => 'RT Agent',
                'app_type' => AiAppService::BUILTIN_AGENT,
                'model_id' => 'local:piper-tr-fahrettin',
                'speed' => '1',
                'config' => json_encode(['mode' => 'realtime', 'conversation' => true, 'realtime_account' => 'cloud:openai', 'realtime_voice' => 'coral']),
            ]);
            $this->assertTrue($cfg['builtin_agent']);
            $this->assertTrue($cfg['conversation']);
            // saved before the listening settings: asks again twice, with a retry and a goodbye sentence
            $this->assertSame([2, 4, 10, 'normal'], [$cfg['retries'], $cfg['silence_seconds'], $cfg['listen_seconds'], $cfg['line_profile']]);
            $this->assertNotSame('', $cfg['retry']);
            $this->assertNotSame('', $cfg['goodbye']);
            $this->assertTrue($cfg['end_on_goodbye']);
            $saved = AiAppService::serviceConfig(['id' => 99, 'title' => 'RT Agent', 'app_type' => AiAppService::BUILTIN_AGENT,
                'model_id' => 'local:piper-tr-fahrettin', 'speed' => '1',
                'config' => json_encode(['conversation' => true, 'goodbye' => 'Hoşça kalın.', 'retry' => 'Efendim?'])]);
            $this->assertSame(['Hoşça kalın.', 'Efendim?'], [$saved['goodbye'], $saved['retry']]);   // a saved sentence reaches the service
            $this->assertSame('openai', $cfg['realtime_cloud']['provider'] ?? null);
            $this->assertSame('gpt-realtime', $cfg['realtime_cloud']['model'] ?? null);
            $this->assertSame('coral', $cfg['realtime_cloud']['voice'] ?? null);
            $this->assertSame('app:99:realtime:openai', $cfg['realtime_cloud']['secret_ref'] ?? null);
            $this->assertArrayNotHasKey('api_key', $cfg['realtime_cloud']);
            $resolved = AiAppService::serviceConfig([
                'id' => 99, 'title' => 'RT Agent', 'app_type' => AiAppService::BUILTIN_AGENT, 'model_id' => 'realtime',
                'speed' => '1', 'config' => json_encode(['mode' => 'realtime', 'conversation' => true, 'realtime_account' => 'cloud:openai']),
            ], true);
            $this->assertSame('sk-test-realtime-123', $resolved['realtime_cloud']['api_key'] ?? null);
            CloudAiService::save('openai', ['realtime_model' => 'gpt-realtime-mini']);
            $overridden = AiAppService::serviceConfig([
                'id' => 99, 'title' => 'RT Agent', 'app_type' => AiAppService::BUILTIN_AGENT, 'model_id' => 'realtime',
                'speed' => '1',
                'config' => json_encode(['mode' => 'realtime', 'conversation' => true, 'realtime_account' => 'cloud:openai']),
            ]);
            $this->assertSame('gpt-realtime-mini', $overridden['realtime_cloud']['model']);
            $this->assertSame('app:99:realtime:openai', $overridden['realtime_cloud']['secret_ref'] ?? null);
            $this->assertArrayNotHasKey('api_key', $overridden['realtime_cloud']);

            require_once dirname(__DIR__, 2) . '/src/services/ai/BuiltinAiAgentService.php';
            CloudAiService::save('google_ai', ['api_key' => 'g-test-realtime-123']);
            $rtAccounts = BuiltinAiAgentService::realtimeAccounts();
            $this->assertArrayHasKey('cloud:openai', $rtAccounts);
            $this->assertArrayHasKey('cloud:google_ai', $rtAccounts);
            $gCfg = AiAppService::serviceConfig([
                'id' => 100,
                'title' => 'Gemini RT Agent',
                'app_type' => AiAppService::BUILTIN_AGENT, 'model_id' => 'realtime',
                'speed' => '1',
                'config' => json_encode(['mode' => 'realtime', 'conversation' => true, 'realtime_account' => 'cloud:google_ai', 'realtime_voice' => 'coral']),
            ]);
            $this->assertSame('google_ai', $gCfg['realtime_cloud']['provider'] ?? null);
            $this->assertSame('gemini-3.8-live', $gCfg['realtime_cloud']['model'] ?? null);
        } finally {
            SecretBox::useKey(null);
            $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'ai_tts.openai.%' OR setting_key LIKE 'ai_tts.google_ai.%'");
            $st = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)');
            foreach ($before as $k => $val) {
                $st->execute([$k, $val]);
            }
        }
    }
}
