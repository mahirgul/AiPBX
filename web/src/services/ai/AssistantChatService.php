<?php
/**
 * AI → Assistant Chat Service:
 *  - Chat & message persistence (`assistant_chats`, `assistant_messages`)
 *  - Retention pruning (`pruneOldChats`)
 *  - Role (`ask` / `change`) and non-admin own-extension scope enforcement
 *  - Classifier pipeline: rules first -> Echo read-only -> menu navigation -> model (last 6 msgs) -> unknown
 */

require_once __DIR__ . '/AssistantPlanner.php';
require_once __DIR__ . '/AssistantPlanContext.php';
require_once __DIR__ . '/AssistantPlanService.php';
require_once __DIR__ . '/AssistantModel.php';
require_once __DIR__ . '/EchoReporter.php';
require_once __DIR__ . '/AiKnowledgeService.php';
require_once dirname(__DIR__, 2) . '/core/PluginManager.php';

if (file_exists(__DIR__ . '/AssistantSettingsService.php')) {
    require_once __DIR__ . '/AssistantSettingsService.php';
}

class AssistantChatService
{
    private const OWN_EXTENSION_ACTIONS = [
        'extension.forward',
        'extension.dnd',
        'extension.voicemail',
        'extension.rename',
        'voicemail.greeting',
    ];

    /** Fixed menu navigation catalog (module => [href, label_key, keywords]). */
    private const NAV_PAGES = [
        'dashboard' => [
            'href' => '/dashboard',
            'label_key' => 'sidebar.item_dashboard_overview',
            'keywords' => ['dashboard', 'overview', 'gösterge', 'gosterge', 'ana sayfa', 'özet', 'ozet'],
        ],
        'my_phone' => [
            'href' => '/my-phone',
            'label_key' => 'sidebar.item_my_phone',
            'keywords' => ['my phone', 'webphone', 'telefonum', 'softphone'],
        ],
        'extensions' => [
            'href' => '/extensions',
            'label_key' => 'sidebar.item_extensions',
            'keywords' => ['extension', 'extensions', 'dahili', 'dahililer'],
        ],
        'trunks' => [
            'href' => '/trunks',
            'label_key' => 'sidebar.item_trunks',
            'keywords' => ['trunk', 'trunks', 'sip trunk', 'dış hat', 'dis hat'],
        ],
        'did_routes' => [
            'href' => '/did-routes',
            'label_key' => 'sidebar.item_did_routes',
            'keywords' => ['did', 'inbound', 'gelen çağrı', 'gelen cagri', 'gelen rota'],
        ],
        'outbound_routes' => [
            'href' => '/outbound-routes',
            'label_key' => 'sidebar.item_outbound_routes',
            'keywords' => ['outbound', 'giden çağrı', 'giden cagri', 'giden rota'],
        ],
        'ivrs' => [
            'href' => '/ivrs',
            'label_key' => 'sidebar.item_ivrs',
            'keywords' => ['ivr', 'auto attendant', 'karşılama', 'karsilama', 'sesli yanıt', 'sesli yanit'],
        ],
        'time_conditions' => [
            'href' => '/time-conditions',
            'label_key' => 'sidebar.item_time_conditions',
            'keywords' => ['time condition', 'time conditions', 'zaman', 'mesai'],
        ],
        'ring_groups' => [
            'href' => '/ring-groups',
            'label_key' => 'sidebar.item_ring_groups',
            'keywords' => ['ring group', 'ring groups', 'çalma grubu', 'calma grubu'],
        ],
        'queues' => [
            'href' => '/queues',
            'label_key' => 'sidebar.item_queues',
            'keywords' => ['queue', 'queues', 'kuyruk', 'kuyruklar'],
        ],
        'conferences' => [
            'href' => '/conferences',
            'label_key' => 'sidebar.item_conferences',
            'keywords' => ['conference', 'conferences', 'konferans'],
        ],
        'sounds' => [
            'href' => '/sounds',
            'label_key' => 'sidebar.item_sounds',
            'keywords' => ['sound', 'sounds', 'music', 'moh', 'ses', 'sesler', 'müzik', 'muzik'],
        ],
        'cdr_reports' => [
            'href' => '/cdr-reports',
            'label_key' => 'sidebar.item_cdr_reports',
            'keywords' => ['cdr', 'call report', 'call reports', 'çağrı raporu', 'cagri raporu', 'rapor', 'kayıt'],
        ],
        'asterisk_settings' => [
            'href' => '/asterisk-settings',
            'label_key' => 'sidebar.item_asterisk_settings',
            'keywords' => ['pbx settings', 'asterisk settings', 'pbx ayarları', 'santral ayarları'],
        ],
        'system_users' => [
            'href' => '/system-users',
            'label_key' => 'sidebar.item_system_users',
            'keywords' => ['user', 'users', 'kullanıcı', 'kullanıcılar', 'kullanici'],
        ],
        'roles' => [
            'href' => '/roles',
            'label_key' => 'sidebar.item_roles',
            'keywords' => ['role', 'roles', 'permission', 'permissions', 'rol', 'roller', 'yetki'],
        ],
        'firewall' => [
            'href' => '/firewall',
            'label_key' => 'sidebar.item_firewall',
            'keywords' => ['firewall', 'güvenlik duvarı', 'guvenlik duvari'],
        ],
        'backup' => [
            'href' => '/backup',
            'label_key' => 'sidebar.item_backup',
            'keywords' => ['backup', 'restore', 'yedek', 'yedekleme'],
        ],
        'ai_local' => [
            'href' => '/ai-local',
            'label_key' => 'ai_local.title',
            'keywords' => ['local ai', 'ollama', 'hugging face', 'yerel ai', 'yerel model'],
        ],
        'ai_cloud' => [
            'href' => '/ai-cloud',
            'label_key' => 'ai_cloud.title',
            'keywords' => ['cloud ai', 'bulut ai', 'openai', 'gemini'],
        ],
        'assistant_settings' => [
            'href' => '/pbx-assistant',
            'label_key' => 'ai_settings.ast_title',
            'keywords' => ['ai settings', 'assistant settings', 'yapay zeka ayar', 'asistan ayar'],
        ],
    ];

