<?php
/**
 * Calls to a cloud AI provider: speech to text, one answer of a language model, the model list, and the connection the
 * AI gateway uses. Counts what it uses with AiUsageMeter. Above the account store, below the gateway.
 */

require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/secret_box.php';
require_once dirname(__DIR__, 2) . '/audit_log.php';
require_once dirname(__DIR__) . '/tts/TtsProviders.php';
require_once __DIR__ . '/CloudAiException.php';
require_once __DIR__ . '/AiLanguages.php';
require_once __DIR__ . '/AiEndpointGuard.php';
require_once __DIR__ . '/AiUsageMeter.php';
require_once __DIR__ . '/AiProviderManifest.php';
require_once __DIR__ . '/AiAccountStore.php';

final class AiProviderClient
{
    /**
     * Text of a WAV file with a cloud provider.
     * @return array{text: string, ms: int}
     */
    public static function stt(string $id, string $wav, string $lang): array
    {
        if (!in_array('stt', AiAccountStore::provider($id)['caps'], true) || !AiAccountStore::configured($id)) {
            throw new CloudAiException(t('ai_cloud.err_no_stt'));
        }
        if (AiUsageMeter::overLimit($id, 'stt')) {
            throw new CloudAiException(t('ai_cloud.err_quota_exceeded'));
        }
        $lang = AiLanguages::resolve($lang);
        $cfg = AiAccountStore::config($id);
        $k = $cfg['api_key'] ?? '';
        $cfgSttModel = trim((string) ($cfg['stt_model'] ?? ''));
        if ($cfgSttModel !== '' && !preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,99}$#D', $cfgSttModel)) {
            throw new CloudAiException(t('ai_cloud.err_model'));
        }
        $started = microtime(true);
        $type = AiAccountStore::typeOf($id);
        switch ($type) {
            case 'openai':
            case 'groq':
                $url = $type === 'openai' ? 'https://api.openai.com/v1/audio/transcriptions' : 'https://api.groq.com/openai/v1/audio/transcriptions';
                $model = $cfgSttModel !== '' ? $cfgSttModel : AiProviderManifest::sttModel($type);
                [$body, $type] = self::multipart(['model' => $model, 'language' => $lang, 'response_format' => 'json'], 'file', 'speech.wav', $wav);
                $d = self::json(TtsHttp::request('POST', $url, ['Authorization' => 'Bearer ' . $k, 'Content-Type' => $type], $body, 120), $id);
                $text = (string) ($d['text'] ?? '');
                break;
            case 'openai_compat':
                $base = rtrim((string) ($cfg['base_url'] ?? ''), '/');
                $model = $cfgSttModel ?: (trim((string) ($cfg['model'] ?? '')) ?: 'whisper-1');
                [$body, $type] = self::multipart(['model' => $model, 'language' => $lang, 'response_format' => 'json'], 'file', 'speech.wav', $wav);
                $headers = ['Content-Type' => $type];
                if ($k !== '') {
                    $headers['Authorization'] = 'Bearer ' . $k;
                }
                $d = self::json(TtsHttp::request('POST', $base . '/audio/transcriptions', $headers, $body, 120), $id);
                $text = (string) ($d['text'] ?? '');
                break;
            case 'deepgram':
                $dgModel = $cfgSttModel !== '' ? $cfgSttModel : AiProviderManifest::sttModel('deepgram');
                $d = self::json(TtsHttp::request('POST', 'https://api.deepgram.com/v1/listen?model=' . rawurlencode($dgModel) . '&smart_format=true&language=' . rawurlencode($lang),
                    ['Authorization' => 'Token ' . $k, 'Content-Type' => 'audio/wav'], $wav, 120), $id);
                $text = (string) ($d['results']['channels'][0]['alternatives'][0]['transcript'] ?? '');
                break;
            case 'azure':
                $region = $cfg['region'] ?: 'westeurope';
                if (!preg_match('/^[a-z0-9]{3,30}$/', $region)) {
                    throw new CloudAiException('Azure: invalid region');
                }
                $d = self::json(TtsHttp::request('POST', "https://{$region}.stt.speech.microsoft.com/speech/recognition/conversation/cognitiveservices/v1?format=simple&language=" . rawurlencode(AiLanguages::localeOf($lang)),
                    ['Ocp-Apim-Subscription-Key' => $k, 'Content-Type' => 'audio/wav; codecs=audio/pcm; samplerate=16000'], $wav, 120), $id);
                $text = (string) ($d['DisplayText'] ?? '');
                break;
            case 'elevenlabs':
                $elModel = $cfgSttModel !== '' ? $cfgSttModel : AiProviderManifest::sttModel('elevenlabs');
                [$body, $type] = self::multipart(['model_id' => $elModel, 'language_code' => $lang], 'file', 'speech.wav', $wav);
                $d = self::json(TtsHttp::request('POST', 'https://api.elevenlabs.io/v1/speech-to-text', ['xi-api-key' => $k, 'Content-Type' => $type], $body, 120), $id);
                $text = (string) ($d['text'] ?? '');
                break;
            case 'google_ai':
                $gSttModel = $cfgSttModel !== '' ? $cfgSttModel : AiProviderManifest::llmModel('google_ai');
                $prompt = 'Transcribe this telephone audio word for word in ' . AiLanguages::nameOf($lang)
                    . '. Output only the transcript, nothing else. If nothing is said, output nothing.';
                $req = ['contents' => [['parts' => [['text' => $prompt], ['inline_data' => ['mime_type' => 'audio/wav', 'data' => base64_encode($wav)]]]]],
                        'generationConfig' => ['temperature' => 0]];
                $d = self::json(TtsHttp::request('POST', AiProviderManifest::llmUrl('google_ai', $gSttModel),
                    ['x-goog-api-key' => $k, 'Content-Type' => 'application/json'], json_encode($req), 120), $id);
                $text = trim((string) ($d['candidates'][0]['content']['parts'][0]['text'] ?? ''));
                break;
            default:
                throw new CloudAiException(t('ai_cloud.err_no_stt'));
        }
        $seconds = self::wavSeconds($wav);
        AiUsageMeter::count($id, 'stt_seconds', $seconds);
        return ['text' => trim($text), 'ms' => (int) round((microtime(true) - $started) * 1000), 'seconds' => round($seconds, 2)];
    }

    /**
     * One answer of a cloud language model (temperature 0, except Claude). Google AI uses
     * Gemini's own API, every other provider the OpenAI chat API.
     */
    public static function chat(string $id, string $prompt, int $maxTokens = 300, int $timeout = 30): string
    {
        if (!in_array('llm', AiAccountStore::provider($id)['caps'], true) || !AiAccountStore::configured($id)) {
            throw new CloudAiException(t('ai_cloud.err_no_llm'));
        }
        if (!AiAccountStore::isLoopbackOllama($id) && AiUsageMeter::overLimit($id, 'llm')) {
            throw new CloudAiException(t('ai_cloud.err_quota_exceeded'));
        }
        $type = AiAccountStore::typeOf($id);
        if ($type === 'google_ai') {
            $gCfg = AiAccountStore::config($id);
            $gModel = trim((string) ($gCfg['llm_model'] ?? '')) ?: AiProviderManifest::llmModel('google_ai');
            if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,99}$#D', $gModel)) {
                throw new CloudAiException(t('ai_cloud.err_model'));
            }
            $d = self::json(TtsHttp::request('POST', AiProviderManifest::llmUrl('google_ai', $gModel),
                ['x-goog-api-key' => $gCfg['api_key'] ?? '', 'Content-Type' => 'application/json'],
                (string) json_encode(['contents' => [['parts' => [['text' => $prompt]]]],
                                      'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => $maxTokens]]), $timeout), $id);
            AiUsageMeter::count($id, 'llm_tokens', (float) ($d['usageMetadata']['totalTokenCount'] ?? 0));
            return trim((string) ($d['candidates'][0]['content']['parts'][0]['text'] ?? ''));
        }
        [$base, $model, $key] = self::llmTarget($id);
        if ($type === 'claude') {
            $d = self::json(TtsHttp::request('POST', $base . '/messages', [
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ], (string) json_encode([
                // Current Claude models refuse temperature 0 and count their thinking toward max_tokens.
                'model' => $model,
                'max_tokens' => max(2048, $maxTokens),
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ] + (preg_match('/^claude-(haiku|sonnet|opus|fable|mythos)-5/', $model) ? ['output_config' => ['effort' => 'low']] : [])),
                $timeout), $id);
            $usage = (float) (($d['usage']['input_tokens'] ?? 0) + ($d['usage']['output_tokens'] ?? 0));
            AiUsageMeter::count($id, 'llm_tokens', $usage);
            foreach ((array) ($d['content'] ?? []) as $block) {
                if (is_array($block) && ($block['type'] ?? 'text') === 'text') {
                    return trim((string) ($block['text'] ?? ''));
                }
            }
            return '';
        }
        $headers = ['Content-Type' => 'application/json'];
        if ($key !== '') {
            $headers['Authorization'] = 'Bearer ' . $key;
        }
        $d = self::json(TtsHttp::request('POST', $base . '/chat/completions', $headers, (string) json_encode([
            'model' => $model, 'temperature' => 0, 'max_tokens' => $maxTokens,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]), $timeout), $id);
        AiUsageMeter::count($id, 'llm_tokens', (float) ($d['usage']['total_tokens'] ?? 0));
        return trim((string) ($d['choices'][0]['message']['content'] ?? ''));
    }

    /** Chat endpoint and model of a provider's language model. */
    private static function llmTarget(string $id, array $post = [], bool $isNew = false): array
    {
        $type = AiAccountStore::typeOf($id);
        $s = $isNew ? [] : AiAccountStore::llmSettings($id);
        $base = AiProviderManifest::llmBase($type);
        $model = AiProviderManifest::llmModel($type);
        $storedBase = (string) ($s['base_url'] ?? '');
        if ($storedBase !== '') {
            $base = $storedBase;
        }
        if (!empty($s['model'])) {
            $model = (string) $s['model'];
        }
        $key = $isNew ? '' : (string) ($s['api_key'] ?? '');
        $ownAddress = AiProviderManifest::ownAddress($type);
        $allowPrivate = AiProviderManifest::privateOk($type);
        // Form fields typed but not yet saved win over the stored account,
        // validated exactly as Save validates them.
        $typedBase = $ownAddress ? trim((string) ($post['base_url'] ?? '')) : '';
        $typedKey = trim((string) ($post['api_key'] ?? ''));
        if ($typedBase !== '') {
            $typedBase = AiEndpointGuard::validBaseUrl($typedBase, $allowPrivate) ?? throw new CloudAiException(t('ai_cloud.err_base_url'));
            if (!$isNew && $typedKey === '' && rtrim($typedBase, '/') !== rtrim($base, '/')) {
                $key = '';
            }
            $base = $typedBase;
        }
        if (in_array($type, AiProviderCatalog::LLM_FIELDS_OWN, true)) {
            $typedModel = trim((string) ($post['model'] ?? ''));
        } elseif (array_key_exists('llm_model', $post) || $type === 'openai') {
            $typedModel = trim((string) ($post['llm_model'] ?? ''));
        } else {
            $typedModel = trim((string) ($post['model'] ?? ''));
        }
        if ($typedModel !== '') {
            if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,99}$#', $typedModel)) {
                throw new CloudAiException(t('ai_cloud.err_model'));
            }
            $model = $typedModel;
        }
        if ($typedKey !== '') {
            $key = $typedKey;
        }
        return [$base, $model, $key];
    }

    /** Credential resolver bridge; never expose this array to browser/worker job JSON. */
    public static function gatewayConfig(string $id, array $post = []): array
    {
        $isNew = !empty($post['_is_new']);
        $type = AiAccountStore::typeOf($id);
        if ($type === 'google_ai') {
            $cfg = $isNew ? [] : AiAccountStore::config($id);
            if (trim((string) ($post['api_key'] ?? '')) !== '') { $cfg['api_key'] = trim((string) ($post['api_key'] ?? '')); }
            $gModel = trim((string) ($post['llm_model'] ?? '')) ?: (trim((string) ($cfg['llm_model'] ?? '')) ?: AiProviderManifest::llmModel('google_ai'));
            if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,99}$#D', $gModel)) {
                throw new CloudAiException(t('ai_cloud.err_model'));
            }
            return ['adapter' => 'google', 'model' => $gModel,
                'url' => AiProviderManifest::llmUrl('google_ai', $gModel),
                'api_key' => $cfg['api_key'] ?? ''];
        }
        [$base, $model, $key] = self::llmTarget($id, $post, $isNew);
        if ($type === 'claude') {
            return ['adapter' => 'anthropic', 'model' => $model, 'url' => $base . '/messages',
                'api_key' => $key, 'allow_private' => false];
        }
        return ['adapter' => 'openai', 'model' => $model, 'url' => $base . '/chat/completions',
            'api_key' => $key, 'allow_private' => AiProviderManifest::privateOk($type)];
    }

    /**
     * Discovers available models for a configured LLM/embedding provider, cached for 15 minutes.
     * `kinds`: what each model is for (llm, stt, tts, realtime, embed, other), so that the account form offers
     * each model field only the models that fit it.
     * @return array{models: list<string>, kinds: array<string, string>, cached: bool}
     */
    public static function discoverModels(string $id, array $post = [], bool $refresh = false, bool $isNew = false): array
    {
        if (!in_array('llm', AiAccountStore::provider($id)['caps'], true)) {
            return ['models' => [], 'kinds' => [], 'cached' => false];      // no model list to ask (speech-only providers)
        }
        $cacheKey = 'ai_cloud_cache.' . $id;
        [$base, , $key] = self::llmTarget($id, $post, $isNew);
        $fingerprint = hash('sha256', $id . '|' . $base . '|' . $key);     // another key: another list
        if (!$isNew && !$refresh && function_exists('getSystemSetting')) {
            $raw = json_decode((string) getSystemSetting($cacheKey, ''), true);
            if (is_array($raw) && ($raw['fp'] ?? '') === $fingerprint && (int) ($raw['exp'] ?? 0) > time()
                && is_array($raw['models'] ?? null) && is_array($raw['kinds'] ?? null)) {
                return ['models' => array_values($raw['models']), 'kinds' => $raw['kinds'], 'cached' => true];
            }
        }

        $models = [];
        $methods = [];      // Google: what each model supports (generateContent, bidiGenerateContent, embedContent)
        $type = AiAccountStore::typeOf($id);
        $allowPrivate = AiProviderManifest::privateOk($type);
        if ($type === 'google_ai') {
            $k = trim((string) ($post['api_key'] ?? '')) ?: ($isNew ? '' : (AiAccountStore::config($id)['api_key'] ?? ''));
            $d = self::json(TtsHttp::request('GET', 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000', ['x-goog-api-key' => $k], null, 20), $id);
            foreach ((array) ($d['models'] ?? []) as $m) {
                $name = (string) ($m['name'] ?? '');
                if ($name !== '') {
                    $name = str_starts_with($name, 'models/') ? substr($name, 7) : $name;
                    $models[] = $name;
                    $methods[$name] = array_map('strval', (array) ($m['supportedGenerationMethods'] ?? []));
                }
            }
        } elseif ($type === 'claude') {
            AiEndpointGuard::assertSafeEndpoint($base, false);
            $d = self::json(TtsHttp::request('GET', $base . '/models', ['x-api-key' => $key, 'anthropic-version' => '2023-06-01'], null, 20), $id);
            foreach ((array) ($d['data'] ?? []) as $m) {
                if (!empty($m['id'])) {
                    $models[] = (string) $m['id'];
                }
            }
        } else {
            AiEndpointGuard::assertSafeEndpoint($base, $allowPrivate);
            $headers = $key !== '' ? ['Authorization' => 'Bearer ' . $key] : [];
            $d = self::json(TtsHttp::request('GET', rtrim($base, '/') . '/models', $headers, null, 20), $id);
            foreach ((array) ($d['data'] ?? $d['models'] ?? []) as $m) {
                if (is_string($m)) {
                    $models[] = $m;
                } elseif (!empty($m['id'])) {
                    $models[] = (string) $m['id'];
                }
            }
        }

        $models = array_values(array_unique(array_filter($models, fn($v) => $v !== '')));
        sort($models, SORT_STRING);
        $kinds = [];
        foreach ($models as $m) {
            $kinds[$m] = self::modelKind($m, $methods[$m] ?? null);
        }
        if (!$isNew && function_exists('setSystemSetting')) {
            setSystemSetting($cacheKey, json_encode(['fp' => $fingerprint, 'exp' => time() + 900, 'models' => $models, 'kinds' => $kinds], JSON_UNESCAPED_UNICODE));
        }
        return ['models' => $models, 'kinds' => $kinds, 'cached' => false];
    }

    /**
     * What a listed model is for, from Google's supported methods or else from its name (the providers' lists do
     * not say): realtime, stt, tts, embed, other (images, moderation ...) or llm.
     * @param list<string>|null $methods
     */
    public static function modelKind(string $model, ?array $methods = null): string
    {
        $m = strtolower($model);
        return match (true) {
            str_contains($m, 'realtime') || str_contains($m, '-live') || in_array('bidiGenerateContent', (array) $methods, true) => 'realtime',
            (bool) preg_match('/whisper|transcribe|distil-|-asr|\bstt\b/', $m) => 'stt',
            (bool) preg_match('/(^|[-_])tts|text-to-speech|playai|orpheus/', $m) => 'tts',
            (bool) preg_match('/embed|rerank/', $m) || in_array('embedContent', (array) $methods, true) => 'embed',
            (bool) preg_match('/dall-e|gpt-image|imagen|veo|moderation|guard|babbage|davinci|aqa|computer-use|search/', $m)
                || ($methods !== null && !in_array('generateContent', $methods, true)) => 'other',
            default => 'llm',
        };
    }

    public static function json(array $r, string $id): array
    {
        try {
            return TtsHttp::json($r, AiAccountStore::provider($id)['title']);
        } catch (TtsException $e) {
            throw new CloudAiException($e->getMessage());
        }
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
}
