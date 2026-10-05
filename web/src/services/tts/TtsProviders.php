<?php
/**
 * Cloud text-to-speech clients (plain REST, no SDKs).
 *
 * Every provider gives its voices and turns text into MP3. HTTP goes
 * through TtsHttp so tests can replace it.
 */

final class TtsException extends RuntimeException
{
}

/** Minimal HTTP client; tests swap TtsHttp::$transport. */
final class TtsHttp
{
    /** @var ?callable(string $method, string $url, array $headers, ?string $body): array{status: int, body: string, type: string} */
    public static $transport = null;

    /** @return array{status: int, body: string, type: string} */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 60): array
    {
        if (self::$transport) {
            return (self::$transport)($method, $url, $headers, $body);
        }
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new TtsException('connection failed: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $resp, 'type' => $type];
    }

    /** Decodes a JSON answer or throws with the provider's own error message. */
    public static function json(array $r, string $provider): array
    {
        $data = json_decode($r['body'], true);
        if ($r['status'] >= 400 || !is_array($data)) {
            throw new TtsException($provider . ' ' . $r['status'] . ': ' . self::errorText($r));
        }
        return $data;
    }

    public static function errorText(array $r): string
    {
        $data = json_decode($r['body'], true);
        $msg = $data['error']['message'] ?? $data['message'] ?? $data['detail']['message'] ?? $data['detail'] ?? $data['Message'] ?? null;
        if (is_array($msg)) {
            $msg = json_encode($msg);
        }
        return mb_substr((string) ($msg ?? trim(strip_tags($r['body']))), 0, 300) ?: 'HTTP ' . $r['status'];
    }
}

/** AWS Signature Version 4 (header signing) for Amazon Polly. */
final class AwsSigV4
{
    /**
     * @return array<string, string> headers to send (incl. Authorization, X-Amz-Date)
     */
    public static function sign(string $method, string $url, array $headers, string $body, string $accessKey, string $secretKey, string $region, string $service, ?int $time = null): array
    {
        $time ??= time();
        $amzDate = gmdate('Ymd\THis\Z', $time);
        $date = gmdate('Ymd', $time);
        $u = parse_url($url);
        $host = $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
        $path = $u['path'] ?? '/';
        $canonicalPath = implode('/', array_map(fn($s) => rawurlencode(rawurldecode($s)), explode('/', $path)));

        $query = [];
        if (!empty($u['query'])) {
            foreach (explode('&', $u['query']) as $pair) {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                $query[] = [rawurlencode(rawurldecode($k)), rawurlencode(rawurldecode($v))];
            }
            usort($query, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        }
        $canonicalQuery = implode('&', array_map(fn($p) => $p[0] . '=' . $p[1], $query));

        $headers = array_change_key_case($headers + ['host' => $host, 'x-amz-date' => $amzDate], CASE_LOWER);
        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= $k . ':' . trim(preg_replace('/\s+/', ' ', (string) $v)) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));
        $canonical = implode("\n", [$method, $canonicalPath, $canonicalQuery, $canonicalHeaders, $signedHeaders, hash('sha256', $body)]);

        $scope = "{$date}/{$region}/{$service}/aws4_request";
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonical)]);
        $k = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
        $k = hash_hmac('sha256', $region, $k, true);
        $k = hash_hmac('sha256', $service, $k, true);
        $k = hash_hmac('sha256', 'aws4_request', $k, true);
        $signature = hash_hmac('sha256', $toSign, $k);

        unset($headers['host']);
        $out = [];
        foreach ($headers as $name => $v) {
            $out[$name] = $v;
        }
        $out['Authorization'] = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
        return $out;
    }
}

abstract class TtsProvider
{
    /** @param array<string, string> $cfg decrypted settings of this provider */
    public function __construct(protected array $cfg)
    {
    }

    abstract public static function id(): string;
    abstract public static function title(): string;
    /** @return list<array{key: string, label: string, secret: bool, default?: string, optional?: bool, json?: bool}> */
    abstract public static function fields(): array;
    /** Longest text one request accepts; longer text is split. */
    abstract public static function maxChars(): int;
    /** @return list<array{id: string, name: string, language: string, gender: string}> */
    abstract public function voices(string $language = ''): array;
    /** MP3 bytes. $speed 0.5-2.0 */
    abstract public function synthesize(string $text, string $voice, string $language, float $speed): string;

