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
    public const LOCAL_CONTEXTS = [0, 2048, 4096, 8192, 16384];
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
        $summaryEmail = trim((string) ($data['summary_email'] ?? ''));
        if ($summaryEmail !== '' && (mb_strlen($summaryEmail) > 254 || filter_var($summaryEmail, FILTER_VALIDATE_EMAIL) === false)) {
            throw new InvalidArgumentException(t('builtin_ai_agent.err_summary_email'));
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
                'goodbye' => langStrings($lang)['ai_apps.default_agent_goodbye'] ?? langStrings('en')['ai_apps.default_agent_goodbye'],
            ] + self::listening($data) + [
                'automatic_announcements' => !empty($data['automatic_announcements']),
                'max_turns' => 12, 'greeting' => $greeting, 'stt_lang' => $lang, 'stt_model' => (string) $data['stt_model'],
                'understand' => (string) $data['understand'], 'knowledge_collection' => $collectionId,
                'realtime_account' => $realtimeAccount, 'realtime_voice' => $realtimeVoice,
                'pbx_tools' => $pbxTools,
                'instructions' => $instructions,
                'summary_email' => $summaryEmail,
                'db_queries' => array_keys($seenQueries),
                'db_lookups' => $dbLookups,
            ],
        ];
    }

    public const STATS_DAYS = 30;
    /** Plugins the wizard may turn on: the knowledge base (an agent needs one) and the two model sources. */
    public const WIZARD_PLUGINS = ['ai-knowledge', 'ailocal', 'aicloud'];

    /** Pages a wizard step sends the administrator to: they show a bar back to the wizard. */
    public const WIZARD_PAGES = ['/plugins', '/ai-local', '/ai-cloud', '/ai-knowledge', '/ai-agent'];

    /**
     * The bar on a page the wizard sent the administrator to: ['done' => n, 'total' => n, 'step' => key] or null.
     * The wizard sets the session flag when it is opened; the bar's close link, or a finished wizard, clears it.
     */
    public static function wizardBar(string $path): ?array
    {
        if (empty($_SESSION['ai_wizard_return'])) {
            return null;
        }
        if (($_GET['wizard_bar'] ?? '') === 'off') {
            unset($_SESSION['ai_wizard_return']);
            return null;
        }
        if (!in_array(rtrim($path, '/') ?: '/', self::WIZARD_PAGES, true)) {
            return null;
        }
        $steps = self::wizardSteps();
        $open = array_keys(array_filter($steps, static fn ($s) => !$s['done']));
        if ($open === []) {
            unset($_SESSION['ai_wizard_return']);
            return null;
        }
        return ['done' => count($steps) - count($open), 'total' => count($steps), 'step' => $open[0]];
    }

    /**
     * The setup wizard's steps, each read from the system as it is now (so settings made by hand tick
     * their step too): key => ['done' => bool, 'optional' => bool, 'info' => string, 'links' => [href => label key]].
     */
    public static function wizardSteps(): array
    {
        $on = static fn (string $slug): bool => PluginManager::isEnabled($slug);
        $models = self::models();
        $realtime = $on('aicloud') ? self::realtimeAccounts() : [];
        $names = static fn (array $list): string => implode(', ', array_slice(array_values($list), 0, 3));
        $collections = $on('ai-knowledge') ? array_filter(AiKnowledgeService::collections(), static fn ($c) => (int) $c['doc_count'] > 0) : [];
        $agents = AiAppService::all(true);
        $numbered = array_values(array_filter($agents, static fn ($a) => trim((string) ($a['internal_number'] ?? '')) !== ''));
        $last = null;
        if ($agents) {
            $last = getDB()->query("SELECT r.created_at, r.result, r.turns, a.title FROM ai_requests r JOIN pbx_ai_apps a ON a.id = r.app_id
                WHERE a.app_type = '" . AiAppService::BUILTIN_AGENT . "' ORDER BY r.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $sources = array_filter(['/ai-local' => $on('ailocal') ? 'builtin_ai_agent.wizard_go_local' : null,
                                 '/ai-cloud' => $on('aicloud') ? 'builtin_ai_agent.wizard_go_cloud' : null]);
        return [
            'plugins' => ['done' => $on('ai-knowledge') && ($on('ailocal') || $on('aicloud')), 'optional' => false,
                'info' => implode(', ', array_filter(self::WIZARD_PLUGINS, $on)), 'links' => ['/plugins' => 'builtin_ai_agent.wizard_go_plugins']],
            'models' => ['done' => ($models['llm'] && $models['stt']) || $realtime !== [], 'optional' => false,
                'info' => $names($models['llm'] + $models['stt'] + $realtime), 'links' => $sources],
            'voice' => ['done' => $models['tts'] !== [] || $realtime !== [], 'optional' => false,
                'info' => $names($models['tts']), 'links' => $sources],
            'knowledge' => ['done' => $collections !== [], 'optional' => false,
                'info' => $names(array_column($collections, 'name')), 'links' => ['/ai-knowledge' => 'builtin_ai_agent.wizard_go_knowledge']],
            'agent' => ['done' => $agents !== [], 'optional' => false, 'info' => $names(array_column($agents, 'title')),
                'links' => ['/ai-agent?new=1' => 'builtin_ai_agent.wizard_go_agent']],
            'number' => ['done' => $numbered !== [], 'optional' => false,
                'info' => $numbered ? $numbered[0]['title'] . ': ' . $numbered[0]['internal_number'] : '',
                'links' => $agents ? ['/ai-agent?edit=' . (int) $agents[0]['id'] => 'builtin_ai_agent.wizard_go_number'] : []],
            'test' => ['done' => $last !== null && (int) $last['turns'] > 0, 'optional' => false,
                'info' => $last ? sprintf('%s · %s · %s · %d', $last['created_at'], $last['title'], $last['result'], (int) $last['turns']) : '',
                'number' => $numbered[0]['internal_number'] ?? '', 'links' => ['/ai-agent' => 'builtin_ai_agent.wizard_go_calls']],
        ];
    }

    /**
     * Per agent over the last STATS_DAYS days: calls, how they ended (handed over, finished by the agent,
     * not understood or silent, hung up) and the caller's turns on average. Keyed by app id.
     */
    public static function stats(): array
    {
        $stmt = getDB()->prepare("SELECT r.app_id, COUNT(*) AS calls,
                SUM(r.result = 'matched') AS handed, SUM(r.result = 'ended') AS ended,
                SUM(r.result IN ('not_understood', 'silence')) AS missed, SUM(r.result = 'hangup') AS hangup,
                ROUND(AVG(NULLIF(r.turns, 0)), 1) AS turns
            FROM ai_requests r JOIN pbx_ai_apps a ON a.id = r.app_id
            WHERE a.app_type = ? AND r.created_at >= NOW() - INTERVAL " . self::STATS_DAYS . " DAY GROUP BY r.app_id");
        $stmt->execute([AiAppService::BUILTIN_AGENT]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['app_id']] = array_map(static fn ($v) => $v === null ? 0 : $v + 0, $row);
        }
        return $out;
    }

    /** The calls of every agent (newest first, at most 90 days) as CSV rows, header first. */
    public static function callsCsv(): array
    {
        $stmt = getDB()->prepare("SELECT r.created_at, a.title, r.caller, r.caller_name, r.result, r.intent_name, r.turns, r.transcript
            FROM ai_requests r JOIN pbx_ai_apps a ON a.id = r.app_id
            WHERE a.app_type = ? AND r.created_at >= NOW() - INTERVAL 90 DAY ORDER BY r.id DESC LIMIT 20000");
        $stmt->execute([AiAppService::BUILTIN_AGENT]);
        $rows = [['time', 'agent', 'caller', 'caller_name', 'result', 'handed_to', 'turns', 'conversation']];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
            // A cell starting with = + - @ would run as a formula in a spreadsheet.
            $rows[] = array_map(static fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v, $row);
        }
        return $rows;
    }

    /** Which optional detectors are installed (Local AI plugin on and the model downloaded): ['vad' => bool, 'turn' => bool]. */
    public static function detectors(): array
    {
        $ready = ['vad' => false, 'turn' => false];
        if (!PluginManager::isEnabled('ailocal')) {
            return $ready;
        }
        try {
            foreach (LocalAiService::models() as $model) {
                if (isset($ready[$model['kind'] ?? '']) && in_array($model['state'] ?? '', ['ready', 'installed', 'loading'], true)) {
                    $ready[$model['kind']] = true;
                }
            }
        } catch (\Throwable) {
            // the AI service is not running: nothing is installed as far as the form can tell
        }
        return $ready;
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
        $line = in_array($data['line_profile'] ?? '', self::LINE_PROFILES, true) ? (string) $data['line_profile'] : 'normal';
        return [
            'retries' => $legacy ? self::RETRIES_DEFAULT : $int('retries', 0, 2, self::RETRIES_DEFAULT),
            'silence_seconds' => $int('silence_seconds', 3, 10, 4),
            'listen_seconds' => $int('listen_seconds', 5, 15, 10),
            'line_profile' => $line,
            // optional detectors (small models installed on the Local AI page); the standard detection is the default
            'speech_detection' => ($data['speech_detection'] ?? '') === 'silero' ? 'silero' : 'standard',
            'end_of_turn' => ($data['end_of_turn'] ?? '') === 'smart' ? 'smart' : 'fixed',
            // a goodbye from the caller (or the agent) ends the call; agents saved before this setting get it
            'end_on_goodbye' => $legacy ? true : !empty($data['end_on_goodbye']),
            'local_context' => in_array((int) ($data['local_context'] ?? 0), self::LOCAL_CONTEXTS, true) ? (int) ($data['local_context'] ?? 0) : 0,
            // a noisy line makes barge-in harder too, unless set; a faint one does not make it easier
            'barge_profile' => in_array($data['barge_profile'] ?? '', self::LINE_PROFILES, true)
                ? (string) $data['barge_profile'] : ($line === 'noisy' ? 'noisy' : 'normal'),
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
