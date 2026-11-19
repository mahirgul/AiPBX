<?php
/**
 * AI → Local models (roadmap 5, step 0): the portal side of the local AI
 * service `aipbx-ai` (ai/, docs/local-ai.md), which runs AI models on the PBX
 * itself so text and audio never leave the server.
 *
 * Two parts, both admin only:
 *  - the runtime (Python venv with ONNX Runtime, ~160 MB): installed and removed by
 *    `aipbx-priv ai runtime-install|runtime-remove`, which runs as root in the
 *    background; its progress is read with `aipbx-priv ai runtime-status`.
 *  - the models: downloaded, removed, measured and used through the service's
 *    HTTP API on 127.0.0.1:8790, with the shared token /etc/aipbx/ai.token.
 */

require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/priv_helper.php';
require_once dirname(__DIR__) . '/audit_log.php';
require_once __DIR__ . '/tts/TtsProviders.php';
require_once __DIR__ . '/ai/OllamaService.php';

final class LocalAiException extends RuntimeException
{
}

class LocalAiService
{
    public const BASE_URL = 'http://127.0.0.1:8790';
    public const TTS_RATES = [8000, 16000, 24000, 48000];

    /** Answers of this request (the page and the TTS provider ask several times). */
    private static ?array $modelsCache = null;
    private static ?array $diskCache = null;

    public static function tokenFile(): string
    {
        return getenv('AIPBX_AI_TOKEN_FILE') ?: '/etc/aipbx/ai.token';
    }

    private static function token(): string
    {
        // No token file = the service was never installed here (not an error to log).
        $file = self::tokenFile();
        $t = is_readable($file) ? trim((string) file_get_contents($file)) : '';
        if (!preg_match('/^[A-Za-z0-9]{16,128}$/', $t)) {
            throw new LocalAiException(t('ai_models.err_no_service'));
        }
        return $t;
    }

    /**
     * One call to the service.
     * @return array{status: int, body: string, type: string}
     */
    private static function call(string $method, string $path, ?array $json = null, int $timeout = 5): array
    {
        $headers = ['Authorization' => 'Bearer ' . self::token()];
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        try {
            return TtsHttp::request($method, self::BASE_URL . $path, $headers, $json !== null ? self::body($json) : null, $timeout);
        } catch (TtsException $e) {
            throw new LocalAiException(t('ai_models.err_no_service'));
        }
    }

