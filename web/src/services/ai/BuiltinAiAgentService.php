<?php

declare(strict_types=1);

require_once __DIR__ . '/AiAppService.php';
require_once __DIR__ . '/AiKnowledgeService.php';
require_once __DIR__ . '/PbxToolRegistry.php';
require_once __DIR__ . '/AgentInstructions.php';

use PBX\Destinations\DestinationRegistry;

final class BuiltinAiAgentService
{
    public const MODES = ['realtime', 'cascade'];
    public const MAX_INSTRUCTIONS = 60000;
    public const WARN_TOKENS = 15000;
    public const MAX_KNOWLEDGE_SOURCES = 8;
    public const NOISE_REDUCTION = ['off', 'near_field', 'far_field'];
    public const TARGET_TYPES = ['extension', 'queue', 'ring_group'];
    public const RETRIES_DEFAULT = 2;
    /** Not selected for a new agent: dials any number for any caller without confirmation (toll fraud on a public line). */
    public const TOOLS_OFF_BY_DEFAULT = ['dial_and_navigate'];
    public const LINE_PROFILES = ['normal', 'noisy', 'faint'];
    public const REALTIME_PROVIDER_TYPES = ['openai', 'google_ai', 'qwen', 'openai_compat'];
    public const INSTRUCTION_VARS = ['{caller_name}', '{caller_id}', '{customer_id}', '{did}', '{date}', '{time}', '{language}'];

