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
require_once __DIR__ . '/CloudAiException.php';
require_once __DIR__ . '/AiProviderManifest.php';
require_once __DIR__ . '/AiEndpointGuard.php';
require_once __DIR__ . '/AiUsageMeter.php';
require_once __DIR__ . '/AiProviderCatalog.php';
require_once __DIR__ . '/AiAccountStore.php';
require_once __DIR__ . '/AiProviderClient.php';

class CloudAiService
{
    public const CAPS = AiProviderCatalog::CAPS;
    public const LLM_FIELDS_OWN = AiProviderCatalog::LLM_FIELDS_OWN;
    public const MAX_ACCOUNTS = AiAccountStore::MAX_ACCOUNTS;

    /**
     * The languages AI jobs run in (AiLanguages::TABLE): code => locale and name. The name is what language models are
     * told to speak or write, the locale goes to speech recognition and synthesis.
     * @return array<string, array{locale: string, name: string}>
     */
    public static function languages(): array
    {
        return AiLanguages::languages();
    }

    /** The language of a code ("tr", "tr_TR", "pt-BR", an alias) when the table has it; else the PBX's own language, else English. */
    public static function language(string $lang): string
    {
        return AiLanguages::resolve($lang);
    }

    public static function languageName(string $lang): string
    {
        return AiLanguages::nameOf($lang);
    }

    public static function languageLocale(string $lang): string
    {
        return AiLanguages::localeOf($lang);
    }

    /**
     * Address of an OpenAI-compatible chat API, or null: http(s), no
     * credentials or fragments in it, at most 200 characters.
     */
    public static function validBaseUrl(string $url, bool $allowPrivate = true): ?string
    {
        return AiEndpointGuard::validBaseUrl($url, $allowPrivate);
    }

    /** Adds to this month's use of a provider (seconds of audio, characters, tokens). */
    public static function count(string $provider, string $unit, float $amount): void
    {
        AiUsageMeter::count($provider, $unit, $amount);
    }

    /** @return array<string, array{stt_seconds: float, tts_chars: int, llm_tokens: int}> */
    public static function usageThisMonth(): array
    {
        return AiUsageMeter::usageThisMonth();
    }

    /**
     * Monthly quota limits configured for a provider (0 = unlimited).
     * @return array{stt_min: int, tts_chars: int, llm_tokens: int}
     */
    public static function limits(string $id): array
    {
        return AiUsageMeter::limits($id);
    }

    /**
     * Computes this month's quota percentage and warning/exceeded flags.
     * @param array{stt_seconds?: float|int, tts_chars?: int, llm_tokens?: int}|null $usageRow
     * @return array{pct: int, warn: bool, exceeded: bool, jobs: array{stt: bool, tts: bool, llm: bool}}
     */
    public static function usageStatus(string $id, ?array $usageRow = null): array
    {
        return AiUsageMeter::usageStatus($id, $usageRow);
    }

    public static function overLimit(string $id, string $job = ''): bool
    {
        return AiUsageMeter::overLimit($id, $job);
    }

    /**
     * The providers an account can be added for (the Add dialog): fields with their defaults.
     * @return list<array<string, mixed>>
     */
    public static function typesForPage(): array
    {
        return AiProviderCatalog::typesForPage();
    }

    /** typeOf() for a reference that may be stale: '' instead of an error. */
    public static function typeIfKnown(string $id): string
    {
        return AiAccountStore::typeIfKnown($id);
    }

    /** The provider type of an account ("openai" for "openai_b"). */
    public static function typeOf(string $id): string
    {
        return AiAccountStore::typeOf($id);
    }

    /**
     * Every account the page lists: the list's entries, and each type's own account when
     * it has settings (stored before accounts existed) or works without any (local Ollama).
     * @return array<string, array{type: string, name: string, enabled: bool}>
     */
    public static function accounts(): array
    {
        return AiAccountStore::accounts();
    }

