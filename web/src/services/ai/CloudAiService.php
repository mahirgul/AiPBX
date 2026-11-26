<?php
/**
 * AI → Cloud services: the accounts of cloud AI providers in one place, what
 * each can do (speech, speech to text, language models, embeddings), a
 * connection test, this month's use, and the engine chosen for each job.
 *
 * Keys are stored encrypted in sys_settings as ai_tts.<account>.<field> — the
 * prefix of the Cloud TTS page, which used them first, so existing accounts
 * keep working unchanged.
 *
 * Accounts: one provider (type) can have several accounts, e.g. two OpenAI keys or two
 * Ollama servers. The list is the setting ai_cloud_accounts: account id => type, name,
 * enabled. An account stored before that list existed has the type's id ("openai"); it
 * needs no entry, so engine choices like cloud:openai keep working. A later account of
 * the same type gets an id like "openai_b". Everything that differs between providers
 * looks at typeOf($id), never at the account id.
 *
 * Choosing a cloud engine sends audio or text to that provider: the page says
 * so next to every provider (privacy, KVKK/GDPR).
 */

require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/secret_box.php';
require_once dirname(__DIR__, 2) . '/audit_log.php';
require_once dirname(__DIR__) . '/AiTtsService.php';
require_once dirname(__DIR__) . '/LocalAiService.php';
require_once __DIR__ . '/AiLanguages.php';

final class CloudAiException extends RuntimeException
{
}

class CloudAiService
{
    public const CAPS = ['tts', 'stt', 'llm', 'embed'];
    private const GOOGLE_AI_MODEL = 'gemini-3.5-flash-lite';
    /** Language models used when the account names none (cloud_llm.DEFAULT_MODELS). */
    public const LLM_DEFAULTS = ['nvidia' => 'meta/llama-3.3-70b-instruct', 'qwen' => 'qwen-plus'];
    /** International (Singapore) endpoint; mainland China: https://dashscope.aliyuncs.com/compatible-mode/v1 */
    public const QWEN_ENDPOINT = 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1';
    /** Accounts whose Model / address fields are for their language model. */
    public const LLM_FIELDS_OWN = ['nvidia', 'qwen', 'openai_compat', 'ollama', 'nvidia_local', 'claude', 'mistral', 'cohere'];
    /** What the AI service uses when an account leaves its model empty (cloud_stt.py / cloud_llm.py defaults). */
    public const STT_MODEL_DEFAULTS = ['openai' => 'gpt-4o-mini-transcribe', 'groq' => 'whisper-large-v3-turbo', 'deepgram' => 'nova-3', 'elevenlabs' => 'scribe_v1'];
    /**
     * The model lists the account form offers before the provider's own list is loaded (that needs the key):
     * the defaults of the AI service and a few common ones, by what they are for. The provider's list replaces them.
     */
    public const KNOWN_MODELS = [
        'openai' => ['llm' => ['gpt-4.1-mini', 'gpt-4.1', 'gpt-4o-mini', 'gpt-4o'],
                     'stt' => ['gpt-4o-mini-transcribe', 'gpt-4o-transcribe', 'whisper-1'],
                     'tts' => ['gpt-4o-mini-tts', 'tts-1', 'tts-1-hd'], 'realtime' => ['gpt-realtime']],
        'groq' => ['llm' => ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant'],
                   'stt' => ['whisper-large-v3-turbo', 'whisper-large-v3']],
        'google_ai' => ['llm' => ['gemini-3.5-flash-lite', 'gemini-2.5-flash', 'gemini-2.5-pro'], 'realtime' => ['gemini-3.1-flash-live-preview']],
        'claude' => ['llm' => ['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5']],
        'mistral' => ['llm' => ['mistral-small-latest', 'mistral-medium-latest', 'mistral-large-latest']],
        'cohere' => ['llm' => ['command-a-03-2025']],
        'nvidia' => ['llm' => ['meta/llama-3.3-70b-instruct']],
        'qwen' => ['llm' => ['qwen-plus', 'qwen-turbo', 'qwen-max'], 'realtime' => ['qwen-omni-turbo-realtime']],
        'openrouter' => ['llm' => ['google/gemini-2.5-flash']],
        'ollama' => ['llm' => ['llama3.2']],
        'deepgram' => ['stt' => ['nova-3', 'nova-2']],
        'elevenlabs' => ['stt' => ['scribe_v1'], 'tts' => ['eleven_multilingual_v2', 'eleven_flash_v2_5', 'eleven_turbo_v2_5']],
    ];
    public const REALTIME_MODELS = [
        'openai' => 'gpt-realtime',
        'google_ai' => 'gemini-3.1-flash-live-preview',
        'qwen' => 'qwen-omni-turbo-realtime',
        'openai_compat' => '',
    ];

    // ------------------------------------------------------------ languages

    /**
     * The languages AI jobs run in (AiLanguages::TABLE): code => locale and name. The name is what language models are
     * told to speak or write, the locale goes to speech recognition and synthesis.
     * @return array<string, array{locale: string, name: string}>
     */
    public static function languages(): array
    {
        return AiLanguages::TABLE;
    }

    /** The language of a code ("tr", "tr_TR", "pt-BR", an alias) when the table has it; else the PBX's own language, else English. */
    public static function language(string $lang): string
    {
        $lang = strtolower(str_replace('_', '-', trim($lang)));
        foreach ([substr($lang, 0, 3), substr($lang, 0, 2)] as $code) {
            $code = AiLanguages::ALIASES[$code] ?? $code;
            if (isset(AiLanguages::TABLE[$code])) {
                return $code;
            }
        }
        $own = class_exists('MailTemplateService') ? (string) MailTemplateService::systemLanguage() : '';
        return isset(AiLanguages::TABLE[$own]) ? $own : 'en';
    }

    public static function languageName(string $lang): string
    {
        return AiLanguages::TABLE[self::language($lang)]['name'];
    }

    public static function languageLocale(string $lang): string
    {
        return AiLanguages::TABLE[self::language($lang)]['locale'];
    }

