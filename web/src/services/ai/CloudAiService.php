<?php
/**
 * AI → Cloud services: the accounts of cloud AI providers in one place, what
 * each can do (speech, speech to text, language models, embeddings), a
 * connection test, this month's use, and the engine chosen for each job.
 *
 * Keys are stored encrypted in sys_settings as ai_tts.<provider>.<field> — the
 * prefix of the Cloud TTS page, which used them first, so existing accounts
 * keep working unchanged.
 *
 * Choosing a cloud engine sends audio or text to that provider: the page says
 * so next to every provider (privacy, KVKK/GDPR).
 */

require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/secret_box.php';
require_once dirname(__DIR__, 2) . '/audit_log.php';
require_once dirname(__DIR__) . '/AiTtsService.php';

final class CloudAiException extends RuntimeException
{
}

class CloudAiService
{
    public const CAPS = ['tts', 'stt', 'llm', 'embed'];
    public const LANGS = ['tr' => 'tr-TR', 'de' => 'de-DE', 'en' => 'en-US'];

    /**
     * Providers: title, capabilities, settings fields, sign-up page.
     * TTS-capable ones also have a class on the Cloud TTS page (same keys).
     */
    public static function providers(): array
    {
        $tts = fn(string $cls) => $cls::fields();
        $key = [['key' => 'api_key', 'label' => 'API key', 'secret' => true]];
        return [
            'google_ai' => ['title' => 'Google AI Studio (Gemini, Gemma)', 'caps' => ['stt', 'llm', 'embed'], 'fields' => $key,
                            'url' => 'https://aistudio.google.com/apikey', 'free' => true],
            'openai' => ['title' => 'OpenAI', 'caps' => ['tts', 'stt', 'llm', 'embed'], 'fields' => $tts(OpenAiTts::class),
                         'url' => 'https://platform.openai.com/api-keys', 'free' => false],
            'azure' => ['title' => 'Microsoft Azure AI Speech', 'caps' => ['tts', 'stt'], 'fields' => $tts(AzureTts::class),
                        'url' => 'https://portal.azure.com/', 'free' => true],
            'google' => ['title' => 'Google Cloud Text-to-Speech', 'caps' => ['tts'], 'fields' => $tts(GoogleTts::class),
                         'url' => 'https://console.cloud.google.com/apis/credentials', 'free' => true],
            'polly' => ['title' => 'Amazon Polly', 'caps' => ['tts'], 'fields' => $tts(PollyTts::class),
                        'url' => 'https://console.aws.amazon.com/iam/', 'free' => true],
            'elevenlabs' => ['title' => 'ElevenLabs', 'caps' => ['tts', 'stt'], 'fields' => $tts(ElevenLabsTts::class),
                             'url' => 'https://elevenlabs.io/app/settings/api-keys', 'free' => true],
            'deepgram' => ['title' => 'Deepgram', 'caps' => ['stt'], 'fields' => $key,
                           'url' => 'https://console.deepgram.com/', 'free' => true],
            'groq' => ['title' => 'Groq', 'caps' => ['stt', 'llm'], 'fields' => $key,
                       'url' => 'https://console.groq.com/keys', 'free' => true],
            'openrouter' => ['title' => 'OpenRouter', 'caps' => ['llm'], 'fields' => $key,
                             'url' => 'https://openrouter.ai/keys', 'free' => true],
        ];
    }

    public static function provider(string $id): array
    {
        $p = self::providers()[$id] ?? null;
        if ($p === null) {
            throw new CloudAiException('Unknown provider.');
        }
        return $p;
    }

    /** Decrypted settings of a provider (server side only). */
    public static function config(string $id): array
    {
        $cfg = [];
        foreach (self::provider($id)['fields'] as $f) {
            $raw = (string) getSystemSetting("ai_tts.{$id}.{$f['key']}", '');
            $cfg[$f['key']] = $f['secret'] ? SecretBox::decrypt($raw) : ($raw !== '' ? $raw : ($f['default'] ?? ''));
        }
        return $cfg;
    }