    public function configured(): bool
    {
        foreach (static::fields() as $f) {
            if (empty($f['optional']) && trim($this->cfg[$f['key']] ?? '') === '') {
                return false;
            }
        }
        return true;
    }

    protected function cfg(string $key, string $default = ''): string
    {
        $v = trim($this->cfg[$key] ?? '');
        return $v !== '' ? $v : $default;
    }

    protected static function audio(array $r, string $provider): string
    {
        if ($r['status'] >= 400 || $r['body'] === '' || str_contains($r['type'], 'json')) {
            throw new TtsException($provider . ' ' . $r['status'] . ': ' . TtsHttp::errorText($r));
        }
        return $r['body'];
    }

    protected static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

/** Google Cloud Text-to-Speech: API key, or a service account JSON key. */
final class GoogleTts extends TtsProvider
{
    private const BASE = 'https://texttospeech.googleapis.com/v1';
    private const SCOPE = 'https://www.googleapis.com/auth/cloud-platform';
    /** @var array<string, array{token: string, exp: int}> access tokens per service account (this process) */
    private static array $tokens = [];

    public static function id(): string { return 'google'; }
    public static function title(): string { return 'Google Cloud Text-to-Speech'; }
    public static function maxChars(): int { return 4500; }   // 5000 bytes per request
    public static function fields(): array
    {
        // Either one is enough: organisations often forbid API keys.
        return [
            ['key' => 'service_account', 'label' => 'Service account JSON', 'secret' => true, 'json' => true, 'optional' => true],
            ['key' => 'api_key', 'label' => 'API key', 'secret' => true, 'optional' => true],
        ];
    }

    public function configured(): bool
    {
        return $this->cfg('service_account') !== '' || $this->cfg('api_key') !== '';
    }

    /**
     * Checks a pasted/uploaded service account key; returns it re-encoded.
     * @throws TtsException
     */
    public static function validateServiceAccount(string $json): string
    {
        $sa = json_decode($json, true);
        if (!is_array($sa) || ($sa['type'] ?? '') !== 'service_account' || empty($sa['client_email']) || empty($sa['private_key'])) {
            throw new TtsException('Google: not a service account JSON key');
        }
        if (!@openssl_pkey_get_private((string) $sa['private_key'])) {
            throw new TtsException('Google: the service account private key cannot be read');
        }
        $keep = array_intersect_key($sa, array_flip(['type', 'project_id', 'private_key_id', 'private_key', 'client_email', 'token_uri']));
        return (string) json_encode($keep, JSON_UNESCAPED_SLASHES);
    }

