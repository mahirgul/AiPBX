<?php

declare(strict_types=1);

require_once __DIR__ . '/AiAppService.php';
require_once __DIR__ . '/AiKnowledgeService.php';
require_once __DIR__ . '/OllamaService.php';
require_once __DIR__ . '/PbxToolRegistry.php';

use PBX\Destinations\DestinationRegistry;

final class BuiltinAiAgentService
{
    public const TOOL_FILE = AiKnowledgeService::AGENT_TOOLS_FILE;
    public const TARGET_TYPES = ['extension', 'queue', 'ring_group'];
    public const RETRIES_DEFAULT = 2;
    public const LINE_PROFILES = ['normal', 'noisy', 'faint'];
    public const REALTIME_VOICES = ['alloy', 'ash', 'ballad', 'coral', 'echo', 'sage', 'shimmer', 'verse'];
    public const REALTIME_PROVIDER_TYPES = ['openai', 'google_ai', 'qwen', 'openai_compat'];
    public const INSTRUCTION_VARS = ['{caller_name}', '{caller_id}', '{customer_id}', '{did}', '{date}', '{time}', '{language}'];

    public static function defaultInstructionsTemplate(): string
    {
        return "# Role\n"
            . "- You serve as the AI voice assistant operating the virtual front desk.\n"
            . "- Greet callers politely, understand their intent, answer from the knowledge base or database tools, and assist or route them.\n"
            . "- Communicate clearly and concisely in a natural telephone tone.\n\n"
            . "# Caller Information\n"
            . "- Caller Name: {caller_name}\n"
            . "- Caller Phone: {caller_id}\n"
            . "- Customer ID: {customer_id}\n"
            . "- Dialed Number (DID): {did}\n"
            . "- Date / Time: {date} {time}\n"
            . "- Language: {language}\n\n"
            . "# Tool & Routing Guidelines\n"
            . "- Use `get_db_value` when the caller asks about their invoice, order, or technical service status.\n"
            . "- Use `collect_dtmf` or ask the caller to dial digits when a numerical ID is required, and `send_dtmf` when pressing keys on a channel.\n"
            . "- When asked to reach an external company representative or sub-extension, call `find_person` and then `dial_and_navigate`.";
    }

    /** @return array<string, string> */
    public static function realtimeAccounts(): array
    {
        if (!AiAppService::isScopeEnabled('cloud')) {
            return [];
        }
        $out = [];
        foreach (CloudAiService::withCap('llm') as $provider) {
            if (in_array(CloudAiService::typeOf($provider), self::REALTIME_PROVIDER_TYPES, true)) {
                $out['cloud:' . $provider] = CloudAiService::provider($provider)['title'];
            }
        }
        return $out;
    }

    public static function models(): array
    {
        $options = ['llm' => [], 'stt' => [], 'tts' => []];
        if (PluginManager::isEnabled('ailocal')) {
            foreach (array_merge(LocalAiService::models(), OllamaService::chatModels()) as $model) {
                $kind = $model['kind'] ?? '';
                if (isset($options[$kind]) && in_array($model['state'] ?? '', ['ready', 'installed'], true)) {
                    $options[$kind]['local:' . $model['id']] = $model['title'];
                }
            }
        }
        if (AiAppService::isScopeEnabled('cloud')) {
            foreach (array_keys($options) as $kind) {
                $providers = $kind === 'tts' ? AiAppService::cloudTtsAccounts() : CloudAiService::withCap($kind);
                foreach ($providers as $provider) {
                    $options[$kind]['cloud:' . $provider] = CloudAiService::provider($provider)['title'];
                }
            }
        }
        return $options;
    }

    public static function targets(bool $fallback = false): array
    {
        $targets = $fallback ? ['hangup' => ['' => t('dest.hangup')]] : [];
        $types = $fallback ? array_keys(DestinationRegistry::getModules()) : self::TARGET_TYPES;
        foreach ($types as $type) {
            $targets[$type] = ($targets[$type] ?? []) + array_column(DestinationRegistry::getOptionsFor($type), 'name', 'id');
        }
        return $targets;
    }

