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

final class CloudAiException extends RuntimeException
{
}

class CloudAiService
{
    public const CAPS = ['tts', 'stt', 'llm', 'embed'];
    /** Languages until the administrator changes the list (setting ai_languages). */
    public const DEFAULT_LANGUAGES = [
        'tr' => ['locale' => 'tr-TR', 'name' => 'Turkish'],
        'de' => ['locale' => 'de-DE', 'name' => 'German'],
        'en' => ['locale' => 'en-US', 'name' => 'English'],
    ];
    public const MAX_LANGUAGES = 30;
    private const GOOGLE_AI_MODEL = 'gemini-3.5-flash-lite';
    private const GOOGLE_AI_GENERATE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/' . self::GOOGLE_AI_MODEL . ':generateContent';
    /** Language models used when the account names none (cloud_llm.DEFAULT_MODELS). */
    public const LLM_DEFAULTS = ['nvidia' => 'meta/llama-3.3-70b-instruct', 'qwen' => 'qwen-plus'];
    /** International (Singapore) endpoint; mainland China: https://dashscope.aliyuncs.com/compatible-mode/v1 */
    public const QWEN_ENDPOINT = 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1';
    /** Accounts whose Model / address fields are for their language model. */
    public const LLM_FIELDS_OWN = ['nvidia', 'qwen', 'openai_compat', 'ollama'];
    public const REALTIME_MODELS = [
        'openai' => 'gpt-realtime',
        'google_ai' => 'gemini-3.8-live',
        'qwen' => 'qwen-omni-turbo-realtime',
        'openai_compat' => '',
    ];

    // ------------------------------------------------------------ languages

