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
    /** Language models used when the account names none (cloud_llm.DEFAULT_MODELS). */
    public const LLM_DEFAULTS = ['nvidia' => 'meta/llama-3.3-70b-instruct', 'qwen' => 'qwen-plus'];
    /** International (Singapore) endpoint; mainland China: https://dashscope.aliyuncs.com/compatible-mode/v1 */
    public const QWEN_ENDPOINT = 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1';
    /** Accounts whose Model / address fields are for their language model. */
    public const LLM_FIELDS_OWN = ['nvidia', 'qwen', 'openai_compat'];

    /**
     * Address of an OpenAI-compatible chat API, or null: http(s), no
     * credentials or fragments in it, at most 200 characters.
     */
    public static function validBaseUrl(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '' || strlen($url) > 200 || !preg_match('~^https?://[^\s/?#@]+(/[^\s?#]*)?$~', $url)
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        return $url;
    }

    /**
     * What the AI service needs to ask a provider's language model:
     * provider, key and, where the account has them, model and address.
     */
    public static function llmSettings(string $id): array
    {
        $cfg = self::config($id);
        $out = ['provider' => $id, 'api_key' => (string) ($cfg['api_key'] ?? '')];
        // Only these accounts' "Model" field names a language model; OpenAI's
        // and ElevenLabs' name their speech model (Cloud TTS).
        if (!in_array($id, self::LLM_FIELDS_OWN, true)) {
            return $out;
        }
        foreach (['model', 'base_url'] as $k) {
            if (trim((string) ($cfg[$k] ?? '')) !== '') {
                $out[$k] = trim((string) $cfg[$k]);
            }
        }
        return $out;
    }

    /**
     * Providers: title, capabilities, settings fields, sign-up page.
     * TTS-capable ones also have a class on the Cloud TTS page (same keys).
     */
    public static function providers(): array
    {
        $tts = fn(string $cls) => $cls::fields();
        $key = [['key' => 'api_key', 'label' => 'API key', 'secret' => true]];
        $model = fn(string $default) => ['key' => 'model', 'label' => 'Model', 'secret' => false, 'optional' => true, 'default' => $default];
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
            // OpenAI-compatible chat APIs (cloud_llm.py in the AI service talks to them).
            'nvidia' => ['title' => 'NVIDIA NIM (build.nvidia.com)', 'caps' => ['llm'],
                         'fields' => [$key[0], $model(self::LLM_DEFAULTS['nvidia'])],
                         'url' => 'https://build.nvidia.com/settings/api-keys', 'free' => true],
            'qwen' => ['title' => 'Qwen (Alibaba Cloud Model Studio)', 'caps' => ['llm'],
                       'fields' => [$key[0], $model(self::LLM_DEFAULTS['qwen']),
                                    ['key' => 'base_url', 'label' => 'Endpoint', 'secret' => false, 'optional' => true,
                                     'default' => self::QWEN_ENDPOINT]],
                       'url' => 'https://modelstudio.console.alibabacloud.com/', 'free' => true],
            'openai_compat' => ['title' => 'OpenAI-compatible (own address)', 'caps' => ['llm'],
                                'fields' => [['key' => 'base_url', 'label' => 'Base URL (…/v1)', 'secret' => false],
                                             ['key' => 'model', 'label' => 'Model', 'secret' => false],
                                             ['key' => 'api_key', 'label' => 'API key', 'secret' => true, 'optional' => true]],
                                'url' => 'https://platform.openai.com/docs/api-reference/chat', 'free' => false],
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
            if ($v !== '' && $f['key'] === 'base_url') {
                $v = self::validBaseUrl($v) ?? throw new CloudAiException(t('ai_cloud.err_base_url'));
            }
            if ($v !== '' && $f['key'] === 'model' && !preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,99}$#', $v)) {
                throw new CloudAiException(t('ai_cloud.err_model'));
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
            case 'nvidia':
            case 'qwen':
            case 'openai_compat':
                return self::testChat($id);
            case 'deepgram':
                $d = self::json(TtsHttp::request('GET', 'https://api.deepgram.com/v1/projects', ['Authorization' => 'Token ' . $k], null, 20), $id);
                return sprintf(t('ai_cloud.test_projects'), count($d['projects'] ?? []));
            default:
                // Speech providers: listing the voices proves the key.
                $voices = AiTtsService::voices($id, true);
                return sprintf(t('ai_tts.test_ok'), count($voices));
        }
    }

    /**
     * One tiny chat request: proves address, key and model together
     * (listing models would not show a wrong model name).
     */
    private static function testChat(string $id): string
    {
        [$base, $model, $key] = self::llmTarget($id);
        $headers = ['Content-Type' => 'application/json'];
        if ($key !== '') {
            $headers['Authorization'] = 'Bearer ' . $key;
        }
        $started = microtime(true);
        $d = self::json(TtsHttp::request('POST', $base . '/chat/completions', $headers, (string) json_encode([
            'model' => $model, 'max_tokens' => 5, 'temperature' => 0,
            'messages' => [['role' => 'user', 'content' => 'Reply with OK.']],
        ]), 30), $id);
        if (!isset($d['choices'][0]['message'])) {
            throw new CloudAiException(t('ai_cloud.err_no_answer'));
        }
        return sprintf(t('ai_cloud.test_chat'), $model, (int) round((microtime(true) - $started) * 1000));
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

    // ------------------------------------------------------------ language models

    /** Chat endpoint and model of a provider's language model. */
    private static function llmTarget(string $id): array
    {
        $s = self::llmSettings($id);
        $fixed = ['openai' => ['https://api.openai.com/v1', 'gpt-4.1-mini'],
                  'groq' => ['https://api.groq.com/openai/v1', 'llama-3.3-70b-versatile'],
                  'openrouter' => ['https://openrouter.ai/api/v1', 'google/gemini-2.5-flash'],
                  'nvidia' => ['https://integrate.api.nvidia.com/v1', self::LLM_DEFAULTS['nvidia']],
                  'qwen' => [self::QWEN_ENDPOINT, self::LLM_DEFAULTS['qwen']]];
        [$base, $model] = $fixed[$id] ?? ['', ''];
        return [$s['base_url'] ?? $base, $s['model'] ?? $model, $s['api_key']];
    }

    /**
     * One answer of a cloud language model (temperature 0). Google AI uses
     * Gemini's own API, every other provider the OpenAI chat API.
     */
    public static function chat(string $id, string $prompt, int $maxTokens = 300, int $timeout = 30): string
    {
        if (!in_array('llm', self::provider($id)['caps'], true) || !self::configured($id)) {
            throw new CloudAiException(t('ai_cloud.err_no_llm'));
        }
        if ($id === 'google_ai') {
            $d = self::json(TtsHttp::request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',
                ['x-goog-api-key' => self::config($id)['api_key'] ?? '', 'Content-Type' => 'application/json'],
                (string) json_encode(['contents' => [['parts' => [['text' => $prompt]]]],
                                      'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => $maxTokens]]), $timeout), $id);
            self::count($id, 'llm_tokens', (float) ($d['usageMetadata']['totalTokenCount'] ?? 0));
            return trim((string) ($d['candidates'][0]['content']['parts'][0]['text'] ?? ''));
        }
        [$base, $model, $key] = self::llmTarget($id);
        $headers = ['Content-Type' => 'application/json'];
        if ($key !== '') {
            $headers['Authorization'] = 'Bearer ' . $key;
        }
        $d = self::json(TtsHttp::request('POST', $base . '/chat/completions', $headers, (string) json_encode([
            'model' => $model, 'temperature' => 0, 'max_tokens' => $maxTokens,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]), $timeout), $id);
        self::count($id, 'llm_tokens', (float) ($d['usage']['total_tokens'] ?? 0));
        return trim((string) ($d['choices'][0]['message']['content'] ?? ''));
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
            $out[] = ['job' => 'llm', 'lang' => $lang];
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