    private static function isBlockedIp(string $ip, bool $allowPrivate): bool
    {
        if ($ip === '') {
            return false;
        }
        $lower = strtolower($ip);
        if (str_starts_with($lower, '169.254.') || str_starts_with($lower, 'fe80:') || str_starts_with($lower, '0.') || $lower === '::') {
            return true;
        }
        if ($allowPrivate) {
            return false;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            || $lower === '127.0.0.1' || $lower === '::1';
    }

    private static function assertSafeEndpoint(string $url, bool $allowPrivate): void
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if ($host === '' || $host === 'metadata.google.internal') {
            throw new CloudAiException(t('ai_cloud.err_base_url'));
        }
        if (!$allowPrivate && ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal'))) {
            throw new CloudAiException(t('ai_cloud.err_base_url'));
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (self::isBlockedIp($host, $allowPrivate)) {
                throw new CloudAiException(t('ai_cloud.err_base_url'));
            }
            return;
        }
        $ips = @gethostbynamel($host);
        if (is_array($ips)) {
            foreach ($ips as $ip) {
                if (self::isBlockedIp($ip, $allowPrivate)) {
                    throw new CloudAiException(t('ai_cloud.err_base_url'));
                }
            }
        }
    }

    /**
     * Address of an OpenAI-compatible chat API, or null: http(s), no
     * credentials or fragments in it, at most 200 characters.
     */
    public static function validBaseUrl(string $url, bool $allowPrivate = true): ?string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '' || strlen($url) > 200 || !preg_match('~^https?://[^\s/?#@]+(/[^\s?#]*)?$~', $url)
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if ($host === 'metadata.google.internal' || (filter_var($host, FILTER_VALIDATE_IP) !== false && self::isBlockedIp($host, $allowPrivate))) {
            return null;
        }
        if (!$allowPrivate && ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal'))) {
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
        $type = self::typeOf($id);
        // The AI service picks the endpoint by provider: it gets the type, not the account.
        $out = ['provider' => $type, 'api_key' => (string) ($cfg['api_key'] ?? '')];
        // OpenAI's and ElevenLabs' "Model" field names their speech model (Cloud TTS): their language model is llm_model.
        $model = trim((string) ($cfg['llm_model'] ?? ''));
        if (in_array($type, self::LLM_FIELDS_OWN, true)) {
            $model = $model ?: trim((string) ($cfg['model'] ?? ''));
            if (trim((string) ($cfg['base_url'] ?? '')) !== '') {
                $out['base_url'] = trim((string) $cfg['base_url']);
            }
        }
        if ($model !== '') {
            $out['model'] = $model;
        }
        foreach (['llm_temperature' => 'temperature', 'llm_max_tokens' => 'max_tokens'] as $key => $name) {
            if (is_numeric($cfg[$key] ?? null)) {
                $out[$name] = $name === 'temperature' ? (float) $cfg[$key] : (int) $cfg[$key];
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
        $providers = [
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
            'claude' => ['title' => 'Anthropic Claude', 'caps' => ['llm'],
                         'fields' => [$key[0], $model('claude-haiku-5-5')],
                         'url' => 'https://console.anthropic.com/settings/keys', 'free' => false],
            'mistral' => ['title' => 'Mistral AI', 'caps' => ['llm', 'embed'],
                          'fields' => [$key[0], $model('mistral-small-latest')],
                          'url' => 'https://console.mistral.ai/api-keys', 'free' => true],
            'cohere' => ['title' => 'Cohere', 'caps' => ['llm', 'embed'],
                         'fields' => [$key[0], $model('command-a-03-2025')],
                         'url' => 'https://dashboard.cohere.com/api-keys', 'free' => true],
            // OpenAI-compatible chat APIs (cloud_llm.py in the AI service talks to them).
            'nvidia' => ['title' => 'NVIDIA NIM (build.nvidia.com)', 'caps' => ['llm'],
                         'fields' => [$key[0], $model(self::LLM_DEFAULTS['nvidia'])],
                         'url' => 'https://build.nvidia.com/settings/api-keys', 'free' => true],
            'qwen' => ['title' => 'Qwen (Alibaba Cloud Model Studio)', 'caps' => ['llm'],
                       'fields' => [$key[0], $model(self::LLM_DEFAULTS['qwen']),
                                    ['key' => 'base_url', 'label' => 'Endpoint', 'secret' => false, 'optional' => true,
                                     'default' => self::QWEN_ENDPOINT]],
                       'url' => 'https://modelstudio.console.alibabacloud.com/', 'free' => true],
            'ollama'        => ['title' => 'Ollama (Local or Cloud API)', 'caps' => ['llm', 'embed'],
                                'fields' => [['key' => 'base_url', 'label' => 'Base URL (…/v1)', 'secret' => false, 'default' => 'http://127.0.0.1:11434/v1'],
                                             ['key' => 'model', 'label' => 'Model', 'secret' => false, 'default' => 'llama3.2'],
                                             ['key' => 'api_key', 'label' => 'API key (optional for local)', 'secret' => true, 'optional' => true]],
                                'url' => 'https://ollama.com/', 'free' => true],
            'nvidia_local'  => ['title' => 'NVIDIA NIM (Local Container)', 'caps' => ['llm', 'embed'],
                                'fields' => [['key' => 'base_url', 'label' => 'Base URL (…/v1)', 'secret' => false, 'default' => 'http://127.0.0.1:8000/v1'],
                                             ['key' => 'model', 'label' => 'Model', 'secret' => false, 'default' => 'meta/llama-3.1-8b-instruct'],
                                             ['key' => 'api_key', 'label' => 'API key (optional)', 'secret' => true, 'optional' => true]],
                                'url' => 'https://build.nvidia.com/', 'free' => false],
            'openai_compat' => ['title' => 'OpenAI-compatible (Cloud or Remote Server)', 'caps' => ['stt', 'llm', 'embed', 'tts'],
                                'fields' => [['key' => 'base_url', 'label' => 'Base URL (…/v1)', 'secret' => false],
                                             ['key' => 'model', 'label' => 'LLM Model', 'secret' => false],
                                             ['key' => 'stt_model', 'label' => 'STT / Whisper Model', 'secret' => false, 'optional' => true, 'default' => 'whisper-1'],
                                             ['key' => 'tts_model', 'label' => 'TTS Model', 'secret' => false, 'optional' => true, 'default' => 'tts-1'],
                                             ['key' => 'tts_voice', 'label' => 'TTS Voice', 'secret' => false, 'optional' => true, 'default' => 'alloy'],
                                             ['key' => 'api_key', 'label' => 'API key', 'secret' => true, 'optional' => true]],
                                'url' => 'https://platform.openai.com/docs/api-reference/chat', 'free' => false],
        ];
        // The settings of one account that most people never change (marked advanced: the dialog folds them).
        foreach ($providers as $type => &$p) {
            $own = array_column($p['fields'], 'key');
            if (in_array('llm', $p['caps'], true)) {
                if (!in_array($type, self::LLM_FIELDS_OWN, true)) {
                    $p['fields'][] = ['key' => 'llm_model', 'label' => 'LLM Model', 'secret' => false, 'optional' => true, 'advanced' => true];
                }
                if ($type !== 'claude') {   // current Claude models refuse temperature
                    $p['fields'][] = ['key' => 'llm_temperature', 'label' => 'Temperature (0-2)', 'secret' => false, 'optional' => true, 'advanced' => true, 'placeholder' => '0'];
                }
                $p['fields'][] = ['key' => 'llm_max_tokens', 'label' => 'Max tokens', 'secret' => false, 'optional' => true, 'advanced' => true, 'placeholder' => '512'];
            }
            if (in_array('stt', $p['caps'], true) && !in_array('stt_model', $own, true)) {
                $p['fields'][] = ['key' => 'stt_model', 'label' => 'STT Model', 'secret' => false, 'optional' => true, 'advanced' => true,
                                  'placeholder' => self::STT_MODEL_DEFAULTS[$type] ?? ''];
            }
        }
        unset($p);
        foreach (self::REALTIME_MODELS as $type => $modelName) {
            $providers[$type]['fields'][] = [
                'key' => 'realtime_model', 'label' => t('ai_cloud.realtime_model'),
                'secret' => false, 'optional' => true, 'advanced' => true, 'placeholder' => $modelName,
            ];
            // The Realtime session of this account: a different voice or detection is a second account ("Copy").
            $rt = static fn (string $key, string $label, string $placeholder, array $extra = []): array =>
                ['key' => $key, 'label' => t($label), 'secret' => false, 'optional' => true, 'advanced' => true, 'placeholder' => $placeholder] + $extra;
            $providers[$type]['fields'][] = $rt('realtime_voice', 'ai_cloud.realtime_voice', $type === 'google_ai' ? 'Kore' : 'alloy');
            $providers[$type]['fields'][] = $rt('realtime_vad_threshold', 'builtin_ai_agent.rt_vad_threshold', '0.5');
            $providers[$type]['fields'][] = $rt('realtime_silence_ms', 'builtin_ai_agent.rt_vad_silence_ms', '500');
            $providers[$type]['fields'][] = $rt('realtime_temperature', 'builtin_ai_agent.rt_temperature', '');
            if ($type === 'openai' || $type === 'openai_compat') {
                $providers[$type]['fields'][] = $rt('realtime_noise_reduction', 'builtin_ai_agent.rt_noise_reduction', '', ['options' => [
                    '' => t('builtin_ai_agent.nr_off'), 'near_field' => t('builtin_ai_agent.nr_near_field'), 'far_field' => t('builtin_ai_agent.nr_far_field')]]);
            }
        }
        $providers['openai_compat']['fields'][] = [
            'key' => 'realtime_protocol', 'label' => t('ai_cloud.realtime_protocol'),
            'secret' => false, 'optional' => true, 'advanced' => true,
            'options' => [
                '' => t('ai_cloud.realtime_automatic'),
                'openai_ga' => 'OpenAI Realtime GA',
                'openai_beta' => 'OpenAI Realtime Beta',
            ],
        ];
        $providers['openai_compat']['fields'][] = [
            'key' => 'realtime_ws_url', 'label' => t('ai_cloud.realtime_ws_url'),
            'secret' => false, 'optional' => true, 'advanced' => true, 'placeholder' => 'wss://example.com/v1/realtime',
        ];
        return $providers;
    }

    // ------------------------------------------------------------ accounts

    private const ACCOUNTS_SETTING = 'ai_cloud_accounts';
    public const MAX_ACCOUNTS = 50;

    /**
     * The stored list as it is: account id => type, name, enabled (removed: a type's own
     * account deleted on the page). Entries with an unknown type are left out.
     * @return array<string, array{type: string, name: string, enabled: bool, removed: bool}>
     */
    private static function registry(): array
    {
        $raw = function_exists('getSystemSetting') ? json_decode((string) getSystemSetting(self::ACCOUNTS_SETTING, ''), true) : null;
        $types = self::providers();
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $id => $a) {
            if (is_string($id) && preg_match('/^[a-z_]{2,20}$/', $id) && is_array($a) && isset($types[$a['type'] ?? ''])
                && (($a['type'] ?? '') === $id || !isset($types[$id]))) {
                $out[$id] = ['type' => (string) $a['type'], 'name' => mb_substr(trim((string) ($a['name'] ?? '')), 0, 60),
                             'enabled' => ($a['enabled'] ?? true) !== false, 'removed' => !empty($a['removed'])];
            }
        }
        return $out;
    }

    private static function saveRegistry(array $reg): void
    {
        setSystemSetting(self::ACCOUNTS_SETTING, (string) json_encode($reg, JSON_UNESCAPED_UNICODE));
    }

    /** An engine choice may name any listed account, or a provider's own account not yet set up. */
    /** typeOf() for a reference that may be stale: '' instead of an error. */
    public static function typeIfKnown(string $id): string
    {
        try {
            return self::typeOf($id);
        } catch (CloudAiException $e) {
            return '';
        }
    }

    /** The provider type of an account ("openai" for "openai_b"). */
    public static function typeOf(string $id): string
    {
        $reg = self::registry();
        if (isset($reg[$id])) {
            return $reg[$id]['type'];
        }
        if (isset(self::providers()[$id])) {
            return $id;
        }
        throw new CloudAiException('Unknown provider.');
    }

    /**
     * Every account the page lists: the list's entries, and each type's own account when
     * it has settings (stored before accounts existed) or works without any (local Ollama).
     * @return array<string, array{type: string, name: string, enabled: bool}>
     */
    public static function accounts(): array
    {
        $reg = self::registry();
        $out = [];
        foreach (self::providers() as $type => $p) {
            if (!isset($reg[$type]) && self::fieldsComplete($type)) {
                $out[$type] = ['type' => $type, 'name' => '', 'enabled' => true];
            }
        }
        foreach ($reg as $id => $a) {
            if (!$a['removed']) {
                $out[$id] = ['type' => $a['type'], 'name' => $a['name'], 'enabled' => $a['enabled']];
            }
        }
        return $out;
    }

    /** What an account is called on pages: its own name, else its provider's title. */
    public static function label(string $id): string
    {
        $name = self::registry()[$id]['name'] ?? '';
        return $name !== '' ? $name : (string) self::providers()[self::typeOf($id)]['title'];
    }

    /** A new account of $type; its settings are saved like any account's. Returns its id. */
    public static function addAccount(string $type, string $name, array $post): string
    {
        if (!isset(self::providers()[$type])) {
            throw new CloudAiException('Unknown provider.');
        }
        $reg = self::registry();
        if (count(self::accounts()) >= self::MAX_ACCOUNTS) {
            throw new CloudAiException(sprintf(t('ai_cloud.err_accounts_many'), self::MAX_ACCOUNTS));
        }
        // The type's own id while it is free: a single account keeps the id engine choices know.
        $id = $type;
        if (isset(self::accounts()[$type])) {
            $id = '';
            foreach (range('b', 'z') as $c) {
                $try = substr($type, 0, 18) . '_' . $c;
                if (!isset($reg[$try]) && !isset(self::providers()[$try])) {
                    $id = $try;
                    break;
                }
            }
            if ($id === '') {
                throw new CloudAiException(sprintf(t('ai_cloud.err_accounts_many'), self::MAX_ACCOUNTS));
            }
        }
        $reg[$id] = ['type' => $type, 'name' => self::cleanName($name), 'enabled' => true];
        self::saveRegistry($reg);
        try {
            self::save($id, $post);
        } catch (\Throwable $e) {
            unset($reg[$id]);
            if ($id === $type) {
                self::deleteSettings($id);
            }
            self::saveRegistry($reg);
            throw $e;
        }
        return $id;
    }

    /** A new account with the same provider, key and settings under another name ("Copy account": a second model of one provider). */
    public static function copyAccount(string $id): string
    {
        $cfg = self::config($id);
        $post = [];
        foreach (self::provider($id)['fields'] as $f) {
            $v = (string) ($cfg[$f['key']] ?? '');
            // An unset field answers its default: do not store that as a choice.
            if ($v !== '' && ($f['secret'] || $v !== (string) ($f['default'] ?? ''))) {
                $post[$f['key']] = $v;
            }
        }
        return self::addAccount(self::typeOf($id), mb_substr(self::label($id), 0, 52) . ' 2', $post);
    }

    public static function renameAccount(string $id, string $name): void
    {
        $type = self::typeOf($id);
        $reg = self::registry();
        $reg[$id] = ['type' => $type, 'name' => self::cleanName($name), 'enabled' => $reg[$id]['enabled'] ?? true];
        self::saveRegistry($reg);
    }

    /** A switched-off account keeps its settings but no job uses it. */
    public static function setEnabled(string $id, bool $enabled): void
    {
        $type = self::typeOf($id);
        $reg = self::registry();
        $reg[$id] = ['type' => $type, 'name' => $reg[$id]['name'] ?? '', 'enabled' => $enabled];
        self::saveRegistry($reg);
        writeAuditLog('ai_cloud', 'provider', $id, self::label($id) . ($enabled ? ' (enabled)' : ' (disabled)'), 'update', $_SESSION['user_id'] ?? null);
    }

    /** Removes an account with its settings, limits and model list. */
    public static function deleteAccount(string $id): void
    {
        $type = self::typeOf($id);
        $label = self::label($id);
        self::deleteSettings($id);
        $reg = self::registry();
        unset($reg[$id]);
        self::saveRegistry($reg);
        if ($id === $type && self::fieldsComplete($type)) {
            // Works on its defaults alone (local Ollama): remembered as removed, or it would come back.
            $reg[$id] = ['type' => $type, 'name' => '', 'enabled' => false, 'removed' => true];
        }
        self::saveRegistry($reg);
        writeAuditLog('ai_cloud', 'provider', $id, $label . ' (account removed)', 'delete', $_SESSION['user_id'] ?? null);
    }

    private static function deleteSettings(string $id): void
    {
        $db = getDB();
        foreach (["ai_tts.{$id}.%", "ai_limit.{$id}.%"] as $like) {
            $db->prepare('DELETE FROM sys_settings WHERE setting_key LIKE ?')->execute([str_replace('_', '\_', $like)]);
        }
        $db->prepare('DELETE FROM sys_settings WHERE setting_key = ?')->execute(['ai_cloud_cache.' . $id]);
    }

    private static function cleanName(string $name): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name)), 0, 60);
    }

    /**
     * The provider of an account: title, capabilities, fields, sign-up page ('title' is
     * the account's label, 'type' its provider).
     */
    public static function provider(string $id): array
    {
        $type = self::typeOf($id);
        $p = self::providers()[$type];
        return ['type' => $type, 'provider_title' => $p['title'], 'title' => self::label($id)] + $p;
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

    public static function isLoopbackOllama(string $id): bool
    {
        if (self::typeIfKnown($id) !== 'ollama') {
            return false;
        }
        $host = strtolower((string) parse_url((string) (self::config($id)['base_url'] ?? ''), PHP_URL_HOST));
        return in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
    }

    public static function configured(string $id, array $post = []): bool
    {
        if (function_exists('getSystemSetting') && getSystemSetting('privacy_block_cloud_ai', '0') === '1' && !self::isLoopbackOllama($id)) {
            return false;
        }
        $a = self::registry()[$id] ?? null;
        if ($a !== null && ($a['removed'] || !$a['enabled'])) {
            return false;
        }
        return self::fieldsComplete($id, $post);
    }

    /** Completeness check for interactive Test / Discover (allows disabled accounts and fresh 2nd accounts). */
    public static function configuredForTest(string $id, array $post = [], bool $isNew = false): bool
    {
        if (function_exists('getSystemSetting') && getSystemSetting('privacy_block_cloud_ai', '0') === '1' && !self::isLoopbackOllama($id)) {
            return false;
        }
        return self::fieldsComplete($id, $post, $isNew);
    }

    /** Whether the account's required fields are filled (stored, or typed in $post). */
    private static function fieldsComplete(string $id, array $post = [], bool $isNew = false): bool
    {
        $fields = self::provider($id)['fields'];
        if ($isNew) {
            $cfg = [];
            foreach ($fields as $f) {
                if (empty($f['secret']) && array_key_exists('default', $f)) {
                    $cfg[$f['key']] = (string) $f['default'];
                }
            }
        } else {
            $cfg = self::config($id);
        }
        foreach ($fields as $f) {
            $k = $f['key'];
            if (isset($post[$k]) && trim((string) $post[$k]) !== '') {
                $cfg[$k] = trim((string) $post[$k]);
            }
        }
        // Google Cloud: either the service account or the API key.
        if (self::typeOf($id) === 'google') {
            return trim($cfg['service_account'] ?? '') !== '' || trim($cfg['api_key'] ?? '') !== '';
        }
        foreach ($fields as $f) {
            if (empty($f['optional']) && trim($cfg[$f['key']] ?? '') === '') {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> configured, switched-on accounts that can do $cap */
    public static function withCap(string $cap): array
    {
        return array_values(array_filter(array_keys(self::accounts()),
            fn($id) => in_array($cap, self::provider($id)['caps'], true) && self::configured($id)));
    }

    /** The page's view of every provider: secrets only masked. */
    /**
     * The page's view of every account (the table): secrets only masked.
     * 'configured' is false for a switched-off account; 'complete' says whether its fields are filled.
     * @return list<array<string, mixed>>
     */
    public static function forPage(): array
    {
        $usage = self::usageThisMonth();
        $out = [];
        foreach (self::accounts() as $id => $a) {
            $p = self::provider($id);
            $cfg = self::config($id);
            $fields = [];
            foreach ($p['fields'] as $f) {
                $v = $cfg[$f['key']] ?? '';
                $masked = !empty($f['json']) ? (string) (json_decode($v, true)['client_email'] ?? '') : SecretBox::mask($v);
                $fields[] = $f + ['value' => $f['secret'] ? '' : $v, 'masked' => $f['secret'] ? $masked : ''];
            }
            $out[] = ['id' => $id, 'type' => $a['type'], 'name' => $a['name'], 'title' => $p['title'], 'provider_title' => $p['provider_title'],
                      'caps' => $p['caps'], 'url' => $p['url'], 'free' => $p['free'], 'enabled' => $a['enabled'],
                      'configured' => self::configured($id), 'complete' => self::fieldsComplete($id),
                      'fields' => $fields, 'usage' => $usage[$id] ?? null,
                      'limits' => self::limits($id), 'quota' => self::usageStatus($id, $usage[$id] ?? null)];
        }
        usort($out, fn($a, $b) => [$a['provider_title'], $a['title']] <=> [$b['provider_title'], $b['title']]);
        return $out;
    }

    /**
     * The providers an account can be added for (the Add dialog): fields with their defaults.
     * @return list<array<string, mixed>>
     */
    public static function typesForPage(): array
    {
        $out = [];
        foreach (self::providers() as $type => $p) {
            $out[] = ['type' => $type, 'title' => $p['title'], 'caps' => $p['caps'], 'url' => $p['url'], 'free' => $p['free'],
                      'models' => (object) (self::KNOWN_MODELS[$type] ?? []),
                      'fields' => array_map(fn($f) => $f + ['value' => $f['secret'] ? '' : (string) ($f['default'] ?? ''), 'masked' => ''], $p['fields'])];
        }
        usort($out, static fn ($a, $b) => strcasecmp($a['title'], $b['title']));      // the "Add a provider account" list: by name
        return $out;
    }

    public static function save(string $id, array $post): void
    {
        $p = self::provider($id);
        $db = getDB();
        $values = [];
        $clear = !empty($post['clear']);
        foreach ($p['fields'] as $f) {
            $field = $f['key'];
            if (!$clear && str_starts_with($field, 'realtime_') && !array_key_exists($field, $post)) {
                continue;
            }
            $v = $clear ? '' : trim((string) ($post[$field] ?? ''));
            if ($v !== '' && !empty($f['json']) && $p['type'] === 'google') {
                $v = GoogleTts::validateServiceAccount($v);
            }
            if ($v !== '' && $field === 'base_url') {
                $v = self::validBaseUrl($v) ?? throw new CloudAiException(t('ai_cloud.err_base_url'));
            }
            if ($v !== '' && in_array($field, ['model', 'realtime_model', 'llm_model', 'stt_model'], true) && !preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,99}$#D', $v)) {
                throw new CloudAiException(t('ai_cloud.err_model'));
            }
            if ($v !== '' && $field === 'llm_temperature' && (!is_numeric($v) || $v < 0 || $v > 2)) {
                throw new CloudAiException(t('ai_cloud.err_number'));
            }
            $ranges = ['realtime_vad_threshold' => [0.1, 0.99], 'realtime_silence_ms' => [200, 3000], 'realtime_temperature' => [0.2, 1.2]];
            if ($v !== '' && isset($ranges[$field]) && (!is_numeric($v) || $v < $ranges[$field][0] || $v > $ranges[$field][1])) {
                throw new CloudAiException(t('ai_cloud.err_number'));
            }
            if ($v !== '' && $field === 'realtime_voice' && !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $v)) {
                throw new CloudAiException(t('ai_cloud.err_model'));
            }
            if ($v !== '' && $field === 'realtime_noise_reduction' && !array_key_exists($v, $f['options'])) {
                throw new CloudAiException(t('ai_cloud.err_number'));
            }
            if ($v !== '' && $field === 'llm_max_tokens' && (!ctype_digit($v) || (int) $v < 16 || (int) $v > 4096)) {
                throw new CloudAiException(t('ai_cloud.err_number'));
            }
            if ($v !== '' && $field === 'realtime_protocol' && !array_key_exists($v, $f['options'])) {
                throw new CloudAiException(t('ai_cloud.err_realtime_protocol'));
            }
            if ($v !== '' && $field === 'realtime_ws_url' && (strlen($v) > 200
                || !preg_match('~^wss?://[^\s/?#@]+(/[^\s?#]*)?$~D', $v)
                || filter_var($v, FILTER_VALIDATE_URL) === false)) {
                throw new CloudAiException(t('ai_cloud.err_realtime_ws_url'));
            }
            $values[$field] = $v;
        }
        $set = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $del = $db->prepare('DELETE FROM sys_settings WHERE setting_key = ?');
        foreach ($p['fields'] as $f) {
            if (!array_key_exists($f['key'], $values)) {
                continue;
            }
            $key = "ai_tts.{$id}.{$f['key']}";
            if ($clear) {
                $del->execute([$key]);
                continue;
            }
            $v = $values[$f['key']];
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
        self::saveLimits($id, $post);
        writeAuditLog('ai_cloud', 'provider', $id, $p['title'] . (!empty($post['clear']) ? ' (credentials removed)' : ' (settings saved)'), 'update', $_SESSION['user_id'] ?? null);
    }

    // ------------------------------------------------------------ connection test

    /**
     * A cheap authenticated call; throws with the provider's own message.
     * $post is the provider form as typed: fields not yet saved are what gets
     * tested. The old behaviour read only stored settings, so hitting Test
     * with a freshly pasted key sent no key at all and the provider's own
     * "no credentials" error read like a bad key.
     */
    public static function test(string $id, array $post = [], bool $isNew = false): string
    {
        $cfg = $isNew ? [] : self::config($id);
        $k = $cfg['api_key'] ?? '';
        $typedKey = trim((string) ($post['api_key'] ?? ''));
        if ($typedKey !== '') {
            $k = $typedKey;
        }
        switch (self::typeOf($id)) {
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
            case 'nvidia_local':
            case 'qwen':
            case 'ollama':
            case 'claude':
            case 'mistral':
            case 'cohere':
            case 'openai_compat':
                return self::testChat($id, $post, $isNew);
            case 'deepgram':
                $d = self::json(TtsHttp::request('GET', 'https://api.deepgram.com/v1/projects', ['Authorization' => 'Token ' . $k], null, 20), $id);
                return sprintf(t('ai_cloud.test_projects'), count($d['projects'] ?? []));
            default:
                // Speech providers: listing the voices proves the key.
                $count = AiTtsService::testConfig($id, $post);
                return sprintf(t('ai_tts.test_ok'), $count);
        }
    }

    /**
     * One tiny chat request: proves address, key and model together
     * (listing models would not show a wrong model name).
     */
    private static function testChat(string $id, array $post = [], bool $isNew = false): string
    {
        require_once __DIR__ . '/AiGateway.php';
        $started = microtime(true);
        $draft = $post + ($isNew ? ['_is_new' => true] : []);
        AiGateway::chat('cloud:' . $id, 'Reply with OK.', 'panel', 300, $draft);
        $cfg = self::gatewayConfig($id, $draft);
        return sprintf(t('ai_cloud.test_chat'), $cfg['model'], (int) round((microtime(true) - $started) * 1000));
    }

    /**
     * Discovers available models for a configured LLM/embedding provider, cached for 15 minutes.
     * `kinds`: what each model is for (llm, stt, tts, realtime, embed, other), so that the account form offers
     * each model field only the models that fit it.
     * @return array{models: list<string>, kinds: array<string, string>, cached: bool}
     */
    public static function discoverModels(string $id, array $post = [], bool $refresh = false, bool $isNew = false): array
    {
        if (!in_array('llm', self::provider($id)['caps'], true)) {
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
        $type = self::typeOf($id);
        $allowPrivate = in_array($type, ['ollama', 'nvidia_local', 'openai_compat'], true);
        if ($type === 'google_ai') {
            $k = trim((string) ($post['api_key'] ?? '')) ?: ($isNew ? '' : (self::config($id)['api_key'] ?? ''));
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
            self::assertSafeEndpoint($base, false);
            $d = self::json(TtsHttp::request('GET', $base . '/models', ['x-api-key' => $key, 'anthropic-version' => '2023-06-01'], null, 20), $id);
            foreach ((array) ($d['data'] ?? []) as $m) {
                if (!empty($m['id'])) {
                    $models[] = (string) $m['id'];
                }
            }
        } else {
            self::assertSafeEndpoint($base, $allowPrivate);
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

    /** Credential resolver bridge; never expose this array to browser/worker job JSON. */
    public static function gatewayConfig(string $id, array $post = []): array
    {
        $isNew = !empty($post['_is_new']);
        $type = self::typeOf($id);
        if ($type === 'google_ai') {
            $cfg = $isNew ? [] : self::config($id);
            if (trim((string) ($post['api_key'] ?? '')) !== '') { $cfg['api_key'] = trim((string) ($post['api_key'] ?? '')); }
            $gModel = trim((string) ($post['llm_model'] ?? '')) ?: (trim((string) ($cfg['llm_model'] ?? '')) ?: self::GOOGLE_AI_MODEL);
            if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,99}$#D', $gModel)) {
                throw new CloudAiException(t('ai_cloud.err_model'));
            }
            return ['adapter' => 'google', 'model' => $gModel,
                'url' => 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($gModel) . ':generateContent',
                'api_key' => $cfg['api_key'] ?? ''];
        }
        [$base, $model, $key] = self::llmTarget($id, $post, $isNew);
        if ($type === 'claude') {
            return ['adapter' => 'anthropic', 'model' => $model, 'url' => $base . '/messages',
                'api_key' => $key, 'allow_private' => false];
        }
        return ['adapter' => 'openai', 'model' => $model, 'url' => $base . '/chat/completions',
            'api_key' => $key, 'allow_private' => in_array($type, ['ollama', 'nvidia_local', 'openai_compat'], true)];
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
        if (self::overLimit($id, 'stt')) {
            throw new CloudAiException(t('ai_cloud.err_quota_exceeded'));
        }
        $lang = self::language($lang);
        $cfg = self::config($id);
        $k = $cfg['api_key'] ?? '';
        $cfgSttModel = trim((string) ($cfg['stt_model'] ?? ''));
        if ($cfgSttModel !== '' && !preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,99}$#D', $cfgSttModel)) {
            throw new CloudAiException(t('ai_cloud.err_model'));
        }
        $started = microtime(true);
        $type = self::typeOf($id);
        switch ($type) {
            case 'openai':
            case 'groq':
                $url = $type === 'openai' ? 'https://api.openai.com/v1/audio/transcriptions' : 'https://api.groq.com/openai/v1/audio/transcriptions';
                $model = $cfgSttModel !== '' ? $cfgSttModel : self::STT_MODEL_DEFAULTS[$type];
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
                $dgModel = $cfgSttModel !== '' ? $cfgSttModel : self::STT_MODEL_DEFAULTS['deepgram'];
                $d = self::json(TtsHttp::request('POST', 'https://api.deepgram.com/v1/listen?model=' . rawurlencode($dgModel) . '&smart_format=true&language=' . rawurlencode($lang),
                    ['Authorization' => 'Token ' . $k, 'Content-Type' => 'audio/wav'], $wav, 120), $id);
                $text = (string) ($d['results']['channels'][0]['alternatives'][0]['transcript'] ?? '');
                break;
            case 'azure':
                $region = $cfg['region'] ?: 'westeurope';
                if (!preg_match('/^[a-z0-9]{3,30}$/', $region)) {
                    throw new CloudAiException('Azure: invalid region');
                }
                $d = self::json(TtsHttp::request('POST', "https://{$region}.stt.speech.microsoft.com/speech/recognition/conversation/cognitiveservices/v1?format=simple&language=" . rawurlencode(self::languageLocale($lang)),
                    ['Ocp-Apim-Subscription-Key' => $k, 'Content-Type' => 'audio/wav; codecs=audio/pcm; samplerate=16000'], $wav, 120), $id);
                $text = (string) ($d['DisplayText'] ?? '');
                break;
            case 'elevenlabs':
                $elModel = $cfgSttModel !== '' ? $cfgSttModel : self::STT_MODEL_DEFAULTS['elevenlabs'];
                [$body, $type] = self::multipart(['model_id' => $elModel, 'language_code' => $lang], 'file', 'speech.wav', $wav);
                $d = self::json(TtsHttp::request('POST', 'https://api.elevenlabs.io/v1/speech-to-text', ['xi-api-key' => $k, 'Content-Type' => $type], $body, 120), $id);
                $text = (string) ($d['text'] ?? '');
                break;
            case 'google_ai':
                $gSttModel = $cfgSttModel !== '' ? $cfgSttModel : self::GOOGLE_AI_MODEL;
                $prompt = 'Transcribe this telephone audio word for word in ' . self::languageName($lang)
                    . '. Output only the transcript, nothing else. If nothing is said, output nothing.';
                $req = ['contents' => [['parts' => [['text' => $prompt], ['inline_data' => ['mime_type' => 'audio/wav', 'data' => base64_encode($wav)]]]]],
                        'generationConfig' => ['temperature' => 0]];
                $d = self::json(TtsHttp::request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($gSttModel) . ':generateContent',
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
    private static function llmTarget(string $id, array $post = [], bool $isNew = false): array
    {
        $type = self::typeOf($id);
        $s = $isNew ? [] : self::llmSettings($id);
        $fixed = ['openai' => ['https://api.openai.com/v1', 'gpt-4.1-mini'],
                  'groq' => ['https://api.groq.com/openai/v1', 'llama-3.3-70b-versatile'],
                  'openrouter' => ['https://openrouter.ai/api/v1', 'google/gemini-2.5-flash'],
                  'claude' => ['https://api.anthropic.com/v1', 'claude-haiku-5-5'],
                  'mistral' => ['https://api.mistral.ai/v1', 'mistral-small-latest'],
                  'cohere' => ['https://api.cohere.com/compatibility/v1', 'command-a-03-2025'],
                  'nvidia' => ['https://integrate.api.nvidia.com/v1', self::LLM_DEFAULTS['nvidia']],
                  'qwen' => [self::QWEN_ENDPOINT, self::LLM_DEFAULTS['qwen']],
                  'ollama' => ['http://127.0.0.1:11434/v1', 'llama3.2'],
                  'nvidia_local' => ['http://127.0.0.1:8000/v1', 'meta/llama-3.1-8b-instruct']];
        [$base, $model] = $fixed[$type] ?? ['', ''];
        $storedBase = (string) ($s['base_url'] ?? '');
        if ($storedBase !== '') {
            $base = $storedBase;
        }
        if (!empty($s['model'])) {
            $model = (string) $s['model'];
        }
        $key = $isNew ? '' : (string) ($s['api_key'] ?? '');
        $ownAddress = in_array($type, ['qwen', 'openai_compat', 'ollama', 'nvidia_local'], true);
        $allowPrivate = in_array($type, ['ollama', 'nvidia_local', 'openai_compat'], true);
        // Form fields typed but not yet saved win over the stored account,
        // validated exactly as Save validates them.
        $typedBase = $ownAddress ? trim((string) ($post['base_url'] ?? '')) : '';
        $typedKey = trim((string) ($post['api_key'] ?? ''));
        if ($typedBase !== '') {
            $typedBase = self::validBaseUrl($typedBase, $allowPrivate) ?? throw new CloudAiException(t('ai_cloud.err_base_url'));
            if (!$isNew && $typedKey === '' && rtrim($typedBase, '/') !== rtrim($base, '/')) {
                $key = '';
            }
            $base = $typedBase;
        }
        if (in_array($type, self::LLM_FIELDS_OWN, true)) {
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

    /**
     * One answer of a cloud language model (temperature 0, except Claude). Google AI uses
     * Gemini's own API, every other provider the OpenAI chat API.
     */
    public static function chat(string $id, string $prompt, int $maxTokens = 300, int $timeout = 30): string
    {
        if (!in_array('llm', self::provider($id)['caps'], true) || !self::configured($id)) {
            throw new CloudAiException(t('ai_cloud.err_no_llm'));
        }
        if (!self::isLoopbackOllama($id) && self::overLimit($id, 'llm')) {
            throw new CloudAiException(t('ai_cloud.err_quota_exceeded'));
        }
        $type = self::typeOf($id);
        if ($type === 'google_ai') {
            $gCfg = self::config($id);
            $gModel = trim((string) ($gCfg['llm_model'] ?? '')) ?: self::GOOGLE_AI_MODEL;
            if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,99}$#D', $gModel)) {
                throw new CloudAiException(t('ai_cloud.err_model'));
            }
            $d = self::json(TtsHttp::request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($gModel) . ':generateContent',
                ['x-goog-api-key' => $gCfg['api_key'] ?? '', 'Content-Type' => 'application/json'],
                (string) json_encode(['contents' => [['parts' => [['text' => $prompt]]]],
                                      'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => $maxTokens]]), $timeout), $id);
            self::count($id, 'llm_tokens', (float) ($d['usageMetadata']['totalTokenCount'] ?? 0));
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
            self::count($id, 'llm_tokens', $usage);
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
        require_once __DIR__ . '/AiJobService.php';
        $reserved = AiJobService::consumeReservedQuota($provider, $unit);
        $delta = $amount - $reserved;
        $insertVal = max(0.0, $delta);
        try {
            getDB()->prepare("INSERT INTO ai_cloud_usage (provider, month, {$unit}) VALUES (?, ?, ?)
                              ON DUPLICATE KEY UPDATE {$unit} = GREATEST(0, {$unit} + ?)")
                ->execute([$provider, date('Y-m'), $insertVal, $delta]);
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

    /**
     * Monthly quota limits configured for a provider (0 = unlimited).
     * @return array{stt_min: int, tts_chars: int, llm_tokens: int}
     */
    public static function limits(string $id): array
    {
        return [
            'stt_min' => max(0, (int) getSystemSetting("ai_limit.{$id}.stt_min", '0')),
            'tts_chars' => max(0, (int) getSystemSetting("ai_limit.{$id}.tts_chars", '0')),
            'llm_tokens' => max(0, (int) getSystemSetting("ai_limit.{$id}.llm_tokens", '0')),
        ];
    }

    public static function saveLimits(string $id, array $post): void
    {
        $db = getDB();
        $set = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $del = $db->prepare('DELETE FROM sys_settings WHERE setting_key = ?');
        foreach (['stt_min', 'tts_chars', 'llm_tokens'] as $metric) {
            $key = "ai_limit.{$id}.{$metric}";
            if (!empty($post['clear'])) {
                $del->execute([$key]);
                continue;
            }
            $field = "limit_{$metric}";
            if (!array_key_exists($field, $post)) {
                continue;
            }
            $v = max(0, (int) $post[$field]);
            if ($v <= 0) {
                $del->execute([$key]);
            } else {
                $set->execute([$key, (string) $v]);
            }
        }
    }

    /**
     * Computes this month's quota percentage and warning/exceeded flags.
     * @param array{stt_seconds?: float|int, tts_chars?: int, llm_tokens?: int}|null $usageRow
     * @return array{pct: int, warn: bool, exceeded: bool, jobs: array{stt: bool, tts: bool, llm: bool}}
     */
    public static function usageStatus(string $id, ?array $usageRow = null): array
    {
        $limits = self::limits($id);
        if ($usageRow === null) {
            $usageRow = self::usageThisMonth()[$id] ?? [];
            require_once __DIR__ . '/AiJobService.php';
            $usageRow['stt_seconds'] = max(0.0, (float) ($usageRow['stt_seconds'] ?? 0) - AiJobService::activeReservedQuota($id, 'stt_seconds'));
            $usageRow['tts_chars'] = max(0, (int) ($usageRow['tts_chars'] ?? 0) - AiJobService::activeReservedQuota($id, 'tts_chars'));
            $usageRow['llm_tokens'] = max(0, (int) ($usageRow['llm_tokens'] ?? 0) - AiJobService::activeReservedQuota($id, 'llm_tokens'));
        }
        $caps = [
            'stt' => [$limits['stt_min'] * 60, (float) ($usageRow['stt_seconds'] ?? 0)],
            'tts' => [$limits['tts_chars'], (float) ($usageRow['tts_chars'] ?? 0)],
            'llm' => [$limits['llm_tokens'], (float) ($usageRow['llm_tokens'] ?? 0)],
        ];
        $maxPct = 0;
        $jobs = ['stt' => false, 'tts' => false, 'llm' => false];
        foreach ($caps as $job => [$lim, $used]) {
            if ($lim <= 0) {
                continue;
            }
            $pct = (int) floor(($used / $lim) * 100);
            if ($used >= $lim) {
                $jobs[$job] = true;
                $pct = max($pct, 100);
            }
            $maxPct = max($maxPct, $pct);
        }
        $exceeded = in_array(true, $jobs, true) || $maxPct >= 100;
        return [
            'pct' => $maxPct,
            'warn' => !$exceeded && $maxPct >= 80,
            'exceeded' => $exceeded,
            'jobs' => $jobs,
        ];
    }

    public static function overLimit(string $id, string $job = ''): bool
    {
        $st = self::usageStatus($id);
        if ($job !== '' && array_key_exists($job, $st['jobs'])) {
            return $st['jobs'][$job];
        }
        return $st['exceeded'];
    }
}