    public static function validate(array $data): array
    {
        $models = self::models();
        foreach (['understand' => 'llm', 'stt_model' => 'stt', 'model_id' => 'tts'] as $key => $kind) {
            if (!isset($models[$kind][(string) ($data[$key] ?? '')])) {
                throw new InvalidArgumentException(t('builtin_ai_agent.err_model'));
            }
        }
        [$type, $target] = array_pad(explode(':', (string) ($data['fallback'] ?? ''), 2), 2, '');
        if (!isset(self::targets(true)[$type][$target])) {
            throw new InvalidArgumentException(t('ai_apps.err_dest'));
        }
        $collectionId = (int) ($data['knowledge_collection'] ?? 0);
        $collection = PluginManager::isEnabled('ai-knowledge') && $collectionId > 0 ? AiKnowledgeService::collection($collectionId) : null;
        if (!$collection) {
            throw new InvalidArgumentException(t('ai_apps.err_knowledge'));
        }
        self::toolsForCollection($collectionId);
        $lang = CloudAiService::language((string) ($collection['assistant_language'] ?? getUserLanguage()));
        $greeting = langStrings($lang)['ai_apps.default_agent_greeting'] ?? langStrings('en')['ai_apps.default_agent_greeting'];
        $greeting = preg_replace('/^([^,.!?]{1,16}),/u', '$1 {caller_name},', $greeting) ?? $greeting;
        $realtimeAccount = trim((string) ($data['realtime_account'] ?? ''));
        if ($realtimeAccount !== '' && !isset(self::realtimeAccounts()[$realtimeAccount])) {
            throw new InvalidArgumentException(t('builtin_ai_agent.err_model'));
        }
        $realtimeVoice = trim((string) ($data['realtime_voice'] ?? 'alloy'));
        if (!in_array($realtimeVoice, self::REALTIME_VOICES, true)) {
            $realtimeVoice = 'alloy';
        }
        $validToolNames = array_column(PbxToolRegistry::all(), 'name');
        $pbxTools = array_values(array_intersect($validToolNames, array_map('strval', (array) ($data['pbx_tools'] ?? []))));
        $instructions = trim((string) ($data['instructions'] ?? ''));
        if (mb_strlen($instructions) > 4000) {
            throw new InvalidArgumentException(sprintf(t('ai_apps.err_text'), 4000));
        }
        $dbLookups = [];
        $seenQueries = [];
        foreach ((array) ($data['db_queries'] ?? []) as $qKey) {
            $qKey = trim((string) $qKey);
            if ($qKey === '' || isset($seenQueries[$qKey])) {
                continue;
            }
            $qRow = ExternalDbQueryService::find($qKey);
            if (!$qRow || !ExternalDbQueryService::available($qRow) || count($dbLookups) >= AiAppDbLookup::MAX_QUERIES) {
                throw new InvalidArgumentException(t('builtin_ai_agent.err_tools'));
            }
            $seenQueries[$qKey] = true;
            $dbLookups[] = ['id' => $qKey, 'saved' => true];
        }
        return [
            'id' => (int) ($data['id'] ?? 0), 'title' => (string) ($data['title'] ?? ''),
            'internal_number' => (string) ($data['internal_number'] ?? ''), 'app_type' => AiAppService::BUILTIN_AGENT,
            'dest_type' => $type, 'dest_id' => $target, 'is_active' => 1,
            'model_id' => (string) $data['model_id'], 'text_template' => $greeting,
            'agent_config' => [
                'conversation' => true, 'barge_in' => !empty($data['barge_in']),
                'retry' => langStrings($lang)['ai_apps.default_agent_retry'] ?? langStrings('en')['ai_apps.default_agent_retry'],
            ] + self::listening($data) + [
                'automatic_announcements' => !empty($data['automatic_announcements']),
                'max_turns' => 12, 'greeting' => $greeting, 'stt_lang' => $lang, 'stt_model' => (string) $data['stt_model'],
                'understand' => (string) $data['understand'], 'knowledge_collection' => $collectionId,
                'realtime_account' => $realtimeAccount, 'realtime_voice' => $realtimeVoice,
                'pbx_tools' => $pbxTools,
                'instructions' => $instructions,
                'db_queries' => array_keys($seenQueries),
                'db_lookups' => $dbLookups,
            ],
        ];
    }

    /**
     * Advanced listening settings, each clamped to what the gateway accepts. An agent saved before
     * they existed has no `silence_seconds` and gets these defaults (it was saved with retries 0).
     */
    public static function listening(array $data): array
    {
        $int = static fn (string $key, int $lo, int $hi, int $default): int =>
            isset($data[$key]) && is_numeric($data[$key]) ? max($lo, min($hi, (int) $data[$key])) : $default;
        $legacy = !isset($data['silence_seconds']);
        return [
            'retries' => $legacy ? self::RETRIES_DEFAULT : $int('retries', 0, 2, self::RETRIES_DEFAULT),
            'silence_seconds' => $int('silence_seconds', 3, 10, 4),
            'listen_seconds' => $int('listen_seconds', 5, 15, 10),
            'line_profile' => in_array($data['line_profile'] ?? '', self::LINE_PROFILES, true) ? (string) $data['line_profile'] : 'normal',
        ];
    }