    public static function configured(string $id): bool
    {
        $cfg = self::config($id);
        $fields = self::provider($id)['fields'];
        // Google Cloud: either the service account or the API key.
        if ($id === 'google') {
            return trim($cfg['service_account'] ?? '') !== '' || trim($cfg['api_key'] ?? '') !== '';
        }
        foreach ($fields as $f) {
            if (empty($f['optional']) && trim($cfg[$f['key']] ?? '') === '') {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> configured providers that can do $cap */
    public static function withCap(string $cap): array
    {
        return array_values(array_filter(array_keys(self::providers()),
            fn($id) => in_array($cap, self::provider($id)['caps'], true) && self::configured($id)));
    }

    /** The page's view of every provider: secrets only masked. */
    public static function forPage(): array
    {
        $usage = self::usageThisMonth();
        $out = [];
        foreach (self::providers() as $id => $p) {
            $cfg = self::config($id);
            $fields = [];
            foreach ($p['fields'] as $f) {
                $v = $cfg[$f['key']] ?? '';
                $masked = !empty($f['json']) ? (string) (json_decode($v, true)['client_email'] ?? '') : SecretBox::mask($v);
                $fields[] = $f + ['value' => $f['secret'] ? '' : $v, 'masked' => $f['secret'] ? $masked : ''];
            }
            $out[] = ['id' => $id, 'title' => $p['title'], 'caps' => $p['caps'], 'url' => $p['url'], 'free' => $p['free'],
                      'configured' => self::configured($id), 'fields' => $fields, 'usage' => $usage[$id] ?? null];
        }
        return $out;
    }

    public static function save(string $id, array $post): void
    {
        self::provider($id);
        $p = self::provider($id);
        $db = getDB();
        $set = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $del = $db->prepare('DELETE FROM sys_settings WHERE setting_key = ?');
        foreach ($p['fields'] as $f) {
            $key = "ai_tts.{$id}.{$f['key']}";
            if (!empty($post['clear'])) {
                $del->execute([$key]);
                continue;
            }
            $v = trim((string) ($post[$f['key']] ?? ''));
            if ($v !== '' && !empty($f['json']) && $id === 'google') {
                $v = GoogleTts::validateServiceAccount($v);
            }
            if ($f['secret']) {
                if ($v !== '') {
                    $set->execute([$key, SecretBox::encrypt($v)]);
                }
            } elseif ($v === '') {
                $del->execute([$key]);
            } else {
                $set->execute([$key, mb_substr($v, 0, 200)]);
            }
        }
        writeAuditLog('ai_cloud', 'provider', $id, $p['title'] . (!empty($post['clear']) ? ' (credentials removed)' : ' (settings saved)'), 'update', $_SESSION['user_id'] ?? null);
    }

    // ------------------------------------------------------------ connection test

    /** A cheap authenticated call; throws with the provider's own message. */
    public static function test(string $id): string
    {
        $cfg = self::config($id);
        $k = $cfg['api_key'] ?? '';
        switch ($id) {
            case 'google_ai':
                $d = self::json(TtsHttp::request('GET', 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=50', ['x-goog-api-key' => $k], null, 20), $id);
                return sprintf(t('ai_cloud.test_models'), count($d['models'] ?? []));
            case 'openai':
                $d = self::json(TtsHttp::request('GET', 'https://api.openai.com/v1/models', ['Authorization' => 'Bearer ' . $k], null, 20), $id);
                return sprintf(t('ai_cloud.test_models'), count($d['data'] ?? []));
            case 'groq':
                $d = self::json(TtsHttp::request('GET', 'https://api.groq.com/openai/v1/models', ['Authorization' => 'Bearer ' . $k], null, 20), $id);
                return sprintf(t('ai_cloud.test_models'), count($d['data'] ?? []));
            case 'openrouter':
                $d = self::json(TtsHttp::request('GET', 'https://openrouter.ai/api/v1/key', ['Authorization' => 'Bearer ' . $k], null, 20), $id);
                return t('ai_cloud.test_ok') . (isset($d['data']['limit_remaining']) ? ' · ' . $d['data']['limit_remaining'] : '');
            case 'deepgram':
                $d = self::json(TtsHttp::request('GET', 'https://api.deepgram.com/v1/projects', ['Authorization' => 'Token ' . $k], null, 20), $id);
                return sprintf(t('ai_cloud.test_projects'), count($d['projects'] ?? []));
            default:
                // Speech providers: listing the voices proves the key.
                $voices = AiTtsService::voices($id, true);
                return sprintf(t('ai_tts.test_ok'), count($voices));
        }
    }

    private static function json(array $r, string $id): array
    {
        try {
            return TtsHttp::json($r, self::provider($id)['title']);
        } catch (TtsException $e) {
            throw new CloudAiException($e->getMessage());
        }
    }

    // ------------------------------------------------------------ speech to text

    /**
     * Text of a WAV file with a cloud provider.
     * @return array{text: string, ms: int}
     */
    public static function stt(string $id, string $wav, string $lang): array
    {
        if (!in_array('stt', self::provider($id)['caps'], true) || !self::configured($id)) {
            throw new CloudAiException(t('ai_cloud.err_no_stt'));
        }
        $lang = array_key_exists($lang, self::LANGS) ? $lang : 'tr';
        $cfg = self::config($id);
        $k = $cfg['api_key'] ?? '';
        $started = microtime(true);
        switch ($id) {
            case 'openai':
            case 'groq':
                $url = $id === 'openai' ? 'https://api.openai.com/v1/audio/transcriptions' : 'https://api.groq.com/openai/v1/audio/transcriptions';
                $model = $id === 'openai' ? 'gpt-4o-mini-transcribe' : 'whisper-large-v3-turbo';
                [$body, $type] = self::multipart(['model' => $model, 'language' => $lang, 'response_format' => 'json'], 'file', 'speech.wav', $wav);
                $d = self::json(TtsHttp::request('POST', $url, ['Authorization' => 'Bearer ' . $k, 'Content-Type' => $type], $body, 120), $id);
                $text = (string) ($d['text'] ?? '');
                break;
            case 'deepgram':
                $d = self::json(TtsHttp::request('POST', 'https://api.deepgram.com/v1/listen?model=nova-3&smart_format=true&language=' . $lang,
                    ['Authorization' => 'Token ' . $k, 'Content-Type' => 'audio/wav'], $wav, 120), $id);
                $text = (string) ($d['results']['channels'][0]['alternatives'][0]['transcript'] ?? '');
                break;
            case 'azure':
                $region = $cfg['region'] ?: 'westeurope';
                if (!preg_match('/^[a-z0-9]{3,30}$/', $region)) {
                    throw new CloudAiException('Azure: invalid region');
                }
                $d = self::json(TtsHttp::request('POST', "https://{$region}.stt.speech.microsoft.com/speech/recognition/conversation/cognitiveservices/v1?format=simple&language=" . self::LANGS[$lang],
                    ['Ocp-Apim-Subscription-Key' => $k, 'Content-Type' => 'audio/wav; codecs=audio/pcm; samplerate=16000'], $wav, 120), $id);
                $text = (string) ($d['DisplayText'] ?? '');
                break;
            case 'elevenlabs':
                [$body, $type] = self::multipart(['model_id' => 'scribe_v1', 'language_code' => $lang], 'file', 'speech.wav', $wav);
                $d = self::json(TtsHttp::request('POST', 'https://api.elevenlabs.io/v1/speech-to-text', ['xi-api-key' => $k, 'Content-Type' => $type], $body, 120), $id);
                $text = (string) ($d['text'] ?? '');
                break;
            case 'google_ai':
                $prompt = 'Transcribe this telephone audio word for word in ' . ['tr' => 'Turkish', 'de' => 'German', 'en' => 'English'][$lang]
                    . '. Output only the transcript, nothing else. If nothing is said, output nothing.';
                $req = ['contents' => [['parts' => [['text' => $prompt], ['inline_data' => ['mime_type' => 'audio/wav', 'data' => base64_encode($wav)]]]]],
                        'generationConfig' => ['temperature' => 0]];
                $d = self::json(TtsHttp::request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',
                    ['x-goog-api-key' => $k, 'Content-Type' => 'application/json'], json_encode($req), 120), $id);
                $text = trim((string) ($d['candidates'][0]['content']['parts'][0]['text'] ?? ''));
                break;
            default:
                throw new CloudAiException(t('ai_cloud.err_no_stt'));
        }
        $seconds = self::wavSeconds($wav);
        self::count($id, 'stt_seconds', $seconds);
        return ['text' => trim($text), 'ms' => (int) round((microtime(true) - $started) * 1000), 'seconds' => round($seconds, 2)];
    }

    /** @return array{0: string, 1: string} body and Content-Type */
    private static function multipart(array $fields, string $fileField, string $fileName, string $data): array
    {
        $b = '----aipbx' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($fields as $n => $v) {
            $body .= "--{$b}\r\nContent-Disposition: form-data; name=\"{$n}\"\r\n\r\n{$v}\r\n";
        }
        $body .= "--{$b}\r\nContent-Disposition: form-data; name=\"{$fileField}\"; filename=\"{$fileName}\"\r\nContent-Type: audio/wav\r\n\r\n{$data}\r\n--{$b}--\r\n";
        return [$body, 'multipart/form-data; boundary=' . $b];
    }

    public static function wavSeconds(string $wav): float
    {
        if (strlen($wav) < 44 || substr($wav, 0, 4) !== 'RIFF') {
            return 0.0;
        }
        $fmt = strpos($wav, 'fmt ');
        $data = strpos($wav, 'data');
        if ($fmt === false || $data === false) {
            return 0.0;
        }
        $byteRate = unpack('V', substr($wav, $fmt + 16, 4))[1] ?? 0;
        $size = unpack('V', substr($wav, $data + 4, 4))[1] ?? 0;
        return $byteRate > 0 ? min($size, strlen($wav) - $data - 8) / $byteRate : 0.0;
    }

    // ------------------------------------------------------------ usage

    /** Adds to this month's use of a provider (seconds of audio, characters, tokens). */
    public static function count(string $provider, string $unit, float $amount): void
    {
        if (!in_array($unit, ['stt_seconds', 'tts_chars', 'llm_tokens'], true) || $amount <= 0) {
            return;
        }
        try {
            getDB()->prepare("INSERT INTO ai_cloud_usage (provider, month, {$unit}) VALUES (?, ?, ?)
                              ON DUPLICATE KEY UPDATE {$unit} = {$unit} + VALUES({$unit})")
                ->execute([$provider, date('Y-m'), $amount]);
        } catch (\Throwable $e) {
            // Counting never breaks the call itself.
        }
    }

    /** @return array<string, array{stt_seconds: float, tts_chars: int, llm_tokens: int}> */
    public static function usageThisMonth(): array
    {
        try {
            $st = getDB()->prepare('SELECT provider, stt_seconds, tts_chars, llm_tokens FROM ai_cloud_usage WHERE month = ?');
            $st->execute([date('Y-m')]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['provider']] = ['stt_seconds' => (float) $r['stt_seconds'], 'tts_chars' => (int) $r['tts_chars'], 'llm_tokens' => (int) $r['llm_tokens']];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ------------------------------------------------------------ engine per job

    /** Jobs with a choice of engine: speech and speech to text per language. */
    public static function jobs(): array
    {
        $out = [];
        foreach (array_keys(self::LANGS) as $lang) {
            $out[] = ['job' => 'tts', 'lang' => $lang];
            $out[] = ['job' => 'stt', 'lang' => $lang];
        }
        return $out;
    }

    public static function engine(string $job, string $lang): string
    {
        return (string) getSystemSetting("ai_engine.{$job}.{$lang}", '');
    }

    /** Values: "local:<model id>" or "cloud:<provider>". */
    public static function saveEngines(array $post): void
    {
        $db = getDB();
        $set = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach (self::jobs() as $j) {
            $field = "engine_{$j['job']}_{$j['lang']}";
            $v = (string) ($post[$field] ?? '');
            if ($v !== '' && !preg_match('/^(local:[a-z0-9][a-z0-9._-]{1,63}|cloud:[a-z_]{2,20})$/', $v)) {
                continue;
            }
            if (str_starts_with($v, 'cloud:') && !array_key_exists(substr($v, 6), self::providers())) {
                continue;
            }
            $set->execute(["ai_engine.{$j['job']}.{$j['lang']}", $v]);
        }
        writeAuditLog('ai_cloud', 'engines', 0, 'AI engines per job', 'update', $_SESSION['user_id'] ?? null);
    }
}
