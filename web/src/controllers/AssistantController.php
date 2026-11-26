<?php
require_once __DIR__ . '/../services/ai/AssistantChatService.php';
require_once __DIR__ . '/../services/ai/EchoReporter.php';
require_once __DIR__ . '/../services/SettingsSearchService.php';
require_once __DIR__ . '/../repositories/DashboardRepository.php';

if (file_exists(__DIR__ . '/../services/ai/AssistantSettingsService.php')) {
    require_once __DIR__ . '/../services/ai/AssistantSettingsService.php';
}

/**
 * /assistant — PBX Assistant welcome & chat screen.
 */
class AssistantController extends BaseController
{
    public static function index(): void
    {
        static::requireLogin();

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $role = userEffectiveRole();
        if (!AssistantChatService::canAsk($role)) {
            static::requireModule('assistant', 'view');
        }

        // The settings moved to their own page.
        if ((string) ($_GET['tab'] ?? '') === 'settings') {
            header('Location: /pbx-assistant', true, 301);
            exit;
        }
        // Switched off (an experimental plugin, off on a new installation): the switch is on its settings page.
        if (!AssistantSettingsService::isPluginEnabled()) {
            header('Location: ' . ($role === 'admin' ? '/pbx-assistant' : AssistantSettingsService::homePath($role)));
            exit;
        }
        $isAdmin = $role === 'admin';
        $message = '';
        $error = '';

        $ownExt = '';
        try {
            $st = getDB()->prepare('SELECT extension FROM sys_users WHERE id = ? LIMIT 1');
            $st->execute([$userId]);
            $ownExt = trim((string) ($st->fetchColumn() ?: ''));
        } catch (\Throwable $e) {
            $ownExt = '';
        }

        $suggestions = [];
        if (class_exists('AssistantSettingsService')) {
            $suggestions = AssistantSettingsService::suggestions();
        }
        if (!$suggestions) {
            $suggestions = [
                t('assistant.suggestion_calls_today'),
                t('assistant.suggestion_forwarding_status'),
                t('assistant.suggestion_forward_example'),
                t('assistant.suggestion_queues_where'),
            ];
        }

        $badges = [
            'active_calls' => 0,
            'registered_phones' => 0,
            'warnings' => 0,
        ];
        if ($isAdmin) {
            try {
                $stats = DashboardRepository::getStats();
                $live = DashboardRepository::getLiveStats();
                $badges['active_calls'] = (int) ($live['active_calls'] ?? 0);
                $badges['registered_phones'] = (int) ($stats['online_pjsip_count'] ?? 0);
                $offlineTrunks = max(0, (int) ($stats['trunk_count'] ?? 0) - (int) ($stats['online_trunk_count'] ?? 0));
                $badges['warnings'] = $offlineTrunks;
            } catch (\Throwable $e) {
                // Keep zero defaults if Asterisk/DB is unavailable
            }
        }

        $initialQuery = trim((string) ($_GET['q'] ?? ''));
        $settingsMatches = [];
        if ($initialQuery !== '') {
            $currentUser = getCurrentUser() ?: ['id' => $userId, 'role' => $role, 'extension' => $ownExt];
            $settingsMatches = SettingsSearchService::search($initialQuery, $role, $currentUser);
        }

        static::renderPage('assistant/index', [
            'csrf' => getCSRFToken(),
            'is_admin' => $isAdmin,
            'can_change' => AssistantChatService::canChange($role),
            'has_extension' => $ownExt !== '',
            'suggestions' => $suggestions,
            'echo_askable' => EchoReporter::askable(),
            'status_badges' => $badges,
            'show_dashboard_link' => hasModulePermission('dashboard', 'view'),
            'initial_query' => $initialQuery,
            'settings_matches' => $settingsMatches,
        ], ['title' => t('assistant.title'), 'message' => $message, 'error' => $error]);
    }

    /** PBX Assistant settings (admin): model, role permissions, history, suggestions. */
    public static function settings(): void
    {
        static::requireRole('admin');
        $message = '';
        $error = '';
        if (static::isPost() && isset($_POST['assistant_onoff'])) {
            $on = ($_POST['enabled'] ?? '') === '1';
            $csrf = (string) ($_POST['csrf_token'] ?? '');
            $res = $on ? PluginManager::enable('pbx-assistant', $csrf) : PluginManager::disable('pbx-assistant', $csrf);
            if (!empty($res['success'])) {
                PluginManager::$menuChanged = true;   // spa_router.js fetches the new sidebar
                $message = t($on ? 'ai_settings.ast_turned_on' : 'ai_settings.ast_turned_off');
            } else {
                $error = (string) ($res['error'] ?? $res['message'] ?? t('common.error'));
            }
        }
        if (static::isPost() && isset($_POST['save_assistant'])) {
            if (!static::verifyCsrf()) {
                $error = t('common.invalid_csrf');
            } else {
                try {
                    AssistantSettingsService::save($_POST);
                    if (is_array($_POST['kb_lang'] ?? null)) {
                        require_once __DIR__ . '/../services/ai/AiKnowledgeService.php';
                        AiKnowledgeService::setAssistantLanguages($_POST['kb_lang']);
                    }
                    $message = t('common.saved', 'Saved.');
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }

        require_once __DIR__ . '/../services/ai/CloudAiService.php';
        try {
            $roles = getDB()->query('SELECT role_key, role_name FROM sys_roles ORDER BY is_system DESC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $roles = [];
        }
        require_once __DIR__ . '/../services/ai/AiBindingService.php';
        $collections = [];
        if (PluginManager::isEnabled('ai-knowledge')) {
            require_once __DIR__ . '/../services/ai/AiKnowledgeService.php';
            try {
                AiKnowledgeService::ensureBuiltIn();
                $collections = AiKnowledgeService::collections();
            } catch (\Throwable $e) {
                $collections = [];
            }
        }

        static::renderPage('ai_assistant/index', [
            'knowledge_collections' => $collections,
            'assistant_on' => AssistantSettingsService::isPluginEnabled(),
            'csrf' => getCSRFToken(),
            'assistant_settings' => AssistantSettingsService::all(),
            'assistant_roles' => $roles,
        ], ['title' => t('ai_settings.ast_title', 'PBX Assistant Settings'), 'message' => $message, 'error' => $error]);
    }
}
