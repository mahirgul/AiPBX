<?php
/**
 * What the portal knows about each AI provider that the AI service must know the same way: the chat address, the
 * default models, which providers take an address of their own, may be on the local network or need no key.
 *
 * The facts live in ai/aipbx_ai/providers.json, which the AI service reads too (ai/aipbx_ai/providers.py). This class
 * only reads that file, so a provider is changed in one place. It depends on nothing else in the portal: the account
 * code (CloudAiService and the classes under it) calls it, never the other way round.
 */
final class AiProviderManifest
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $providers = null;

    public static function path(): string
    {
        return dirname(__DIR__, 4) . '/ai/aipbx_ai/providers.json';
    }

    /** @return array<string, array<string, mixed>> provider type => its entry */
    public static function all(): array
    {
        if (self::$providers === null) {
            $json = is_file(self::path()) ? json_decode((string) file_get_contents(self::path()), true) : null;
            if (!is_array($json) || !is_array($json['providers'] ?? null)) {
                throw new RuntimeException('The provider list ' . self::path() . ' is missing or not valid JSON.');
            }
            self::$providers = $json['providers'];
        }
        return self::$providers;
    }

    /** @return array<string, mixed> */
    public static function provider(string $type): array
    {
        return self::all()[$type] ?? [];
    }

    /** The language model the provider uses when an account names none ('' when there is none). */
    public static function llmModel(string $type): string
    {
        return (string) (((array) (self::provider($type)['llm'] ?? []))['model'] ?? '');
    }

    /**
     * The address of the provider's chat API without the path (…/v1), or '' for a provider that has none a form
     * could show (Google AI has its own request). The address of one the administrator names is what its form
     * starts with.
     */
    public static function llmBase(string $type): string
    {
        $llm = (array) (self::provider($type)['llm'] ?? []);
        if (!empty($llm['default_base'])) {
            return (string) $llm['default_base'];
        }
        $endpoint = (string) ($llm['endpoint'] ?? '');
        return ($endpoint === '' || str_contains($endpoint, '{')) ? '' : (string) preg_replace('#/(chat/completions|messages)$#', '', $endpoint);
    }

    /** The chat request address of a provider whose address holds the model (Google AI: …/models/{model}:generateContent). */
    public static function llmUrl(string $type, string $model): string
    {
        return str_replace('{model}', rawurlencode($model), (string) (((array) (self::provider($type)['llm'] ?? []))['endpoint'] ?? ''));
    }

    /** The speech-to-text model used when an account names none. */
    public static function sttModel(string $type): string
    {
        return (string) (((array) (self::provider($type)['stt'] ?? []))['model'] ?? '');
    }

    /** @return array<string, string> provider type => default model, for the providers with a Realtime API */
    public static function realtimeModels(): array
    {
        $out = [];
        foreach (self::all() as $type => $p) {
            if (isset($p['realtime'])) {
                $out[$type] = (string) ($p['realtime']['model'] ?? '');
            }
        }
        return $out;
    }

    /** Does the provider take an address of its own (the administrator's server, Qwen's region)? */
    public static function ownAddress(string $type): bool
    {
        return !empty(self::provider($type)['own_address']);
    }

    /** May the provider's address be on the local network (a server of the administrator's own)? */
    public static function privateOk(string $type): bool
    {
        return !empty(self::provider($type)['private_ok']);
    }

    /** Is it a server that normally runs on this machine (Ollama, a local NIM container)? */
    public static function localCapable(string $type): bool
    {
        return !empty(self::provider($type)['local_capable']);
    }

    /** Does it work without an API key? */
    public static function keyless(string $type): bool
    {
        return !empty(self::provider($type)['keyless']);
    }

    /**
     * The models an account form offers before the provider's own list is loaded, by what they are for.
     * @return array<string, list<string>>
     */
    public static function knownModels(string $type): array
    {
        return (array) (self::provider($type)['models'] ?? []);
    }
}