    /** What an account is called on pages: its own name, else its provider's title. */
    public static function label(string $id): string
    {
        return AiAccountStore::label($id);
    }

    /** A new account of $type; its settings are saved like any account's. Returns its id. */
    public static function addAccount(string $type, string $name, array $post): string
    {
        return AiAccountStore::addAccount($type, $name, $post);
    }

    /** A new account with the same provider, key and settings under another name ("Copy account": a second model of one provider). */
    public static function copyAccount(string $id): string
    {
        return AiAccountStore::copyAccount($id);
    }

    public static function renameAccount(string $id, string $name): void
    {
        AiAccountStore::renameAccount($id, $name);
    }

    /** A switched-off account keeps its settings but no job uses it. */
    public static function setEnabled(string $id, bool $enabled): void
    {
        AiAccountStore::setEnabled($id, $enabled);
    }

    /** Removes an account with its settings, limits and model list. */
    public static function deleteAccount(string $id): void
    {
        AiAccountStore::deleteAccount($id);
    }

    /**
     * The provider of an account: title, capabilities, fields, sign-up page ('title' is
     * the account's label, 'type' its provider).
     */
    public static function provider(string $id): array
    {
        return AiAccountStore::provider($id);
    }

    /** Decrypted settings of a provider (server side only). */
    public static function config(string $id): array
    {
        return AiAccountStore::config($id);
    }

    public static function configured(string $id, array $post = []): bool
    {
        return AiAccountStore::configured($id, $post);
    }

    /** Completeness check for interactive Test / Discover (allows disabled accounts and fresh 2nd accounts). */
    public static function configuredForTest(string $id, array $post = [], bool $isNew = false): bool
    {
        return AiAccountStore::configuredForTest($id, $post, $isNew);
    }

    /** @return list<string> configured, switched-on accounts that can do $cap */
    public static function withCap(string $cap): array
    {
        return AiAccountStore::withCap($cap);
    }

    /**
     * The page's view of every account (the table): secrets only masked.
     * 'configured' is false for a switched-off account; 'complete' says whether its fields are filled.
     * @return list<array<string, mixed>>
     */
    public static function forPage(): array
    {
        return AiAccountStore::forPage();
    }

    public static function save(string $id, array $post): void
    {
        AiAccountStore::save($id, $post);
    }

    /**
     * What the AI service needs to ask a provider's language model:
     * provider, key and, where the account has them, model and address.
     */
    public static function llmSettings(string $id): array
    {
        return AiAccountStore::llmSettings($id);
    }

    /**
     * Text of a WAV file with a cloud provider.
     * @return array{text: string, ms: int}
     */
    public static function stt(string $id, string $wav, string $lang): array
    {
        return AiProviderClient::stt($id, $wav, $lang);
    }

    /**
     * One answer of a cloud language model (temperature 0, except Claude). Google AI uses
     * Gemini's own API, every other provider the OpenAI chat API.
     */
    public static function chat(string $id, string $prompt, int $maxTokens = 300, int $timeout = 30): string
    {
        return AiProviderClient::chat($id, $prompt, $maxTokens, $timeout);
    }

    /** Credential resolver bridge; never expose this array to browser/worker job JSON. */
    public static function gatewayConfig(string $id, array $post = []): array
    {
        return AiProviderClient::gatewayConfig($id, $post);
    }

    /**
     * Discovers available models for a configured LLM/embedding provider, cached for 15 minutes.
     * `kinds`: what each model is for (llm, stt, tts, realtime, embed, other), so that the account form offers
     * each model field only the models that fit it.
     * @return array{models: list<string>, kinds: array<string, string>, cached: bool}
     */
    public static function discoverModels(string $id, array $post = [], bool $refresh = false, bool $isNew = false): array
    {
        return AiProviderClient::discoverModels($id, $post, $refresh, $isNew);
    }

    public static function wavSeconds(string $wav): float
    {
        return AiProviderClient::wavSeconds($wav);
    }
}