    /** OAuth access token from a signed JWT (RFC 7523), cached until shortly before it expires. */
    private function token(): string
    {
        $sa = json_decode($this->cfg('service_account'), true) ?: [];
        $email = (string) ($sa['client_email'] ?? '');
        $cached = self::$tokens[$email] ?? null;
        if ($cached && $cached['exp'] > time() + 60) {
            return $cached['token'];
        }
        $tokenUri = (string) ($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        if (!preg_match('#^https://[a-z0-9.-]+\.googleapis\.com/#', $tokenUri)) {
            throw new TtsException('Google: unexpected token_uri');
        }
        $now = time();
        $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => (string) ($sa['private_key_id'] ?? '')]))
            . '.' . $b64(json_encode(['iss' => $email, 'scope' => self::SCOPE, 'aud' => $tokenUri, 'iat' => $now, 'exp' => $now + 3600]));
        if (!openssl_sign($unsigned, $sig, (string) ($sa['private_key'] ?? ''), OPENSSL_ALGO_SHA256)) {
            throw new TtsException('Google: could not sign with the service account key');
        }
        $r = TtsHttp::request('POST', $tokenUri, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsigned . '.' . $b64($sig),
        ]));
        $data = json_decode($r['body'], true);
        if ($r['status'] >= 400 || empty($data['access_token'])) {
            $msg = is_array($data) ? (($data['error_description'] ?? '') ?: ($data['error'] ?? '')) : '';
            throw new TtsException('Google ' . $r['status'] . ': ' . ($msg ?: TtsHttp::errorText($r)));
        }
        self::$tokens[$email] = ['token' => (string) $data['access_token'], 'exp' => $now + (int) ($data['expires_in'] ?? 3600)];
        return self::$tokens[$email]['token'];
    }

    /** @return array{0: string, 1: array<string, string>} [query suffix, headers] */
    private function auth(): array
    {
        if ($this->cfg('service_account') !== '') {
            return ['', ['Authorization' => 'Bearer ' . $this->token()]];
        }
        return ['key=' . rawurlencode($this->cfg('api_key')), []];
    }

    private static function url(string $path, string $query): string
    {
        $q = implode('&', array_filter([$query]));
        return self::BASE . $path . ($q !== '' ? (str_contains($path, '?') ? '&' : '?') . $q : '');
    }

    public function voices(string $language = ''): array
    {
        [$key, $headers] = $this->auth();
        $path = '/voices' . ($language !== '' ? '?languageCode=' . rawurlencode($language) : '');
        $data = TtsHttp::json(TtsHttp::request('GET', self::url($path, $key), $headers), 'Google');
        $out = [];
        foreach ($data['voices'] ?? [] as $v) {
            foreach ($v['languageCodes'] ?? [] as $lang) {
                $out[] = ['id' => $v['name'], 'name' => $v['name'], 'language' => $lang, 'gender' => strtolower((string) ($v['ssmlGender'] ?? ''))];
            }
        }
        return $out;
    }

    public function synthesize(string $text, string $voice, string $language, float $speed): string
    {
        [$key, $headers] = $this->auth();
        $body = json_encode([
            'input' => ['text' => $text],
            'voice' => ['languageCode' => $language, 'name' => $voice],
            'audioConfig' => ['audioEncoding' => 'MP3', 'speakingRate' => $speed],
        ], JSON_UNESCAPED_UNICODE);
        $r = TtsHttp::request('POST', self::url('/text:synthesize', $key), $headers + ['Content-Type' => 'application/json; charset=utf-8'], $body);
        $data = TtsHttp::json($r, 'Google');
        $audio = base64_decode((string) ($data['audioContent'] ?? ''), true);
        if (!$audio) {
            throw new TtsException('Google: empty audio');
        }
        return $audio;
    }

    /**
     * Lossless variant for building prompt sets: 16-bit PCM WAV at the given
     * sample rate (Google returns LINEAR16 with a WAV header).
     */
    public function synthesizeWav(string $text, string $voice, string $language, float $speed, int $sampleRate): string
    {
        [$key, $headers] = $this->auth();
        $body = json_encode([
            'input' => ['text' => $text],
            'voice' => ['languageCode' => $language, 'name' => $voice],
            'audioConfig' => ['audioEncoding' => 'LINEAR16', 'sampleRateHertz' => $sampleRate, 'speakingRate' => $speed],
        ], JSON_UNESCAPED_UNICODE);
        $r = TtsHttp::request('POST', self::url('/text:synthesize', $key), $headers + ['Content-Type' => 'application/json; charset=utf-8'], $body);
        $data = TtsHttp::json($r, 'Google');
        $audio = base64_decode((string) ($data['audioContent'] ?? ''), true);
        if (!$audio) {
            throw new TtsException('Google: empty audio');
        }
        return $audio;
    }
}

/** Amazon Polly, access key + secret (SigV4). */
final class PollyTts extends TtsProvider
{
    public static function id(): string { return 'polly'; }
    public static function title(): string { return 'Amazon Polly'; }
    public static function maxChars(): int { return 2800; }   // 3000 billed characters per request
    public static function fields(): array
    {
        return [
            ['key' => 'access_key', 'label' => 'Access key ID', 'secret' => false],
            ['key' => 'secret_key', 'label' => 'Secret access key', 'secret' => true],
            ['key' => 'region', 'label' => 'Region', 'secret' => false, 'default' => 'eu-central-1'],
        ];
    }