    public static function canAsk(string $role): bool
    {
        if (class_exists('AssistantSettingsService')) {
            return AssistantSettingsService::canAsk($role);
        }
        return self::roleFlag($role, 'ask', $role === 'admin');
    }

    public static function canChange(string $role): bool
    {
        if (class_exists('AssistantSettingsService')) {
            return AssistantSettingsService::canChange($role);
        }
        return self::roleFlag($role, 'change', $role === 'admin');
    }

    private static function roleFlag(string $role, string $flag, bool $default): bool
    {
        if ($role === 'admin') {
            return true;
        }
        $raw = function_exists('getSystemSetting') ? (string) getSystemSetting('assistant.roles', '') : '';
        if ($raw === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded[$role]) || !is_array($decoded[$role])) {
            return $default;
        }
        return !empty($decoded[$role][$flag]);
    }

    /**
     * Validate that a plan is within the user's scope.
     * Returns null when allowed, or an error string when forbidden.
     */
    public static function validateScope(array $plan, array $user): ?string
    {
        $role = (string) ($user['role'] ?? '');
        if (!self::canChange($role)) {
            return t('assistant.err_change_not_allowed');
        }
        if ($role === 'admin') {
            return null;
        }
        $ownExt = trim((string) ($user['extension'] ?? ''));
        if ($ownExt === '') {
            return t('assistant.err_no_extension');
        }
        $action = (string) ($plan['action'] ?? '');
        if (!in_array($action, self::OWN_EXTENSION_ACTIONS, true)) {
            return t('assistant.err_own_extension_only');
        }
        $targetExt = trim((string) ($plan['arguments']['extension'] ?? ''));
        if ($targetExt === '' || $targetExt !== $ownExt) {
            return t('assistant.err_own_extension_only');
        }
        return null;
    }

    /** @return array<int, array{id:int, user_id:int, title:string, created_at:string, updated_at:string}> */
    public static function listChats(int $userId, int $limit = 50): array
    {
        if (AssistantSettingsService::historyDays() === 0) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $st = getDB()->prepare(
            "SELECT id, user_id, title, created_at, updated_at
             FROM assistant_chats
             WHERE user_id = ?
             ORDER BY updated_at DESC, id DESC
             LIMIT {$limit}"
        );
        $st->execute([$userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getChat(int $chatId, int $userId): ?array
    {
        if ($chatId <= 0 || AssistantSettingsService::historyDays() === 0) {
            return null;
        }
        $st = getDB()->prepare('SELECT id, user_id, title, created_at, updated_at FROM assistant_chats WHERE id = ? AND user_id = ?');
        $st->execute([$chatId, $userId]);
        $chat = $st->fetch(PDO::FETCH_ASSOC);
        if (!$chat) {
            return null;
        }
        $msgSt = getDB()->prepare(
            'SELECT id, chat_id, role, text, kind, plan_id, payload_json, created_at
             FROM assistant_messages
             WHERE chat_id = ?
             ORDER BY id ASC'
        );
        $msgSt->execute([$chatId]);
        $messages = [];
        foreach ($msgSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payload = null;
            if (!empty($row['payload_json'])) {
                $decoded = json_decode((string) $row['payload_json'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
            $messages[] = [
                'id' => (int) $row['id'],
                'role' => (string) $row['role'],
                'text' => (string) $row['text'],
                'kind' => (string) $row['kind'],
                'plan_id' => $row['plan_id'] !== null ? (int) $row['plan_id'] : null,
                'payload' => $payload,
                'created_at' => (string) $row['created_at'],
            ];
        }
        $chat['messages'] = $messages;
        return $chat;
    }

    public static function createChat(int $userId, string $title = ''): int
    {
        if (AssistantSettingsService::historyDays() === 0) {
            return 0;
        }
        $cleanTitle = mb_substr(trim($title), 0, 180);
        $st = getDB()->prepare(
            'INSERT INTO assistant_chats (user_id, title, created_at, updated_at) VALUES (?, ?, NOW(), NOW())'
        );
        $st->execute([$userId, $cleanTitle]);
        return (int) getDB()->lastInsertId();
    }

    public static function deleteChat(int $chatId, int $userId): bool
    {
        $st = getDB()->prepare('SELECT id FROM assistant_chats WHERE id = ? AND user_id = ?');
        $st->execute([$chatId, $userId]);
        if (!$st->fetchColumn()) {
            return false;
        }
        getDB()->prepare('DELETE FROM assistant_messages WHERE chat_id = ?')->execute([$chatId]);
        $del = getDB()->prepare('DELETE FROM assistant_chats WHERE id = ? AND user_id = ?');
        $del->execute([$chatId, $userId]);
        return $del->rowCount() > 0;
    }

    public static function addMessage(
        int $chatId,
        string $role,
        string $text,
        string $kind = 'text',
        ?int $planId = null,
        ?array $payload = null
    ): int {
        if ($chatId <= 0 || AssistantSettingsService::historyDays() === 0) {
            return 0;
        }
        $payloadJson = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;
        $st = getDB()->prepare(
            'INSERT INTO assistant_messages (chat_id, role, text, kind, plan_id, payload_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $st->execute([$chatId, $role, $text, $kind, $planId, $payloadJson]);
        $msgId = (int) getDB()->lastInsertId();

        if ($role === 'user') {
            $titleSnippet = mb_substr(trim($text), 0, 80);
            getDB()->prepare(
                "UPDATE assistant_chats
                 SET updated_at = NOW(),
                     title = CASE WHEN title = '' THEN ? ELSE title END
                 WHERE id = ?"
            )->execute([$titleSnippet, $chatId]);
        } else {
            getDB()->prepare('UPDATE assistant_chats SET updated_at = NOW() WHERE id = ?')->execute([$chatId]);
        }

        return $msgId;
    }

    /** Last N messages in chronological order (oldest first). */
    public static function lastMessages(int $chatId, int $limit = 6, ?int $excludeMessageId = null): array
    {
        if ($chatId <= 0 || AssistantSettingsService::historyDays() === 0) {
            return [];
        }
        $limit = max(1, min(20, $limit));
        if ($excludeMessageId !== null) {
            $st = getDB()->prepare(
                "SELECT id, role, text, kind, created_at
                 FROM assistant_messages
                 WHERE chat_id = ? AND id <> ?
                 ORDER BY id DESC
                 LIMIT {$limit}"
            );
            $st->execute([$chatId, $excludeMessageId]);
        } else {
            $st = getDB()->prepare(
                "SELECT id, role, text, kind, created_at
                 FROM assistant_messages
                 WHERE chat_id = ?
                 ORDER BY id DESC
                 LIMIT {$limit}"
            );
            $st->execute([$chatId]);
        }
        return array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Delete chats older than configured retention days (default 10). */
    public static function pruneOldChats(?int $days = null): int
    {
        return AssistantSettingsService::pruneOldChats($days);
    }

    /**
     * Match menu navigation queries ("kuyruk ayarları nerede", "where are extensions").
     *
     * @return array<int, array{href:string, label:string, module:string}>
     */
    public static function matchNavigation(string $question, string $role): array
    {
        $q = mb_strtolower(trim($question));
        if ($q === '') {
            return [];
        }
        $hasNavCue = (bool) preg_match(
            '/\b(?:where|nerede|nereye|sayfa|sayfası|sayfasi|page|open|git|menü|menu|ayar|ayarlar|ayarları|ayarlari|settings)\b/u',
            $q
        );
        if (!$hasNavCue) {
            return [];
        }

        $matches = [];
        foreach (self::NAV_PAGES as $module => $spec) {
            if ($role !== 'admin' && function_exists('hasModulePermission') && !hasModulePermission($module, 'view')) {
                continue;
            }
            foreach ($spec['keywords'] as $kw) {
                if (mb_strpos($q, $kw) !== false) {
                    $matches[] = [
                        'href' => $spec['href'],
                        'label' => t($spec['label_key']),
                        'module' => $module,
                    ];
                    break;
                }
            }
        }
        return array_slice($matches, 0, 4);
    }

    /**
     * Main classifier pipeline:
     *   1. Rules first (`AssistantPlanner::fromRules`)
     *   2. Echo read-only (`EchoReporter::match` -> `EchoReporter::ask`)
     *   3. Menu navigation (`self::matchNavigation`)
     *   4. Model fallback with last 6 messages (`AssistantPlanner::fromModelReply`)
     *   5. Honest unknown (`kind = 'unknown'` with `EchoReporter::askable()`)
     *
     * @param array{id:int, role:string, extension?:?string} $user
     * @param callable|null $modelAsk fn(string $prompt): ?string
     * @param array|null $context optional AssistantPlanner::context() override (useful in tests)
     */
    public static function ask(
        string $question,
        int $chatId,
        array $user,
        ?callable $modelAsk = null,
        ?array $context = null
    ): array {
        $question = trim($question);
        $userId = $user['id'];
        $role = $user['role'];
        $ownExt = trim((string) ($user['extension'] ?? ''));
        $isAdmin = $role === 'admin';

        if (!self::canAsk($role)) {
            throw new RuntimeException(t('assistant.err_unauthorized'));
        }
        if ($question === '') {
            throw new RuntimeException(t('ai_assistant.err_empty'));
        }

        if ($chatId <= 0 || self::getChat($chatId, $userId) === null) {
            $chatId = self::createChat($userId, $question);
        }

        $userMsgId = self::addMessage($chatId, 'user', $question, 'text');

        if ($context === null) {
            $context = AssistantPlanner::context(
                AssistantPlanContext::extensions(),
                AssistantPlanContext::ringGroups(),
                AssistantPlanContext::queues()
            );
        }

        // 1. Rules first (rules do not use conversation context).
        $rulePlan = AssistantPlanner::fromRules($question, $context);
        if ($rulePlan !== null) {
            return self::finishPlanResponse($chatId, $question, $rulePlan, $user);
        }

        // 2. Echo read-only queries.
        if (EchoReporter::match($question) !== null) {
            $echo = EchoReporter::ask($question, ['is_admin' => $isAdmin, 'extension' => $ownExt]);
            $summaryText = $echo['narration'] ?: ($echo['title'] !== '' ? $echo['title'] : t('assistant.echo_title'));
            $msgId = self::addMessage($chatId, 'assistant', $summaryText, 'info', null, $echo);
            return [
                'chat_id' => $chatId,
                'message' => [
                    'id' => $msgId,
                    'role' => 'assistant',
                    'kind' => 'info',
                    'text' => $summaryText,
                    'plan_id' => null,
                    'payload' => $echo,
                ],
            ];
        }

        // 3. Menu navigation.
        $navLinks = self::matchNavigation($question, $role);
        if ($navLinks) {
            $text = t('assistant.nav_intro');
            $payload = ['links' => $navLinks];
            $msgId = self::addMessage($chatId, 'assistant', $text, 'nav', null, $payload);
            return [
                'chat_id' => $chatId,
                'message' => [
                    'id' => $msgId,
                    'role' => 'assistant',
                    'kind' => 'nav',
                    'text' => $text,
                    'plan_id' => null,
                    'payload' => $payload,
                ],
            ];
        }

        // 4. A clear match in a guide of the knowledge base answers at once, before the model.
        // (A single word is a page or setting name: step 5 shows where it is.)
        $knowledge = str_contains(trim($question), ' ') ? self::knowledgeAnswer($question, $role, true) : null;
        if ($knowledge !== null) {
            return self::knowledgeResponse($chatId, $knowledge);
        }

        // 5. Pages and settings whose names match (e.g. "pjsip", "codec"): shortcuts, before the model.
        $settings = self::settingsMatches($question, $user);
        if ($settings !== []) {
            $text = t('assistant.nav_intro');
            $payload = ['matches' => $settings];
            $msgId = self::addMessage($chatId, 'assistant', $text, 'settings', null, $payload);
            return [
                'chat_id' => $chatId,
                'message' => [
                    'id' => $msgId,
                    'role' => 'assistant',
                    'kind' => 'settings',
                    'text' => $text,
                    'plan_id' => null,
                    'payload' => $payload,
                ],
            ];
        }

        // 6. Model fallback with last 6 messages as context.
        $askFn = $modelAsk ?? 'AssistantModel::ask';
        $history = self::lastMessages($chatId, 6, $userMsgId);
        $prompt = self::buildModelPrompt($question, $context, $history);
        $reply = null;
        try {
            $reply = $askFn($prompt);
        } catch (\Throwable $e) {
            $reply = null;
        }
        $modelPlan = AssistantPlanner::fromModelReply($reply, $context);
        if ($modelPlan !== null && !empty($modelPlan['ok'])) {
            return self::finishPlanResponse($chatId, $question, $modelPlan, $user);
        }

        // 7. Knowledge base: an answer only from the documents this role may read, with its sources.
        $knowledge = self::knowledgeAnswer($question, $role);
        if ($knowledge !== null) {
            return self::knowledgeResponse($chatId, $knowledge);
        }

        // 8. The model's short text answer (a greeting, a question no action covers).
        $replyText = AssistantPlanner::replyText($reply);
        if ($replyText !== null) {
            $msgId = self::addMessage($chatId, 'assistant', $replyText, 'text');
            return [
                'chat_id' => $chatId,
                'message' => [
                    'id' => $msgId,
                    'role' => 'assistant',
                    'kind' => 'text',
                    'text' => $replyText,
                    'plan_id' => null,
                    'payload' => null,
                ],
            ];
        }

        // 9. Honest unknown: never invent an answer.
        $askable = EchoReporter::askable();
        $unknownText = t('assistant.unknown_intro');
        $payload = ['askable' => $askable];
        $msgId = self::addMessage($chatId, 'assistant', $unknownText, 'unknown', null, $payload);
        return [
            'chat_id' => $chatId,
            'message' => [
                'id' => $msgId,
                'role' => 'assistant',
                'kind' => 'unknown',
                'text' => $unknownText,
                'plan_id' => null,
                'payload' => $payload,
            ],
        ];
    }

    /**
     * A grounded answer from the knowledge base (ai-knowledge plugin), or null:
     * plugin off, no model, nothing found in the collections the role may read.
     * @return array{text: string, payload: array{sources: list<array{title: string, collection: string}>}}|null
     */
    public static function knowledgeAnswer(string $question, string $role, bool $directOnly = false): ?array
    {
        if (!PluginManager::isEnabled('ai-knowledge')) {
            return null;
        }
        try {
            AiKnowledgeService::ensureBuiltIn();
            $roleKb = class_exists('AssistantSettingsService') ? AssistantSettingsService::roleCollection($role) : 0;
            if ($roleKb === -1) {
                return null;
            }
            if ($roleKb > 0) {
                $collections = [$roleKb];
                $searchRole = 'admin';
            } else {
                $collections = AiKnowledgeService::assistantCollections(function_exists('getUserLanguage') ? (string) getUserLanguage() : 'en');
                $searchRole = $role;
            }
            if ($collections === []) {
                return null;
            }
            if ($directOnly) {
                $res = AiKnowledgeService::directAnswer(AiKnowledgeService::search($question, $searchRole, $collections, 3, AiKnowledgeService::MIN_SCORE, 'assistant'));
            } else {
                $selection = AssistantModel::resolvedMode();
                if (!str_contains($selection, ':') && AiKnowledgeService::$chatter === null) {
                    return null;
                }
                $res = AiKnowledgeService::askWithContext($question, $searchRole, $collections, $selection, 'assistant');
            }
        } catch (\Throwable) {
            return null;
        }
        if ($res === null) {
            return null;
        }
        if (!$res['grounded'] || $res['answer'] === '') {
            return null;
        }
        $sources = [];
        foreach ($res['sources'] as $hit) {
            $sources[$hit['doc_id']] = ['title' => (string) $hit['doc_title'], 'collection' => (string) $hit['collection_name']];
        }
        return ['text' => mb_substr($res['answer'], 0, 2000), 'payload' => ['sources' => array_values($sources)]];
    }

    /**
     * Settings-search matches for a short query (a page, menu or setting name), at most six.
     * @param array<string, mixed> $user
     * @return list<array<string, mixed>>
     */
    public static function settingsMatches(string $question, array $user): array
    {
        $q = trim($question);
        if ($q === '' || mb_strlen($q) > 60 || count(preg_split('/\s+/u', $q) ?: []) > 4) {
            return [];
        }
        try {
            require_once dirname(__DIR__) . '/SettingsSearchService.php';
            return array_values(SettingsSearchService::search($q, (string) $user['role'], $user, 6));
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array{text: string, payload: array<string, mixed>} $knowledge */
    private static function knowledgeResponse(int $chatId, array $knowledge): array
    {
        $msgId = self::addMessage($chatId, 'assistant', $knowledge['text'], 'knowledge', null, $knowledge['payload']);
        return [
            'chat_id' => $chatId,
            'message' => [
                'id' => $msgId,
                'role' => 'assistant',
                'kind' => 'knowledge',
                'text' => $knowledge['text'],
                'plan_id' => null,
                'payload' => $knowledge['payload'],
            ],
        ];
    }

    private static function finishPlanResponse(int $chatId, string $question, array $plan, array $user): array
    {
        if (empty($plan['ok'])) {
            $errText = t('assistant.err_generic');
            if (isset($plan['message_key'])) {
                $errText = t((string) $plan['message_key']);
                try {
                    $errText = vsprintf($errText, array_map('strval', array_values((array) ($plan['message_params'] ?? []))));
                } catch (\ValueError) {
                    // fewer values than the text expects: the text as it is
                }
            }
            $msgId = self::addMessage($chatId, 'assistant', $errText, 'error');
            return [
                'chat_id' => $chatId,
                'message' => [
                    'id' => $msgId,
                    'role' => 'assistant',
                    'kind' => 'error',
                    'text' => $errText,
                    'plan_id' => null,
                    'payload' => null,
                ],
            ];
        }

        $scopeError = self::validateScope($plan, $user);
        if ($scopeError !== null) {
            $msgId = self::addMessage($chatId, 'assistant', $scopeError, 'error');
            return [
                'chat_id' => $chatId,
                'message' => [
                    'id' => $msgId,
                    'role' => 'assistant',
                    'kind' => 'error',
                    'text' => $scopeError,
                    'plan_id' => null,
                    'payload' => null,
                ],
            ];
        }

        $title = !empty($plan['title_key']) ? t((string) $plan['title_key']) : t('assistant.preview_title');
        $payload = [
            'plan' => $plan,
            'request' => mb_substr($question, 0, 300),
        ];
        $msgId = self::addMessage($chatId, 'assistant', $title, 'plan', null, $payload);
        return [
            'chat_id' => $chatId,
            'message' => [
                'id' => $msgId,
                'role' => 'assistant',
                'kind' => 'plan',
                'text' => $title,
                'plan_id' => null,
                'payload' => $payload,
            ],
        ];
    }

    private static function buildModelPrompt(string $question, array $context, array $history): string
    {
        require_once __DIR__ . '/CloudAiService.php';
        $base = AssistantPlanner::prompt($question, $context, CloudAiService::languageName((string) getUserLanguage()));
        if (!$history) {
            return $base;
        }
        $lines = [];
        foreach ($history as $h) {
            $r = (string) ($h['role'] ?? 'user');
            $t = mb_substr((string) ($h['text'] ?? ''), 0, 200);
            $lines[] = "{$r}: {$t}";
        }
        return "Recent conversation (oldest first):\n" . implode("\n", $lines) . "\n\n" . $base;
    }
}