    /** JSON object body: the service refuses anything else, and json_encode([]) is "[]". */
    private static function body(array $json): string
    {
        return $json === [] ? '{}' : (string) json_encode($json, JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private static function callJson(string $method, string $path, ?array $json = null, int $timeout = 5): array
    {
        $r = self::call($method, $path, $json, $timeout);
        $data = json_decode($r['body'], true);
        if ($r['status'] >= 400 || !is_array($data)) {
            $err = is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : 'HTTP ' . $r['status'];
            throw new LocalAiException(mb_substr($err, 0, 300));
        }
        return $data;
    }

    // ------------------------------------------------------------ runtime

    /**
     * State of the runtime install (aipbx-ai-setup writes it).
     * @return array{state: string, step: string, error: ?string, started: ?int, finished: ?int}
     */
    public static function runtime(): array
    {
        $res = PrivHelper::run(['ai', 'runtime-status']);
        return self::parseRuntime($res['success'] ? $res['output'] : '');
    }

    /** @return array{state: string, step: string, error: ?string, started: ?int, finished: ?int} */
    public static function parseRuntime(string $json): array
    {
        $d = json_decode($json, true);
        $d = is_array($d) ? $d : [];
        $state = in_array($d['state'] ?? '', ['absent', 'installing', 'installed', 'failed', 'removing'], true) ? $d['state'] : 'absent';
        return [
            'state' => $state,
            'step' => is_string($d['step'] ?? null) ? mb_substr($d['step'], 0, 200) : '',
            'error' => is_string($d['error'] ?? null) ? mb_substr($d['error'], 0, 500) : null,
            'started' => isset($d['started']) ? (int) $d['started'] : null,
            'finished' => isset($d['finished']) ? (int) $d['finished'] : null,
        ];
    }

    public static function installRuntime(): void
    {
        $res = PrivHelper::run(['ai', 'runtime-install']);
        if (!$res['success']) {
            throw new LocalAiException(sprintf(t('ai_models.err_runtime'), $res['output']));
        }
        writeAuditLog('ai_models', 'runtime', 0, 'Local AI runtime', 'install', $_SESSION['user_id'] ?? null);
    }

    public static function removeRuntime(): void
    {
        $res = PrivHelper::run(['ai', 'runtime-remove']);
        if (!$res['success']) {
            throw new LocalAiException(sprintf(t('ai_models.err_runtime'), $res['output']));
        }
        self::$modelsCache = null;
        writeAuditLog('ai_models', 'runtime', 0, 'Local AI runtime and models', 'delete', $_SESSION['user_id'] ?? null);
    }

    /** Ollama is a separate runtime; installing it never downloads a model. */
    public static function ollamaRuntime(): array
    {
        $res = PrivHelper::run(['ai', 'ollama-status']);
        return self::parseRuntime($res['success'] ? $res['output'] : '');
    }

    public static function installOllama(): void
    {
        $res = PrivHelper::run(['ai', 'ollama-install']);
        if (!$res['success']) {
            throw new LocalAiException(sprintf(t('ai_models.err_runtime'), $res['output']));
        }
        writeAuditLog('ai_settings', 'runtime', 0, 'Ollama runtime', 'install', $_SESSION['user_id'] ?? null);
    }

    /** A running local LLM; errors remain local and never trigger a cloud fallback. */
    public static function chat(string $id, string $prompt, int $maxTokens = 300): string
    {
        self::requireId($id);
        if (str_starts_with($id, 'ollama-')) {
            try {
                return OllamaService::chat($id, $prompt, $maxTokens);
            } catch (TtsException $e) {
                throw new LocalAiException($e->getMessage());
            }
        }
        $d = self::callJson('POST', '/v1/chat', ['model' => $id, 'prompt' => $prompt, 'max_tokens' => $maxTokens], 180);
        return (string) ($d['text'] ?? '');
    }

    // ------------------------------------------------------------ service

    /** @return array<string, mixed>|null null when the service does not answer */
    public static function health(): ?array
    {
        try {
            return self::callJson('GET', '/v1/health', null, 3);
        } catch (LocalAiException $e) {
            return null;
        }
    }

    /**
     * Models the service knows, with their state; [] when it does not answer.
     * @return list<array<string, mixed>>
     */
    public static function models(): array
    {
        if (self::$modelsCache !== null) {
            return self::$modelsCache;
        }
        try {
            $data = self::callJson('GET', '/v1/models', null, 3);
        } catch (LocalAiException $e) {
            self::$diskCache = null;
            return self::$modelsCache = [];
        }
        $disk = is_array($data['disk'] ?? null) ? $data['disk'] : [];
        self::$diskCache = ['used_mb' => (int) ($disk['used_mb'] ?? 0), 'limit_mb' => (int) ($disk['limit_mb'] ?? 0)];
        $out = [];
        foreach ((array) ($data['models'] ?? []) as $m) {
            if (is_array($m) && is_string($m['id'] ?? null) && self::validId($m['id'])) {
                $out[] = $m;
            }
        }
        return self::$modelsCache = $out;
    }

    /** @return array{used_mb: int, limit_mb: int}|null */
    public static function disk(): ?array
    {
        self::models();
        return self::$diskCache;
    }

    /**
     * Running text-to-speech models (the voices of the "Local models" TTS provider).
     * @return list<array<string, mixed>>
     */
    public static function readyTtsModels(): array
    {
        return array_values(array_filter(self::models(), fn($m) => ($m['kind'] ?? '') === 'tts' && ($m['state'] ?? '') === 'ready'));
    }

    /** "tr" → "tr-TR"; tags that already carry a region stay as they are. */
    public static function languageTag(string $lang): string
    {
        $lang = str_replace('_', '-', $lang);
        if (str_contains($lang, '-')) {
            return $lang;
        }
        return match ($lang) {
            'tr' => 'tr-TR', 'de' => 'de-DE', 'en' => 'en-US', 'fr' => 'fr-FR', 'es' => 'es-ES', 'it' => 'it-IT',
            default => $lang,
        };
    }

    public static function model(string $id): ?array
    {
        foreach (self::models() as $m) {
            if ($m['id'] === $id) {
                return $m;
            }
        }
        return null;
    }

    public static function ready(string $id): bool
    {
        return (self::model($id)['state'] ?? '') === 'ready';
    }

    public static function validId(string $id): bool
    {
        return !str_contains($id, '..') && (bool) preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/', $id);
    }

    private static function requireId(string $id): void
    {
        if (!self::validId($id)) {
            throw new LocalAiException(t('ai_models.err_model'));
        }
    }

    public static function installModel(string $id, bool $acceptLicense): void
    {
        self::requireId($id);
        if (!$acceptLicense) {
            throw new LocalAiException(t('ai_models.err_license'));
        }
        self::callJson('POST', '/v1/models/' . $id . '/install', ['accept_license' => true], 10);
        self::$modelsCache = null;
        writeAuditLog('ai_models', 'model', $id, 'Local model ' . $id . ' (licence accepted)', 'install', $_SESSION['user_id'] ?? null);
    }

    /**
     * Every Piper voice of the public voice list (cached by the service for a day).
     * @return list<array<string, mixed>>
     */
    public static function piperCatalog(): array
    {
        $d = self::callJson('GET', '/v1/catalog/piper', null, 30);
        return array_values(array_filter((array) ($d['voices'] ?? []), 'is_array'));
    }

    /** Adds a Piper voice from the list (licence accepted) and starts its download. */
    public static function addPiperVoice(string $key, bool $acceptLicense): string
    {
        if (!preg_match('/^[A-Za-z]{2,3}_[A-Za-z]{2}-[A-Za-z0-9_]+-(x_low|low|medium|high)$/', $key)) {
            throw new LocalAiException(t('ai_models.err_model'));
        }
        if (!$acceptLicense) {
            throw new LocalAiException(t('ai_models.err_license'));
        }
        $d = self::callJson('POST', '/v1/models/add', ['engine' => 'piper', 'key' => $key, 'accept_license' => true], 60);
        self::$modelsCache = null;
        writeAuditLog('ai_models', 'model', $key, 'Piper voice ' . $key . ' added (licence accepted)', 'install', $_SESSION['user_id'] ?? null);
        return (string) ($d['id'] ?? '');
    }

    /** Normalizes and validates a Hugging Face repo slug or https://huggingface.co URL. */
    public static function normalizeHfRepo(string $repoOrUrl): string
    {
        $s = trim($repoOrUrl);
        if ($s === '') {
            throw new LocalAiException(t('ai_models.err_hf_repo'));
        }
        if (str_contains($s, '://')) {
            $p = parse_url($s);
            if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || strtolower((string) ($p['host'] ?? '')) !== 'huggingface.co') {
                throw new LocalAiException(t('ai_models.err_hf_url'));
            }
            $parts = array_values(array_filter(explode('/', (string) ($p['path'] ?? '')), fn($x) => $x !== ''));
            if (count($parts) < 2) {
                throw new LocalAiException(t('ai_models.err_hf_repo'));
            }
            $s = $parts[0] . '/' . $parts[1];
        }
        $s = trim($s, '/');
        if (str_contains($s, '..') || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\/[A-Za-z0-9][A-Za-z0-9._-]*$/', $s)) {
            throw new LocalAiException(t('ai_models.err_hf_repo'));
        }
        return $s;
    }

    /**
     * Searches Hugging Face Hub models via the local AI service.
     * @return list<array<string, mixed>>
     */
    private const HF_TOKEN_SETTING = 'ai_hf_token';

    /** The Hugging Face access token saved on the Local AI page (encrypted), '' when none. */
    public static function hfToken(): string
    {
        require_once dirname(__DIR__) . '/secret_box.php';
        $raw = function_exists('getSystemSetting') ? (string) getSystemSetting(self::HF_TOKEN_SETTING, '') : '';
        return $raw === '' ? '' : (string) SecretBox::decrypt($raw);
    }

    /** Saves (or with '' removes) the token used for gated and private Hugging Face models. */
    public static function saveHfToken(string $token): void
    {
        require_once dirname(__DIR__) . '/secret_box.php';
        $token = trim($token);
        if ($token !== '' && !preg_match('/^hf_[A-Za-z0-9]{20,100}$/', $token)) {
            throw new LocalAiException(t('ai_local.hf_token_invalid'));
        }
        setSystemSetting(self::HF_TOKEN_SETTING, $token === '' ? '' : SecretBox::encrypt($token));
        writeAuditLog('ai_models', 'settings', 'hf_token', $token === '' ? 'Hugging Face token removed' : 'Hugging Face token saved', 'update', $_SESSION['user_id'] ?? null);
    }

    public static function hfSearch(string $query, string $kind = '', string $token = ''): array
    {
        $token = trim($token) !== '' ? $token : self::hfToken();
        $payload = ['query' => mb_substr(trim($query), 0, 120)];
        if (in_array($kind, ['tts', 'stt', 'embedding', 'llm'], true)) {
            $payload['kind'] = $kind;
        }
        if (trim($token) !== '') {
            $payload['token'] = trim($token);
        }
        $d = self::callJson('POST', '/v1/hf/search', $payload, 30);
        return array_values(array_filter((array) ($d['models'] ?? []), 'is_array'));
    }

    /**
     * Inspects a Hugging Face repository (pinned revision, format, SHA-256, license).
     * @return array<string, mixed>
     */
    public static function hfInspect(string $repoOrUrl, string $revision = '', string $token = ''): array
    {
        $token = trim($token) !== '' ? $token : self::hfToken();
        $repo = self::normalizeHfRepo($repoOrUrl);
        $payload = ['repo' => $repo];
        $rev = trim($revision);
        if ($rev !== '') {
            if (str_contains($rev, '..') || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $rev)) {
                throw new LocalAiException(t('ai_models.err_hf_repo'));
            }
            $payload['revision'] = $rev;
        }
        if (trim($token) !== '') {
            $payload['token'] = trim($token);
        }
        $d = self::callJson('POST', '/v1/hf/inspect', $payload, 30);
        return is_array($d['info'] ?? null) ? $d['info'] : [];
    }