    /**
     * The languages AI jobs run in: code => locale and name (the name is also
     * what language models are told to speak or write, so "French" or
     * "Français" both work).
     * @return array<string, array{locale: string, name: string}>
     */
    public static function languages(): array
    {
        $raw = function_exists('getSystemSetting') ? (string) getSystemSetting('ai_languages', '') : '';
        $list = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($list) || $list === []) {
            return self::DEFAULT_LANGUAGES;
        }
        $out = [];
        foreach ($list as $code => $l) {
            if (is_string($code) && is_array($l) && self::validLanguage($code, (string) ($l['locale'] ?? ''), (string) ($l['name'] ?? '')) === null) {
                $out[$code] = ['locale' => (string) $l['locale'], 'name' => (string) $l['name']];
            }
        }
        return $out ?: self::DEFAULT_LANGUAGES;
    }

    /** $lang when it is in the list, else the first language of the list. */
    public static function language(string $lang): string
    {
        $all = self::languages();
        $lang = strtolower(substr(trim($lang), 0, 3));
        if (isset($all[$lang])) {
            return $lang;
        }
        $two = substr($lang, 0, 2);
        return isset($all[$two]) ? $two : (string) array_key_first($all);
    }

    public static function languageName(string $lang): string
    {
        return self::languages()[self::language($lang)]['name'];
    }

    public static function languageLocale(string $lang): string
    {
        return self::languages()[self::language($lang)]['locale'];
    }

    /** null when valid, else the error text. */
    public static function validLanguage(string $code, string $locale, string $name): ?string
    {
        if (!preg_match('/^[a-z]{2,3}$/', $code)) {
            return t('ai_cloud.err_lang_code');
        }
        if (!preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8}){0,2}$/', $locale) || !str_starts_with($locale, $code)) {
            return t('ai_cloud.err_lang_locale');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 40 || preg_match('/[\x00-\x1f\x7f<>"]/', $name)) {
            return t('ai_cloud.err_lang_name');
        }
        return null;
    }

    public static function addLanguage(array $post): string
    {
        $code = strtolower(trim((string) ($post['lang_code'] ?? '')));
        $locale = trim((string) ($post['lang_locale'] ?? ''));
        $locale = $locale !== '' ? $locale : $code;
        $name = trim((string) ($post['lang_name'] ?? ''));
        $err = self::validLanguage($code, $locale, $name);
        if ($err !== null) {
            throw new CloudAiException($err);
        }
        $all = self::languages();
        if (isset($all[$code])) {
            throw new CloudAiException(t('ai_cloud.err_lang_exists'));
        }
        if (count($all) >= self::MAX_LANGUAGES) {
            throw new CloudAiException(sprintf(t('ai_cloud.err_lang_max'), self::MAX_LANGUAGES));
        }
        $all[$code] = ['locale' => $locale, 'name' => $name];
        self::storeLanguages($all);
        writeAuditLog('ai_cloud', 'language', 0, "AI language {$code} ({$name}) added", 'create', $_SESSION['user_id'] ?? null);
        return $code;
    }

    public static function deleteLanguage(string $code): void
    {
        $all = self::languages();
        if (!isset($all[$code])) {
            return;
        }
        if (count($all) === 1) {
            throw new CloudAiException(t('ai_cloud.err_lang_last'));
        }
        $used = self::languageUsedBy($code);
        if ($used !== []) {
            throw new CloudAiException(sprintf(t('ai_cloud.err_lang_used'), implode(', ', $used)));
        }
        unset($all[$code]);
        self::storeLanguages($all);
        $st = getDB()->prepare('DELETE FROM sys_settings WHERE setting_key = ?');
        foreach (['tts', 'stt', 'llm'] as $job) {
            $st->execute(["ai_engine.{$job}.{$code}"]);
        }
        writeAuditLog('ai_cloud', 'language', 0, "AI language {$code} deleted", 'delete', $_SESSION['user_id'] ?? null);
    }

    /**
     * AI applications that speak or listen in $code (their titles).
     * @return list<string>
     */
    public static function languageUsedBy(string $code): array
    {
        $out = [];
        try {
            foreach (getDB()->query('SELECT title, model_id, config FROM pbx_ai_apps')->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $c = json_decode((string) ($a['config'] ?? ''), true);
                if ((is_array($c) && ($c['stt_lang'] ?? '') === $code) || ($a['model_id'] ?? '') === 'engine:' . $code
                    || (is_array($c) && ($c['stt_model'] ?? '') === 'engine:' . $code)) {
                    $out[] = (string) $a['title'];
                }
            }
        } catch (\Throwable $e) {
            // AI applications not installed: nothing uses the language.
        }
        return $out;
    }

    private static function storeLanguages(array $all): void
    {
        getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute(['ai_languages', (string) json_encode($all, JSON_UNESCAPED_UNICODE)]);
    }

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
        $type = self::typeOf($id);
        // The AI service picks the endpoint by provider: it gets the type, not the account.
        $out = ['provider' => $type, 'api_key' => (string) ($cfg['api_key'] ?? '')];
        // Only these accounts' "Model" field names a language model; OpenAI's
        // and ElevenLabs' name their speech model (Cloud TTS).
        if (!in_array($type, self::LLM_FIELDS_OWN, true)) {
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
                         'fields' => [$key[0], $model('claude-sonnet-4-20250514')],
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
        foreach (self::REALTIME_MODELS as $type => $modelName) {
            $providers[$type]['fields'][] = [
                'key' => 'realtime_model', 'label' => t('ai_cloud.realtime_model'),
                'secret' => false, 'optional' => true, 'placeholder' => $modelName,
            ];
        }
        $providers['openai_compat']['fields'][] = [
            'key' => 'realtime_protocol', 'label' => t('ai_cloud.realtime_protocol'),
            'secret' => false, 'optional' => true,
            'options' => [
                '' => t('ai_cloud.realtime_automatic'),
                'openai_ga' => 'OpenAI Realtime GA',
                'openai_beta' => 'OpenAI Realtime Beta',
            ],
        ];
        $providers['openai_compat']['fields'][] = [
            'key' => 'realtime_ws_url', 'label' => t('ai_cloud.realtime_ws_url'),
            'secret' => false, 'optional' => true, 'placeholder' => 'wss://example.com/v1/realtime',
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
    private static function knownAccount(string $id): bool
    {
        return isset(self::accounts()[$id]) || (isset(self::providers()[$id]) && empty(self::registry()[$id]['removed']));
    }

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

    public static function configured(string $id, array $post = []): bool
    {
        if (function_exists('getSystemSetting') && getSystemSetting('privacy_block_cloud_ai', '0') === '1') {
            return false;
        }
        $a = self::registry()[$id] ?? null;
        if ($a !== null && ($a['removed'] || !$a['enabled'])) {
            return false;
        }
        return self::fieldsComplete($id, $post);
    }

    /** Whether the account's required fields are filled (stored, or typed in $post). */
    private static function fieldsComplete(string $id, array $post = []): bool
    {
        $cfg = self::config($id);
        $fields = self::provider($id)['fields'];
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
                      'fields' => array_map(fn($f) => $f + ['value' => $f['secret'] ? '' : (string) ($f['default'] ?? ''), 'masked' => ''], $p['fields'])];
        }
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
            if ($v !== '' && in_array($field, ['model', 'realtime_model'], true) && !preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,99}$#D', $v)) {
                throw new CloudAiException(t('ai_cloud.err_model'));
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
    public static function test(string $id, array $post = []): string
    {
        $cfg = self::config($id);
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
                return self::testChat($id, $post);
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
    private static function testChat(string $id, array $post = []): string
    {
        require_once __DIR__ . '/AiGateway.php';
        $started = microtime(true);
        AiGateway::chat('cloud:' . $id, 'Reply with OK.', 'panel', 300, $post);
        $cfg = self::gatewayConfig($id, $post);
        return sprintf(t('ai_cloud.test_chat'), $cfg['model'], (int) round((microtime(true) - $started) * 1000));
    }

    /**
     * Discovers available models for a configured LLM/embedding provider, cached for 15 minutes.
     * @return array{models: list<string>, cached: bool}
     */
    public static function discoverModels(string $id, array $post = [], bool $refresh = false): array
    {
        self::provider($id);
        $cacheKey = 'ai_cloud_cache.' . $id;
        [$base, , $key] = self::llmTarget($id, $post);
        $fingerprint = hash('sha256', $id . '|' . $base);
        if (!$refresh && function_exists('getSystemSetting')) {
            $raw = json_decode((string) getSystemSetting($cacheKey, ''), true);
            if (is_array($raw) && ($raw['fp'] ?? '') === $fingerprint && (int) ($raw['exp'] ?? 0) > time() && is_array($raw['models'] ?? null)) {
                return ['models' => array_values($raw['models']), 'cached' => true];
            }
        }

        $models = [];
        $type = self::typeOf($id);
        if ($type === 'google_ai') {
            $k = trim((string) ($post['api_key'] ?? '')) ?: (self::config($id)['api_key'] ?? '');
            $d = self::json(TtsHttp::request('GET', 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=50', ['x-goog-api-key' => $k], null, 20), $id);
            foreach ((array) ($d['models'] ?? []) as $m) {
                $name = (string) ($m['name'] ?? '');
                if ($name !== '') {
                    $models[] = str_starts_with($name, 'models/') ? substr($name, 7) : $name;
                }
            }
        } elseif ($type === 'claude') {
            $d = self::json(TtsHttp::request('GET', $base . '/models', ['x-api-key' => $key, 'anthropic-version' => '2023-06-01'], null, 20), $id);
            foreach ((array) ($d['data'] ?? []) as $m) {
                if (!empty($m['id'])) {
                    $models[] = (string) $m['id'];
                }
            }
        } else {
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
        if (function_exists('setSystemSetting')) {
            setSystemSetting($cacheKey, json_encode(['fp' => $fingerprint, 'exp' => time() + 900, 'models' => $models], JSON_UNESCAPED_UNICODE));
        }
        return ['models' => $models, 'cached' => false];
    }

    /** Credential resolver bridge; never expose this array to browser/worker job JSON. */
    public static function gatewayConfig(string $id, array $post = []): array
    {
        $type = self::typeOf($id);
        if ($type === 'google_ai') {
            $cfg = self::config($id);
            if (trim((string) ($post['api_key'] ?? '')) !== '') { $cfg['api_key'] = trim((string) $post['api_key']); }
            return ['adapter' => 'google', 'model' => self::GOOGLE_AI_MODEL,
                'url' => self::GOOGLE_AI_GENERATE_URL,
                'api_key' => $cfg['api_key'] ?? ''];
        }
        [$base, $model, $key] = self::llmTarget($id, $post);
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
        $started = microtime(true);
        $type = self::typeOf($id);
        switch ($type) {
            case 'openai':
            case 'groq':
                $url = $type === 'openai' ? 'https://api.openai.com/v1/audio/transcriptions' : 'https://api.groq.com/openai/v1/audio/transcriptions';
                $model = $type === 'openai' ? 'gpt-4o-mini-transcribe' : 'whisper-large-v3-turbo';
                [$body, $type] = self::multipart(['model' => $model, 'language' => $lang, 'response_format' => 'json'], 'file', 'speech.wav', $wav);
                $d = self::json(TtsHttp::request('POST', $url, ['Authorization' => 'Bearer ' . $k, 'Content-Type' => $type], $body, 120), $id);
                $text = (string) ($d['text'] ?? '');
                break;
            case 'openai_compat':
                $base = rtrim((string) ($cfg['base_url'] ?? ''), '/');
                $model = trim((string) ($cfg['stt_model'] ?? '')) ?: (trim((string) ($cfg['model'] ?? '')) ?: 'whisper-1');
                [$body, $type] = self::multipart(['model' => $model, 'language' => $lang, 'response_format' => 'json'], 'file', 'speech.wav', $wav);
                $headers = ['Content-Type' => $type];
                if ($k !== '') {
                    $headers['Authorization'] = 'Bearer ' . $k;
                }
                $d = self::json(TtsHttp::request('POST', $base . '/audio/transcriptions', $headers, $body, 120), $id);
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
                $d = self::json(TtsHttp::request('POST', "https://{$region}.stt.speech.microsoft.com/speech/recognition/conversation/cognitiveservices/v1?format=simple&language=" . rawurlencode(self::languageLocale($lang)),
                    ['Ocp-Apim-Subscription-Key' => $k, 'Content-Type' => 'audio/wav; codecs=audio/pcm; samplerate=16000'], $wav, 120), $id);
                $text = (string) ($d['DisplayText'] ?? '');
                break;
            case 'elevenlabs':
                [$body, $type] = self::multipart(['model_id' => 'scribe_v1', 'language_code' => $lang], 'file', 'speech.wav', $wav);
                $d = self::json(TtsHttp::request('POST', 'https://api.elevenlabs.io/v1/speech-to-text', ['xi-api-key' => $k, 'Content-Type' => $type], $body, 120), $id);
                $text = (string) ($d['text'] ?? '');
                break;
            case 'google_ai':
                $prompt = 'Transcribe this telephone audio word for word in ' . self::languageName($lang)
                    . '. Output only the transcript, nothing else. If nothing is said, output nothing.';
                $req = ['contents' => [['parts' => [['text' => $prompt], ['inline_data' => ['mime_type' => 'audio/wav', 'data' => base64_encode($wav)]]]]],
                        'generationConfig' => ['temperature' => 0]];
                $d = self::json(TtsHttp::request('POST', self::GOOGLE_AI_GENERATE_URL,
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
    private static function llmTarget(string $id, array $post = []): array
    {
        $s = self::llmSettings($id);
        $fixed = ['openai' => ['https://api.openai.com/v1', 'gpt-4.1-mini'],
                  'groq' => ['https://api.groq.com/openai/v1', 'llama-3.3-70b-versatile'],
                  'openrouter' => ['https://openrouter.ai/api/v1', 'google/gemini-2.5-flash'],
                  'claude' => ['https://api.anthropic.com/v1', 'claude-sonnet-4-20250514'],
                  'mistral' => ['https://api.mistral.ai/v1', 'mistral-small-latest'],
                  'cohere' => ['https://api.cohere.com/compatibility/v1', 'command-a-03-2025'],
                  'nvidia' => ['https://integrate.api.nvidia.com/v1', self::LLM_DEFAULTS['nvidia']],
                  'qwen' => [self::QWEN_ENDPOINT, self::LLM_DEFAULTS['qwen']]];
        [$base, $model] = $fixed[self::typeOf($id)] ?? ['', ''];
        $base = $s['base_url'] ?? $base;
        $model = $s['model'] ?? $model;
        $key = $s['api_key'];
        // Form fields typed but not yet saved win over the stored account,
        // validated exactly as Save validates them.
        $typedBase = trim((string) ($post['base_url'] ?? ''));
        if ($typedBase !== '') {
            $base = self::validBaseUrl($typedBase) ?? throw new CloudAiException(t('ai_cloud.err_base_url'));
        }
        $typedModel = trim((string) ($post['model'] ?? ''));
        if ($typedModel !== '') {
            if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,99}$#', $typedModel)) {
                throw new CloudAiException(t('ai_cloud.err_model'));
            }
            $model = $typedModel;
        }
        $typedKey = trim((string) ($post['api_key'] ?? ''));
        if ($typedKey !== '') {
            $key = $typedKey;
        }
        return [$base, $model, $key];
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
        if (self::overLimit($id, 'llm')) {
            throw new CloudAiException(t('ai_cloud.err_quota_exceeded'));
        }
        $type = self::typeOf($id);
        if ($type === 'google_ai') {
            $d = self::json(TtsHttp::request('POST', self::GOOGLE_AI_GENERATE_URL,
                ['x-goog-api-key' => self::config($id)['api_key'] ?? '', 'Content-Type' => 'application/json'],
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
                'model' => $model,
                'max_tokens' => $maxTokens,
                'temperature' => 0,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]), $timeout), $id);
            $usage = (float) (($d['usage']['input_tokens'] ?? 0) + ($d['usage']['output_tokens'] ?? 0));
            self::count($id, 'llm_tokens', $usage);
            return trim((string) ($d['content'][0]['text'] ?? ''));
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

    public static function localFallback(string $job, string $lang): string
    {
        if (!in_array($job, ['tts', 'stt'], true)) {
            return '';
        }
        $lang = strtolower(str_replace('_', '-', $lang));
        $matches = static function (array $m) use ($lang): bool {
            foreach ((array) ($m['languages'] ?? []) as $ml) {
                if (str_starts_with(strtolower(str_replace('_', '-', (string) $ml)), $lang)) {
                    return true;
                }
            }
            return false;
        };
        $models = LocalAiService::models();
        foreach (['ready', 'installed'] as $state) {
            foreach ($models as $m) {
                if (($m['kind'] ?? '') === $job && ($m['state'] ?? '') === $state && $matches($m)) {
                    return 'local:' . $m['id'];
                }
            }
        }
        return '';
    }

    // ------------------------------------------------------------ engine per job

    /** Jobs with a choice of engine: speech and speech to text per language. */
    public static function jobs(): array
    {
        $out = [];
        foreach (array_keys(self::languages()) as $lang) {
            $out[] = ['job' => 'tts', 'lang' => $lang];
            $out[] = ['job' => 'stt', 'lang' => $lang];
            $out[] = ['job' => 'llm', 'lang' => $lang];
        }
        return $out;
    }

    /** Features that may use their own engine instead of the one of the language: feature => job. */
    public const FEATURES = ['vm_stt' => 'stt', 'vm_llm' => 'llm', 'cdr_stt' => 'stt', 'cdr_llm' => 'llm', 'qa_llm' => 'llm'];

    /** The feature's own engine ("local:…" / "cloud:…"), '' = the engine of the language. */
    public static function featureEngine(string $feature): string
    {
        return isset(self::FEATURES[$feature]) ? (string) getSystemSetting("ai_use.{$feature}", '') : '';
    }

    /**
     * Engine a feature uses: its own choice, else the engine of the job and
     * language. A cloud account over its limit falls back to a local model.
     */
    public static function engineFor(string $feature, string $lang): string
    {
        $job = self::FEATURES[$feature] ?? throw new CloudAiException('Unknown feature.');
        $v = self::featureEngine($feature);
        if ($v === '' || (str_starts_with($v, 'cloud:') && !self::configured(substr($v, 6)))) {
            return self::engine($job, $lang);
        }
        if (str_starts_with($v, 'cloud:') && self::overLimit(substr($v, 6), $job)) {
            return self::localFallback($job, $lang);
        }
        return $v;
    }

    /** Saves the engine chosen for each feature in $post (use_<feature>); '' = by language. */
    public static function saveFeatureEngines(array $post): void
    {
        $set = getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach (self::FEATURES as $feature => $job) {
            if (!array_key_exists("use_{$feature}", $post)) {
                continue;
            }
            $v = (string) $post["use_{$feature}"];
            if ($v !== '' && !self::validEngineValue($v, $job)) {
                throw new CloudAiException(t('ai_cloud.err_engine'));
            }
            $set->execute(["ai_use.{$feature}", $v]);
        }
    }

    /** "local:<model id>" (speech jobs only) or "cloud:<provider that can do $job>". */
    private static function validEngineValue(string $v, string $job): bool
    {
        if (preg_match('/^cloud:([a-z_]{2,20})$/', $v, $m)) {
            return self::knownAccount($m[1]) && in_array($job, self::provider($m[1])['caps'], true);
        }
        return $job !== 'llm' && (bool) preg_match('/^local:[a-z0-9][a-z0-9._-]{1,63}$/', $v);
    }

    public static function engine(string $job, string $lang, bool $fallback = true): string
    {
        $v = (string) getSystemSetting("ai_engine.{$job}.{$lang}", '');
        if ($fallback && str_starts_with($v, 'cloud:') && self::overLimit(substr($v, 6), $job)) {
            return self::localFallback($job, $lang);
        }
        return $v;
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
            if (str_starts_with($v, 'cloud:') && !self::knownAccount(substr($v, 6))) {
                continue;
            }
            $set->execute(["ai_engine.{$j['job']}.{$j['lang']}", $v]);
        }
        writeAuditLog('ai_cloud', 'engines', 0, 'AI engines per job', 'update', $_SESSION['user_id'] ?? null);
    }
}