    /**
     * The selected collection can publish one active agent-tools.txt document.
     */
    public static function toolsForCollection(int $collectionId): array
    {
        if ($collectionId < 1) {
            return [];
        }
        $stmt = getDB()->prepare('SELECT content_text FROM ai_knowledge_docs WHERE collection_id = ? AND is_active = 1 AND filename = ? LIMIT 2');
        $stmt->execute([$collectionId, self::TOOL_FILE]);
        $documents = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($documents) > 1) {
            throw new InvalidArgumentException(t('builtin_ai_agent.err_tools'));
        }
        if (!$documents) {
            return [];
        }
        return self::parseTools($documents[0]);
    }

    public static function parseTools(string $text): array
    {
        $targets = null;
        $tools = [];
        $http = [];
        $db = [];
        $connections = [];
        $voice = [];
        $greetings = [];
        $waiting = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$tool, $definition] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            if ($tool === 'tone' && $definition !== '' && mb_strlen($definition) <= 300 && !isset($voice['announcement_tone'])) {
                $voice['announcement_tone'] = $definition;
                continue;
            }
            if ($tool === 'greeting' && $definition !== '' && mb_strlen($definition) <= 250 && count($greetings) < 5) {
                $greetings[] = $definition;
                continue;
            }
            if ($tool === 'waiting' && $definition !== '' && mb_strlen($definition) <= 120 && count($waiting) < 5) {
                $waiting[] = $definition;
                continue;
            }
            if ($tool === 'http_lookup' && $definition !== '') {
                $http[] = $definition;
                continue;
            }
            if ($tool === 'get_db_value' && $definition !== '') {
                $db[] = $definition;
                continue;
            }
            if ($tool === 'db_connection' && ctype_digit($definition)) {
                $connection = ExternalDbRepository::findById((int) $definition);
                if (!$connection) {
                    throw new InvalidArgumentException(t('builtin_ai_agent.err_tools'));
                }
                $connections[(int) $definition] = true;
                continue;
            }
            [$id, $type, $target, $usage] = array_pad(array_map('trim', explode('|', $definition, 4)), 4, '');
            $targets ??= self::targets();
            if ($tool !== 'call_transfer' || !preg_match('/^[a-z][a-z0-9_]{0,29}$/D', $id)
                || $id === 'none' || isset($tools[$id]) || !isset($targets[$type][$target])
                || $usage === '' || mb_strlen($usage) > 200 || count($tools) >= AiAppService::MAX_INTENTS) {
                throw new InvalidArgumentException(t('builtin_ai_agent.err_tools'));
            }
            $tools[$id] = ['id' => $id, 'name' => $usage, 'keywords' => [], 'examples' => [], 'reply' => '',
                'dest_type' => $type, 'dest_id' => $target,
                'route_id' => substr(hash('sha256', "$id:$type:$target"), 0, 16)];
        }
        $httpTools = AiAppService::parseHttpTools(implode("\n", $http));
        if (array_intersect(['knowledge', 'get_db_value', 'call_transfer', 'end_call'], array_keys($httpTools))) {
            throw new InvalidArgumentException(t('builtin_ai_agent.err_tools'));
        }
        if ($greetings) {
            $voice += ['greeting' => $greetings[0], 'greetings' => $greetings];
        }
        $lookups = AiAppDbLookup::parse(implode("\n", $db));
        $seen = array_fill_keys(array_column($lookups, 'id'), true);
        foreach (array_keys($connections) as $id) {
            foreach (ExternalDbQueryService::keysForConnection($id) as $key) {
                if (!isset($seen[$key])) {
                    $lookups[] = ['id' => $key, 'saved' => true, 'connection_scope' => $id];
                    $seen[$key] = true;
                }
            }
        }
        if (count($lookups) > AiAppDbLookup::MAX_QUERIES) {
            throw new InvalidArgumentException(t('builtin_ai_agent.err_tools'));
        }
        return $voice + ['waiting_phrases' => $waiting, 'intents' => array_values($tools), 'tools' => array_values($httpTools),
            'db_lookups' => $lookups];
    }

    public static function markCollectionChanged(int $collectionId): void
    {
        foreach (AiAppService::all(true) as $agent) {
            if ((int) (AiAppService::config($agent)['knowledge_collection'] ?? 0) === $collectionId) {
                markPendingSync('ai_apps', 'ai_app', $agent['id'], 'AI agent: ' . $agent['title'], 'update', $_SESSION['user_id'] ?? null);
            }
        }
    }
}
