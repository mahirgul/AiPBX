<?php
/**
 * AI → Local models (roadmap 5, step 0): the portal side of the local AI
 * service `aipbx-ai` (ai/, docs/local-ai.md), which runs AI models on the PBX
 * itself so text and audio never leave the server.
 *
 * Two parts, both admin only:
 *  - the runtime (Python venv with torch, ~1 GB): installed and removed by
 *    `aipbx-priv ai runtime-install|runtime-remove`, which runs as root in the
 *    background; its progress is read with `aipbx-priv ai runtime-status`.
 *  - the models: downloaded, removed, measured and used through the service's
 *    HTTP API on 127.0.0.1:8790, with the shared token /etc/aipbx/ai.token.
 */

require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/priv_helper.php';
require_once dirname(__DIR__) . '/audit_log.php';
require_once __DIR__ . '/tts/TtsProviders.php';

final class LocalAiException extends RuntimeException
{
}

class LocalAiService
{
    public const BASE_URL = 'http://127.0.0.1:8790';
    public const TTS_RATES = [8000, 16000, 24000, 48000];

    /** Answers of this request (the page and the TTS provider ask several times). */
    private static ?array $modelsCache = null;

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
            return self::$modelsCache = [];
        }
        $out = [];
        foreach ((array) ($data['models'] ?? []) as $m) {
            if (is_array($m) && is_string($m['id'] ?? null) && self::validId($m['id'])) {
                $out[] = $m;
            }
        }
        return self::$modelsCache = $out;
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
        return (bool) preg_match('/^[a-z0-9][a-z0-9-]{1,40}$/', $id);
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

    public static function removeModel(string $id): void
    {
        self::requireId($id);
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
            'service' => $health,
            'models' => $health !== null ? self::models() : [],
        ];
    }
}