    /** Adds a validated Hugging Face model (license accepted) and starts its download. */
    public static function addHuggingFaceModel(string $repoOrUrl, string $revision, bool $acceptLicense, string $language = '', string $token = ''): string
    {
        $token = trim($token) !== '' ? $token : self::hfToken();
        $repo = self::normalizeHfRepo($repoOrUrl);
        if (!$acceptLicense) {
            throw new LocalAiException(t('ai_models.err_license'));
        }
        $payload = ['engine' => 'huggingface', 'repo' => $repo, 'accept_license' => true];
        $rev = trim($revision);
        if ($rev !== '') {
            if (str_contains($rev, '..') || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $rev)) {
                throw new LocalAiException(t('ai_models.err_hf_repo'));
            }
            $payload['revision'] = $rev;
        }
        $lang = trim($language);
        if ($lang !== '' && preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})?$/', $lang)) {
            $payload['language'] = $lang;
        }
        if (trim($token) !== '') {
            $payload['token'] = trim($token);
        }
        $d = self::callJson('POST', '/v1/models/add', $payload, 60);
        self::$modelsCache = null;
        writeAuditLog('ai_models', 'model', $repo, 'Hugging Face model ' . $repo . ' added (licence accepted)', 'install', $_SESSION['user_id'] ?? null);
        return (string) ($d['id'] ?? '');
    }

    /** Loads a downloaded model into memory (it stays running after restarts). */
    public static function runModel(string $id): void
    {
        self::requireId($id);
        if (str_starts_with($id, 'ollama-')) {
            try {
                OllamaService::run($id, true);
                writeAuditLog('ai_settings', 'model', $id, 'Local Ollama model', 'update', $_SESSION['user_id'] ?? null);
                return;
            } catch (TtsException $e) {
                throw new LocalAiException($e->getMessage());
            }
        }
        self::callJson('POST', '/v1/models/' . $id . '/run', [], 10);
        self::$modelsCache = null;
        writeAuditLog('ai_models', 'model', $id, 'Local model ' . $id . ' started', 'update', $_SESSION['user_id'] ?? null);
    }

    /** Unloads a model; its files stay (it stays stopped after restarts). */
    public static function stopModel(string $id): void
    {
        self::requireId($id);
        if (str_starts_with($id, 'ollama-')) {
            try {
                OllamaService::run($id, false);
                writeAuditLog('ai_settings', 'model', $id, 'Local Ollama model', 'update', $_SESSION['user_id'] ?? null);
                return;
            } catch (TtsException $e) {
                throw new LocalAiException($e->getMessage());
            }
        }
        self::callJson('POST', '/v1/models/' . $id . '/stop', [], 60);
        self::$modelsCache = null;
        writeAuditLog('ai_models', 'model', $id, 'Local model ' . $id . ' stopped', 'update', $_SESSION['user_id'] ?? null);
    }

    /** Stops a running download; the service deletes the partial files. */
    public static function cancelDownload(string $id): void
    {
        self::requireId($id);
        self::callJson('POST', '/v1/models/' . $id . '/cancel', [], 10);
        self::$modelsCache = null;
        writeAuditLog('ai_models', 'model', $id, 'Local model ' . $id . ' download cancelled', 'update', $_SESSION['user_id'] ?? null);
    }

    public static function removeModel(string $id): void
    {
        self::requireId($id);
        if (str_starts_with($id, 'ollama-')) {
            try {
                OllamaService::remove($id);
                writeAuditLog('ai_settings', 'model', $id, 'Local Ollama model', 'update', $_SESSION['user_id'] ?? null);
                return;
            } catch (TtsException $e) {
                throw new LocalAiException($e->getMessage());
            }
        }
        self::callJson('POST', '/v1/models/' . $id . '/remove', [], 30);
        self::$modelsCache = null;
        writeAuditLog('ai_models', 'model', $id, 'Local model ' . $id, 'delete', $_SESSION['user_id'] ?? null);
    }

    /** @return array{audio_seconds: float, first_audio_ms: int, seconds: float, realtime_factor: float} */
    public static function benchmark(string $id): array
    {
        self::requireId($id);
        $d = self::callJson('POST', '/v1/models/' . $id . '/benchmark', [], 180);
        return [
            'audio_seconds' => round((float) ($d['audio_seconds'] ?? 0), 2),
            'first_audio_ms' => (int) ($d['first_audio_ms'] ?? 0),
            'seconds' => round((float) ($d['seconds'] ?? 0), 2),
            'realtime_factor' => round((float) ($d['realtime_factor'] ?? 0), 1),
        ];
    }

    /** 16-bit mono WAV bytes. Long texts take a while on a CPU: generous timeout. */
    public static function tts(string $model, string $text, float $speed, int $sampleRate = 24000): string
    {
        self::requireId($model);
        if (!in_array($sampleRate, self::TTS_RATES, true)) {
            throw new LocalAiException(t('ai_models.err_model'));
        }
        $r = self::call('POST', '/v1/tts', ['model' => $model, 'text' => $text, 'speed' => $speed, 'sample_rate' => $sampleRate], 600);
        if ($r['status'] !== 200 || !str_starts_with($r['body'], 'RIFF')) {
            $data = json_decode($r['body'], true);
            throw new LocalAiException(is_array($data) && is_string($data['error'] ?? null) ? mb_substr($data['error'], 0, 300) : 'HTTP ' . $r['status']);
        }
        return $r['body'];
    }

    /**
     * Speech to text with a running speech-to-text model.
     * @return array{text: string, seconds: float, ms: int}
     */
    public static function stt(string $model, string $wav): array
    {
        self::requireId($model);
        if (strlen($wav) < 44 || strlen($wav) > 4 * 1024 * 1024 || !str_starts_with($wav, 'RIFF')) {
            throw new LocalAiException(t('ai_models.err_stt_audio'));
        }
        $headers = ['Authorization' => 'Bearer ' . self::token(), 'Content-Type' => 'audio/wav'];
        try {
            $r = TtsHttp::request('POST', self::BASE_URL . '/v1/stt?model=' . rawurlencode($model), $headers, $wav, 120);
        } catch (TtsException $e) {
            throw new LocalAiException(t('ai_models.err_no_service'));
        }
        $d = json_decode($r['body'], true);
        if ($r['status'] !== 200 || !is_array($d)) {
            throw new LocalAiException(is_array($d) && is_string($d['error'] ?? null) ? mb_substr($d['error'], 0, 300) : 'HTTP ' . $r['status']);
        }
        return ['text' => (string) ($d['text'] ?? ''), 'seconds' => (float) ($d['seconds'] ?? 0), 'ms' => (int) ($d['ms'] ?? 0)];
    }

    /**
     * Makes the service hold exactly these voice-requests settings
     * (id => config): PUT the wanted ones, DELETE the others.
     * @param array<int, array> $wanted
     */
    public static function pushApps(array $wanted): void
    {
        $have = self::callJson('GET', '/v1/apps', null, 5);
        $existing = [];
        foreach ((array) ($have['apps'] ?? $have) as $k => $v) {
            $existing[] = (int) (is_array($v) ? ($v['id'] ?? $k) : $k);
        }
        foreach ($wanted as $id => $cfg) {
            self::callJson('PUT', '/v1/apps/' . (int) $id, $cfg, 10);
        }
        foreach (array_diff($existing, array_keys($wanted)) as $id) {
            if ($id > 0) {
                self::callJson('DELETE', '/v1/apps/' . (int) $id, null, 5);
            }
        }
    }

    /**
     * Enables or disables draining on the local AI live-call service:
     * when draining, new calls are refused while active calls finish naturally.
     *
     * @return array<string, mixed>
     */
    public static function drainCalls(bool $drain = true): array
    {
        return self::callJson('POST', '/v1/calls/drain', ['drain' => $drain], 3);
    }

    /** The caller's recording of a voice-requests call ('' when the service no longer has it). */
    public static function callAudio(string $uuid): string
    {
        if (!preg_match('/^[0-9a-f-]{36}$/', $uuid)) {
            return '';
        }
        $r = self::call('GET', '/v1/calls/' . $uuid . '/audio', null, 10);
        return $r['status'] === 200 && str_starts_with($r['body'], 'RIFF') ? $r['body'] : '';
    }

    /** WAV → MP3 (what Cloud TTS keeps and plays), with lame. */
    public static function wavToMp3(string $wav): string
    {
        $in = tempnam(sys_get_temp_dir(), 'aipbx-ai-');
        $out = $in . '.mp3';
        try {
            file_put_contents($in, $wav);
            exec('lame --quiet -b 64 ' . escapeshellarg($in) . ' ' . escapeshellarg($out) . ' 2>&1', $o, $ret);
            $mp3 = $ret === 0 ? (string) @file_get_contents($out) : '';
            if ($mp3 === '') {
                throw new LocalAiException('MP3 conversion failed: ' . implode(' ', $o));
            }
            return $mp3;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /** Everything the page and its polling show. */
    public static function status(): array
    {
        $runtime = self::runtime();
        $health = $runtime['state'] === 'installed' ? self::health() : null;
        return [
            'runtime' => $runtime,
            'ollama' => self::ollamaRuntime(),
            'service' => $health,
            'models' => array_merge($health !== null ? self::models() : [], OllamaService::models()),
            'disk' => $health !== null ? self::disk() : null,
        ];
    }
}