    public static function defaultInstructionsTemplate(): string
    {
        // A worked transfer example only with an extension that exists here: a missing target would refuse the save.
        $extensions = array_keys(self::targets()['extension'] ?? []);
        $transfer = $extensions ? "- When the caller asks for a person, transfer to {transfer, operator, " . $extensions[0] . "}.\n" : '';
        // One greeting, in the PBX's own language: an agent speaks one language, others get their own agent or a greeting added by hand.
        $own = CloudAiService::language('');
        $say = langStrings($own)['ai_apps.default_agent_greeting'] ?? langStrings('en')['ai_apps.default_agent_greeting'];
        $greetings = "{greeting, 1, {$own}, " . str_replace(['{', '}'], '', $say) . "}\n";
        return "# Role\n"
            . "You are the virtual front desk of the company. Greet callers politely, understand what they need, answer from the\n"
            . "knowledge base and the tools, and route them. Speak short, natural sentences for the telephone.\n\n"
            . "# Greetings (order, language, text; the call's language picks one)\n"
            . $greetings . "\n"
            . "{language, {$own}}\n\n"
            . "# Caller\n"
            . "Name {caller_name}, number {caller_id}, customer {customer_id}, dialled {did}, {date} {time}, language {language}.\n\n"
            . "# What you can do (use the buttons above to add more)\n"
            . $transfer
            . "\n# Options\n"
            . "{barge_in, on}\n"
            . "\n# Said while a lookup runs\n"
            . "{waiting, One moment please, I am checking.}\n";
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

    /**
     * What an agent can be bound to: installed local voices (the only local AI left) and the configured
     * cloud accounts per capability. Every feature chooses its own model: there is no default.
     * @return array{llm: array<string,string>, stt: array<string,string>, tts: array<string,string>}
     */
    public static function models(): array
    {
        $options = ['llm' => [], 'stt' => [], 'tts' => []];
        if (PluginManager::isEnabled('ailocal')) {
            foreach (LocalAiService::models() as $model) {
                if (($model['kind'] ?? '') === 'tts' && in_array($model['state'] ?? '', ['ready', 'installed', 'loading'], true)) {
                    $options['tts']['local:' . $model['id']] = $model['title'];
                }
            }
        }
        if (AiAppService::isScopeEnabled('cloud')) {
            foreach (['llm', 'stt'] as $kind) {
                foreach (CloudAiService::withCap($kind) as $account) {
                    $options[$kind]['cloud:' . $account] = CloudAiService::provider($account)['title'];
                }
            }
            foreach (AiAppService::cloudTtsAccounts() as $account) {
                $options['tts']['cloud:' . $account] = CloudAiService::provider($account)['title'];
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


    /**
     * What the tool brackets of the instructions are resolved against now: transfer targets, available saved queries,
     * knowledge bases (by name), PBX tools and the AI languages (AgentInstructions::parse()).
     */
    public static function instructionSources(): array
    {
        $queries = [];
        foreach (ExternalDbQueryService::all() as $q) {
            if (ExternalDbQueryService::available($q)) {
                $queries[(string) $q['query_key']] = (string) ($q['description'] ?? '');
            }
        }
        $bases = [];
        if (PluginManager::isEnabled('ai-knowledge')) {
            foreach (AiKnowledgeService::collections() as $c) {
                $bases[(string) $c['name']] = ['id' => (int) $c['id'], 'description' => (string) ($c['description'] ?? '')];
            }
        }
        return [self::targets(), $queries, $bases, array_column(PbxToolRegistry::all(), 'name'), array_keys(CloudAiService::languages())];
    }

    /** The instructions' tools resolved against the PBX as it is now (throws with the line of the first wrong bracket). */
    public static function parseInstructions(string $instructions): array
    {
        return AgentInstructions::parse($instructions, ...self::instructionSources());
    }

    public static function validate(array $data): array
    {
        $mode = ($data['mode'] ?? '') === 'realtime' ? 'realtime' : 'cascade';
        $models = self::models();
        $realtimeAccount = trim((string) ($data['realtime_account'] ?? ''));
        if ($mode === 'realtime') {
            if (!isset(self::realtimeAccounts()[$realtimeAccount])) {
                throw new InvalidArgumentException(t('builtin_ai_agent.err_model'));
            }
        } else {
            foreach (['understand' => 'llm', 'stt_model' => 'stt', 'model_id' => 'tts'] as $key => $kind) {
                if (!isset($models[$kind][(string) ($data[$key] ?? '')])) {
                    throw new InvalidArgumentException(t('builtin_ai_agent.err_model'));
                }
            }
        }
        // Where a call goes when the AI cannot answer: optional, "hangup" unless chosen (the shared target selector).
        $type = trim((string) ($data['dest_type'] ?? '')) ?: 'hangup';
        $target = trim((string) ($data['dest_id'] ?? ''));
        if (!isset(self::targets(true)[$type][$target])) {
            throw new InvalidArgumentException(t('ai_apps.err_dest'));
        }
        $instructions = trim((string) ($data['instructions'] ?? ''));
        if (mb_strlen($instructions) > self::MAX_INSTRUCTIONS) {
            throw new InvalidArgumentException(sprintf(t('ai_apps.err_text'), self::MAX_INSTRUCTIONS));
        }
        // Every tool of the agent is written into its instructions (AgentInstructions); there is no other place.
        $parsed = self::parseInstructions($instructions);
        // {language, xx} in the instructions: the agent's own language (calls whose channel has none); else the first AI language
        $lang = $parsed['options']['language'] ?: CloudAiService::language('');
        $greetings = array_column($parsed['greetings_by_lang'][$lang] ?? [], 'text');
        $greeting = $greetings[0] ?? (langStrings($lang)['ai_apps.default_agent_greeting'] ?? langStrings('en')['ai_apps.default_agent_greeting']);
        if ($greetings === []) {
            $greeting = preg_replace('/^([^,.!?]{1,16}),/u', '$1 {caller_name},', $greeting) ?? $greeting;
        }
        $summaryEmail = $parsed['options']['email'];                                    // {email, …} in the instructions
        if (count($parsed['db_queries']) > AiAppDbLookup::MAX_QUERIES) {
            throw new InvalidArgumentException(t('builtin_ai_agent.err_tools'));
        }
        $config = [
            'mode' => $mode, 'conversation' => true, 'barge_in' => $parsed['options']['barge_in'] ?? true,
            'retry' => langStrings($lang)['ai_apps.default_agent_retry'] ?? langStrings('en')['ai_apps.default_agent_retry'],
            'goodbye' => langStrings($lang)['ai_apps.default_agent_goodbye'] ?? langStrings('en')['ai_apps.default_agent_goodbye'],
        ] + self::listening($data) + [
            'automatic_announcements' => $parsed['options']['announcements'] ?? false,
            'record_call' => !empty($data['record_call']),
            'max_turns' => 12, 'greeting' => $greeting, 'stt_lang' => $lang,
            'stt_model' => $mode === 'cascade' ? (string) $data['stt_model'] : '',
            'understand' => $mode === 'cascade' ? (string) $data['understand'] : '',
            'realtime_account' => $mode === 'realtime' ? $realtimeAccount : '',
            'instructions' => $instructions, 'instructions_model' => $parsed['text'], 'summary_email' => $summaryEmail,
            'transfers' => $parsed['transfers'], 'http_tools' => $parsed['http_tools'],
            'greetings_by_lang' => $parsed['greetings_by_lang'], 'greetings' => $greetings,
            'waiting_phrases' => $parsed['waiting_phrases'],
            'db_queries' => $parsed['db_queries'], 'db_lookups' => array_map(static fn ($k) => ['id' => $k, 'saved' => true], $parsed['db_queries']),
            'knowledge_collections' => $parsed['knowledge_collections'], 'pbx_tools' => $parsed['pbx_tools'],
        ];
        return [
            'id' => (int) ($data['id'] ?? 0), 'title' => (string) ($data['title'] ?? ''),
            'internal_number' => (string) ($data['internal_number'] ?? ''), 'app_type' => AiAppService::BUILTIN_AGENT,
            'dest_type' => $type, 'dest_id' => $target, 'is_active' => 1,
            'model_id' => $mode === 'cascade' ? (string) $data['model_id'] : 'realtime', 'text_template' => $greeting,
            'agent_config' => $config,
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
        $path = rtrim($path, '/') ?: '/';
        if (!in_array($path, self::WIZARD_PAGES, true)) {
            return null;
        }
        if ($path === '/ai-agent' && !isset($_GET['new']) && !isset($_GET['edit'])) {
            return null;                                          // the AI Agent page holds the wizard itself (first tab)
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
            'plugins' => ['done' => $on('aicloud') || $on('ailocal'), 'optional' => false,
                'info' => implode(', ', array_filter(self::WIZARD_PLUGINS, $on)), 'links' => ['/plugins' => 'builtin_ai_agent.wizard_go_plugins']],
            'models' => ['done' => ($models['llm'] && $models['stt'] && $models['tts']) || $realtime !== [], 'optional' => false,
                'info' => $names($models['llm'] + $models['stt'] + $realtime), 'links' => $sources],
            'voice' => ['done' => $models['tts'] !== [] || $realtime !== [], 'optional' => true,
                'info' => $names($models['tts']), 'links' => $sources],
            'knowledge' => ['done' => $collections !== [], 'optional' => true,
                'info' => $names(array_column($collections, 'name')), 'links' => ['/ai-knowledge' => 'builtin_ai_agent.wizard_go_knowledge']],
            'agent' => ['done' => $agents !== [], 'optional' => false, 'info' => $names(array_column($agents, 'title')),
                'links' => ['/ai-agent?new=1' => 'builtin_ai_agent.wizard_go_agent']],
            'number' => ['done' => $numbered !== [], 'optional' => false,
                'info' => $numbered ? $numbered[0]['title'] . ': ' . $numbered[0]['internal_number'] : '',
                'links' => $agents ? ['/ai-agent?edit=' . (int) $agents[0]['id'] => 'builtin_ai_agent.wizard_go_number'] : []],
            'test' => ['done' => $last !== null && ((int) $last['turns'] > 0 || in_array($last['result'], ['matched', 'ended'], true)),
                'optional' => false,
                'info' => $last ? sprintf('%s · %s · %s · %d', $last['created_at'], $last['title'], $last['result'], (int) $last['turns']) : '',
                'number' => $numbered[0]['internal_number'] ?? '', 'links' => ['/ai-agent?tab=calls' => 'builtin_ai_agent.wizard_go_calls']],
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
            // a noisy line makes barge-in harder too, unless set; a faint one does not make it easier
            'barge_profile' => in_array($data['barge_profile'] ?? '', self::LINE_PROFILES, true)
                ? (string) $data['barge_profile'] : ($line === 'noisy' ? 'noisy' : 'normal'),
        ];
    }

    /**
     * The tools an agent's form defines, in the shape the dialplan builder and the AI service use:
     * transfer targets as requests ("intents"), HTTP lookups, greetings, waiting phrases and the tone.
     */
    public static function toolsFromConfig(array $c): array
    {
        $tools = [];
        $broken = self::brokenTransfers($c);
        foreach ((array) ($c['transfers'] ?? []) as $t) {
            if (in_array($t['id'], $broken, true)) {
                // the target was deleted after the agent was saved: only this transfer is left out, the agent works on
                error_log('AI agent: transfer ' . $t['id'] . ' left out, its target ' . $t['type'] . ' ' . $t['target'] . ' no longer exists');
                continue;
            }
            $tools[] = ['id' => $t['id'], 'name' => $t['usage'], 'keywords' => [], 'examples' => [], 'reply' => '',
                'dest_type' => $t['type'], 'dest_id' => $t['target'],
                'route_id' => substr(hash('sha256', "{$t['id']}:{$t['type']}:{$t['target']}"), 0, 16)];
        }
        try {
            $http = AiAppService::parseHttpTools((string) ($c['http_tools'] ?? ''));
        } catch (\Exception $e) {
            $http = [];
            error_log('AI agent: HTTP lookups left out: ' . $e->getMessage());
        }
        $out = ['waiting_phrases' => array_values((array) ($c['waiting_phrases'] ?? [])), 'intents' => $tools,
            'tools' => array_values($http), 'db_lookups' => array_values((array) ($c['db_lookups'] ?? []))];
        if (!empty($c['greetings'])) {
            $out += ['greeting' => $c['greetings'][0], 'greetings' => array_values($c['greetings'])];
        }
        return $out;
    }

    /**
     * Names the caller may say that speech recognition should not miss (Deepgram keyterm prompting, Nova-3):
     * the transfer targets' names and the knowledge bases' names. Numbers and duplicates are left out.
     * @return list<string>
     */
    public static function keyterms(array $c, array $sources): array
    {
        $targets = self::targets();
        $names = [];
        foreach ((array) ($c['transfers'] ?? []) as $t) {
            $names[] = (string) ($targets[$t['type'] ?? ''][(string) ($t['target'] ?? '')] ?? '');
        }
        foreach ($sources as $source) {
            $names[] = (string) $source['name'];
        }
        $out = [];
        foreach ($names as $name) {
            $name = trim((string) preg_replace('/^[\d\s<>()#:.\/-]+|[<>()#]+/u', ' ', $name));
            $name = trim((string) preg_replace('/\s+/u', ' ', $name));
            if (mb_strlen($name) >= 3 && mb_strlen($name) <= 40 && preg_match('/\pL/u', $name)) {
                $out[mb_strtolower($name)] = $name;
            }
        }
        return array_slice(array_values($out), 0, 30);
    }

    /** Transfers whose target no longer exists (deleted after the agent was saved): their ids. @return list<string> */
    public static function brokenTransfers(array $c): array
    {
        if (empty($c['transfers'])) {
            return [];
        }
        $targets = self::targets();
        $out = [];
        foreach ((array) $c['transfers'] as $t) {
            if (!isset($targets[$t['type'] ?? ''][(string) ($t['target'] ?? '')])) {
                $out[] = (string) $t['id'];
            }
        }
        return $out;
    }

    /** The agents that transfer to a destination (deleting it is refused while they do): their titles. @return list<string> */
    public static function agentsUsing(string $type, array $ids): array
    {
        $out = [];
        foreach (AiAppService::all(true) as $agent) {
            foreach ((array) (AiAppService::config($agent)['transfers'] ?? []) as $t) {
                if (($t['type'] ?? '') === $type && in_array((string) ($t['target'] ?? ''), array_map('strval', $ids), true)) {
                    $out[] = (string) $agent['title'];
                    break;
                }
            }
        }
        return $out;
    }

    /** The knowledge bases an agent may search: [{id, name, description}], the model chooses one by its description. */
    public static function knowledgeSources(array $c): array
    {
        if (!PluginManager::isEnabled('ai-knowledge')) {
            return [];
        }
        $out = [];
        foreach ((array) ($c['knowledge_collections'] ?? []) as $cid) {
            $collection = AiKnowledgeService::collection((int) $cid);
            if ($collection && trim((string) $collection['description']) !== '') {
                $out[] = ['id' => (int) $collection['id'], 'name' => mb_substr((string) $collection['name'], 0, 60),
                    'description' => mb_substr(trim((string) $collection['description']), 0, 200)];
            }
        }
        return $out;
    }

    /** Whether the agent can answer at all: what its mode needs is chosen and usable. */
    public static function ready(array $svcCfg): bool
    {
        if (empty($svcCfg['conversation'])) {
            return false;
        }
        if (($svcCfg['mode'] ?? '') === 'realtime') {
            return !empty($svcCfg['realtime_cloud']);
        }
        return ($svcCfg['mode'] ?? '') === 'cascade' && ($svcCfg['tts_model'] ?? '') !== '' && ($svcCfg['stt_model'] ?? '') !== ''
            && !empty($svcCfg['llm_cloud']);
    }

    public static function markCollectionChanged(int $collectionId): void
    {
        foreach (AiAppService::all(true) as $agent) {
            if (in_array($collectionId, array_map('intval', (array) (AiAppService::config($agent)['knowledge_collections'] ?? [])), true)) {
                markPendingSync('ai_apps', 'ai_app', $agent['id'], 'AI agent: ' . $agent['title'], 'update', $_SESSION['user_id'] ?? null);
            }
        }
    }

    /**
     * What PUT /v1/apps/<id> sends the AI service for a built-in agent. Explicit choices only: a model that is
     * missing, over its limit or blocked by the privacy lock leaves the agent not ready (calls go to its
     * destination); nothing falls back to another model or mode.
     */
    public static function serviceConfig(array $app, bool $resolveCredentials = false): array
    {
        $c = AiAppService::config($app);
        $c = self::toolsFromConfig($c) + $c;
        $appId = (int) ($app['id'] ?? 0);
        $mode = in_array($c['mode'] ?? '', self::MODES, true) ? (string) $c['mode'] : '';
        $cloudAllowed = !AiAppService::isPrivacyCloudBlocked() && AiAppService::isScopeEnabled('cloud');
        $lang = CloudAiService::language((string) ($c['stt_lang'] ?? ''));
        $out = [
            'type' => 'voice_requests', 'builtin_agent' => true, 'mode' => $mode, 'conversation' => true,
            'tts_model' => '', 'stt_model' => '', 'understand' => '',
            'language' => $lang, 'language_name' => CloudAiService::languageName($lang), 'language_locale' => CloudAiService::languageLocale($lang),
            'speed' => (float) ($app['speed'] ?? 1) ?: 1.0,
            'greeting' => (string) ($c['greeting'] ?? ''), 'not_understood' => '', 'threshold' => 0.5,
            'barge_in' => !empty($c['barge_in']),
            'instructions' => (string) ($c['instructions_model'] ?? $c['instructions'] ?? ''), 'max_turns' => (int) ($c['max_turns'] ?? 12),
            'tools' => $c['tools'] ?? [], 'db_queries' => AiAppDbLookup::metadata($c['db_lookups'] ?? []),
            'automatic_announcements' => !empty($c['automatic_announcements']),
            'greetings' => $c['greetings'] ?? [], 'waiting_phrases' => $c['waiting_phrases'] ?? [],
            // every language's greetings: the AI service picks by the call's channel language (else the agent's)
            'greetings_by_lang' => (object) ($c['greetings_by_lang'] ?? []),
            'languages' => (object) array_map(static fn ($l) => ['name' => $l['name'], 'locale' => $l['locale']], CloudAiService::languages()),
            'intents' => array_map(static fn ($i) => array_intersect_key($i, array_flip(['id', 'name', 'keywords', 'examples', 'reply', 'route_id'])),
                $c['intents'] ?? []),
        ] + self::listening($c);
        foreach (['retry', 'goodbye'] as $phrase) {
            $saved = trim((string) ($c[$phrase] ?? ''));
            $out[$phrase] = $saved !== '' ? $saved
                : (langStrings($lang)['ai_apps.default_agent_' . $phrase] ?? langStrings('en')['ai_apps.default_agent_' . $phrase] ?? '');
        }
        $sources = self::knowledgeSources($c);
        $out['knowledge'] = $sources !== [];
        if ($sources !== []) {
            $out['knowledge_sources'] = $sources;
        }
        if (!empty($c['pbx_tools']) && is_array($c['pbx_tools'])) {
            require_once __DIR__ . '/PbxToolRegistry.php';
            $enabledPbx = PbxToolRegistry::forEnabled(array_map('strval', $c['pbx_tools']));
            if ($enabledPbx !== []) {
                $out['pbx_tools'] = $enabledPbx;
                $out['pbx_tools_key'] = AiAppService::dialplanKey();
            }
        }
        if ($mode === 'realtime' && $cloudAllowed) {
            $payload = self::realtimePayload((string) ($c['realtime_account'] ?? ''), $c, $lang);
            if ($payload !== null) {
                $out['realtime_cloud'] = $payload;
            }
        } elseif ($mode === 'cascade' && $cloudAllowed) {
            $tts = (string) ($app['model_id'] ?? '');
            if (str_starts_with($tts, 'cloud:') && AiAppService::isCloudTtsReady(substr($tts, 6))) {
                $out['tts_model'] = $tts;
                $out['tts_cloud'] = AiAppService::cloudTtsPayload(substr($tts, 6), $lang);
            } elseif (str_starts_with($tts, 'local:') && AiAppService::isScopeEnabled('local')) {
                $out['tts_model'] = substr($tts, 6);
            }
            $stt = (string) ($c['stt_model'] ?? '');
            if (str_starts_with($stt, 'cloud:')) {
                $sttId = substr($stt, 6);
                if (CloudAiService::typeIfKnown($sttId) !== '' && CloudAiService::configured($sttId) && !CloudAiService::overLimit($sttId, 'stt')) {
                    $out['stt_model'] = $stt;
                    $out['stt_cloud'] = AiAppService::cloudSttPayload($sttId, $lang);
                }
            }
            if (($out['stt_cloud']['provider'] ?? '') === 'deepgram' && isset($out['stt_cloud'])) {
                $out['stt_keyterms'] = self::keyterms($c, $sources);
            }
            $llm = (string) ($c['understand'] ?? '');
            if (str_starts_with($llm, 'cloud:')) {
                $llmId = substr($llm, 6);
                if (CloudAiService::typeIfKnown($llmId) !== '' && CloudAiService::configured($llmId) && !CloudAiService::overLimit($llmId, 'llm')) {
                    $out['understand'] = 'cloud';
                    $out['llm_cloud'] = CloudAiService::llmSettings($llmId);
                }
            }
        } elseif ($mode === 'cascade') {          // cloud blocked or off: only a local voice can still speak
            $tts = (string) ($app['model_id'] ?? '');
            if (str_starts_with($tts, 'local:') && AiAppService::isScopeEnabled('local')) {
                $out['tts_model'] = substr($tts, 6);
            }
        }
        if (!$resolveCredentials) {
            foreach (['tts', 'stt', 'llm', 'realtime'] as $task) {
                $key = $task . '_cloud';
                if (isset($out[$key])) {
                    $provider = (string) ($out[$key]['provider'] ?? '');
                    $out[$key] = ['provider' => $provider, 'secret_ref' => 'app:' . $appId . ':' . $task . ':' . $provider]
                        + array_diff_key($out[$key], ['api_key' => 1, 'secret_ref' => 1, 'provider' => 1]);
                }
            }
        }
        return $out;
    }

    /** The Realtime connection of an account: provider, key, model, the agent's voice and the speech-detection settings. */
    private static function realtimePayload(string $account, array $c, string $lang): ?array
    {
        if (!str_starts_with($account, 'cloud:')) {
            return null;
        }
        $id = substr($account, 6);
        $type = CloudAiService::typeIfKnown($id);
        if ($type === '' || !isset(AiProviderManifest::realtimeModels()[$type]) || !CloudAiService::configured($id) || CloudAiService::overLimit($id, 'llm')) {
            return null;
        }
        $pc = CloudAiService::config($id);
        $hasAuth = !empty($pc['api_key']) || ($type === 'openai_compat' && !empty($pc['base_url']));
        if (!$hasAuth) {
            return null;
        }
        $protocol = (string) ($pc['realtime_protocol'] ?? '');
        $model = trim((string) ($pc['realtime_model'] ?? ''));
        if ($model === '') {
            $model = $type === 'openai_compat'
                ? ($protocol === 'openai_ga' ? 'gpt-realtime' : (trim((string) ($pc['model'] ?? '')) ?: 'gpt-4o-realtime-preview'))
                : AiProviderManifest::realtimeModels()[$type];
        }
        $payload = [
            'provider' => $type, 'api_key' => (string) ($pc['api_key'] ?? ''), 'model' => $model,
            // the voice and the speech detection are the account's (two settings = two accounts, "Copy")
            'voice' => trim((string) ($pc['realtime_voice'] ?? '')) ?: 'alloy', 'lang' => $lang,
            'vad' => ['threshold' => max(0.1, min(0.99, (float) ($pc['realtime_vad_threshold'] ?? '') ?: 0.5)),
                      'silence_ms' => max(200, min(3000, (int) ($pc['realtime_silence_ms'] ?? '') ?: 500))],
            'noise_reduction' => in_array($pc['realtime_noise_reduction'] ?? '', self::NOISE_REDUCTION, true) ? (string) $pc['realtime_noise_reduction'] : 'off',
        ];
        if (is_numeric($pc['realtime_temperature'] ?? null)) {
            $payload['temperature'] = max(0.2, min(1.2, (float) $pc['realtime_temperature']));
        }
        if (!empty($pc['base_url'])) {
            $payload['base_url'] = (string) $pc['base_url'];
        }
        if ($type === 'openai_compat') {
            $payload['protocol'] = $protocol;
            if (!empty($pc['realtime_ws_url'])) {
                $payload['ws_url'] = (string) $pc['realtime_ws_url'];
            }
        }
        return $payload;
    }
}