    private function call(string $method, string $path, string $body = ''): array
    {
        $region = $this->cfg('region', 'eu-central-1');
        if (!preg_match('/^[a-z]{2}(-[a-z]+)+-\d$/', $region)) {
            throw new TtsException('Polly: invalid region ' . $region);
        }
        $url = "https://polly.{$region}.amazonaws.com{$path}";
        $headers = $body !== '' ? ['content-type' => 'application/json'] : [];
        $signed = AwsSigV4::sign($method, $url, $headers, $body, $this->cfg('access_key'), $this->cfg('secret_key'), $region, 'polly');
        return TtsHttp::request($method, $url, $signed, $body !== '' ? $body : null);
    }

    public function voices(string $language = ''): array
    {
        $out = [];
        $token = '';
        do {
            $q = ['IncludeAdditionalLanguageCodes' => 'true'];
            if ($language !== '') {
                $q['LanguageCode'] = $language;
            }
            if ($token !== '') {
                $q['NextToken'] = $token;
            }
            $data = TtsHttp::json($this->call('GET', '/v1/voices?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986)), 'Polly');
            foreach ($data['Voices'] ?? [] as $v) {
                // The engine is part of the voice choice: neural sounds better, standard costs less.
                foreach ($v['SupportedEngines'] ?? ['standard'] as $engine) {
                    $out[] = ['id' => $v['Id'] . '|' . $engine, 'name' => $v['Name'] . ' (' . $engine . ')', 'language' => $v['LanguageCode'], 'gender' => strtolower((string) ($v['Gender'] ?? ''))];
                }
            }
            $token = (string) ($data['NextToken'] ?? '');
        } while ($token !== '');
        return $out;
    }

    public function synthesize(string $text, string $voice, string $language, float $speed): string
    {
        [$id, $engine] = array_pad(explode('|', $voice, 2), 2, 'standard');
        $rate = (int) round($speed * 100);
        $body = json_encode([
            'Engine' => $engine,
            'LanguageCode' => $language,
            'OutputFormat' => 'mp3',
            'SampleRate' => '22050',
            'Text' => '<speak><prosody rate="' . $rate . '%">' . self::esc($text) . '</prosody></speak>',
            'TextType' => 'ssml',
            'VoiceId' => $id,
        ], JSON_UNESCAPED_UNICODE);
        return self::audio($this->call('POST', '/v1/speech', $body), 'Polly');
    }
}

/** Microsoft Azure AI Speech, subscription key + region. */
final class AzureTts extends TtsProvider
{
    public static function id(): string { return 'azure'; }
    public static function title(): string { return 'Microsoft Azure AI Speech'; }
    public static function maxChars(): int { return 4000; }
    public static function fields(): array
    {
        return [
            ['key' => 'api_key', 'label' => 'Speech key', 'secret' => true],
            ['key' => 'region', 'label' => 'Region', 'secret' => false, 'default' => 'westeurope'],
        ];
    }

    private function host(): string
    {
        $region = $this->cfg('region', 'westeurope');
        if (!preg_match('/^[a-z0-9]{3,30}$/', $region)) {
            throw new TtsException('Azure: invalid region ' . $region);
        }
        return "https://{$region}.tts.speech.microsoft.com";
    }

    public function voices(string $language = ''): array
    {
        $data = TtsHttp::json(TtsHttp::request('GET', $this->host() . '/cognitiveservices/voices/list', ['Ocp-Apim-Subscription-Key' => $this->cfg('api_key')]), 'Azure');
        $out = [];
        foreach ($data as $v) {
            if ($language !== '' && strcasecmp($v['Locale'] ?? '', $language) !== 0) {
                continue;
            }
            $out[] = ['id' => $v['ShortName'], 'name' => ($v['LocalName'] ?? $v['ShortName']) . ' (' . $v['ShortName'] . ')', 'language' => $v['Locale'], 'gender' => strtolower((string) ($v['Gender'] ?? ''))];
        }
        return $out;
    }

    public function synthesize(string $text, string $voice, string $language, float $speed): string
    {
        $pct = (int) round(($speed - 1) * 100);
        $ssml = "<speak version='1.0' xml:lang='" . self::esc($language) . "'><voice name='" . self::esc($voice) . "'>"
            . "<prosody rate='" . ($pct >= 0 ? '+' : '') . $pct . "%'>" . self::esc($text) . '</prosody></voice></speak>';
        $r = TtsHttp::request('POST', $this->host() . '/cognitiveservices/v1', [
            'Ocp-Apim-Subscription-Key' => $this->cfg('api_key'),
            'Content-Type' => 'application/ssml+xml',
            'X-Microsoft-OutputFormat' => 'audio-24khz-48kbitrate-mono-mp3',
            'User-Agent' => 'AiPBX',
        ], $ssml);
        return self::audio($r, 'Azure');
    }
}

/** ElevenLabs, API key. */
final class ElevenLabsTts extends TtsProvider
{
    private const BASE = 'https://api.elevenlabs.io/v1';

    public static function id(): string { return 'elevenlabs'; }
    public static function title(): string { return 'ElevenLabs'; }
    public static function maxChars(): int { return 4500; }
    public static function fields(): array
    {
        return [
            ['key' => 'api_key', 'label' => 'API key', 'secret' => true],
            ['key' => 'model', 'label' => 'Model', 'secret' => false, 'default' => 'eleven_multilingual_v2', 'optional' => true],
        ];
    }

    public function voices(string $language = ''): array
    {
        $data = TtsHttp::json(TtsHttp::request('GET', self::BASE . '/voices', ['xi-api-key' => $this->cfg('api_key')]), 'ElevenLabs');
        $out = [];
        foreach ($data['voices'] ?? [] as $v) {
            // Multilingual voices speak any supported language: listed under every language.
            $out[] = ['id' => $v['voice_id'], 'name' => $v['name'], 'language' => '*', 'gender' => strtolower((string) ($v['labels']['gender'] ?? ''))];
        }
        return $out;
    }

    public function synthesize(string $text, string $voice, string $language, float $speed): string
    {
        if (!preg_match('/^[A-Za-z0-9]{8,64}$/', $voice)) {
            throw new TtsException('ElevenLabs: invalid voice');
        }
        $body = json_encode([
            'text' => $text,
            'model_id' => $this->cfg('model', 'eleven_multilingual_v2'),
            'language_code' => strtolower(substr($language, 0, 2)) ?: null,
            'voice_settings' => ['speed' => max(0.7, min(1.2, $speed))],
        ], JSON_UNESCAPED_UNICODE);
        $r = TtsHttp::request('POST', self::BASE . '/text-to-speech/' . $voice . '?output_format=mp3_44100_128', [
            'xi-api-key' => $this->cfg('api_key'), 'Content-Type' => 'application/json', 'Accept' => 'audio/mpeg',
        ], $body);
        return self::audio($r, 'ElevenLabs');
    }
}

/** OpenAI text-to-speech, API key. */
final class OpenAiTts extends TtsProvider
{
    public const VOICES = ['alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'nova', 'onyx', 'sage', 'shimmer', 'verse'];

    public static function id(): string { return 'openai'; }
    public static function title(): string { return 'OpenAI TTS'; }
    public static function maxChars(): int { return 4000; }   // 4096 per request
    public static function fields(): array
    {
        return [
            ['key' => 'api_key', 'label' => 'API key', 'secret' => true],
            ['key' => 'model', 'label' => 'Model', 'secret' => false, 'default' => 'gpt-4o-mini-tts', 'optional' => true],
        ];
    }

    public function voices(string $language = ''): array
    {
        // No voice list endpoint: the voices are fixed and speak every language.
        return array_map(fn($v) => ['id' => $v, 'name' => ucfirst($v), 'language' => '*', 'gender' => ''], self::VOICES);
    }

    public function synthesize(string $text, string $voice, string $language, float $speed): string
    {
        if (!in_array($voice, self::VOICES, true)) {
            throw new TtsException('OpenAI: unknown voice ' . $voice);
        }
        $body = json_encode([
            'model' => $this->cfg('model', 'gpt-4o-mini-tts'),
            'input' => $text,
            'voice' => $voice,
            'response_format' => 'mp3',
            'speed' => max(0.25, min(4.0, $speed)),
        ], JSON_UNESCAPED_UNICODE);
        $r = TtsHttp::request('POST', 'https://api.openai.com/v1/audio/speech', [
            'Authorization' => 'Bearer ' . $this->cfg('api_key'), 'Content-Type' => 'application/json',
        ], $body);
        return self::audio($r, 'OpenAI');
    }
}
