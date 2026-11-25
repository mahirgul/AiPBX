<?php

require_once __DIR__ . '/../services/ai/BuiltinAiAgentService.php';
require_once __DIR__ . '/../services/ExternalDbQueryService.php';

final class BuiltinAiAgentController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');
        $token = (string) ($_POST['csrf_token'] ?? '');
        $notices = static::handlePost([
            'save_agent' => fn () => PBXHelper::handleAction($token, static function () {
                AiAppService::save(BuiltinAiAgentService::validate($_POST), true);
                return t('ai_apps.msg_saved');
            }),
            'delete_agent' => fn () => PBXHelper::handleAction($token, static function () {
                AiAppService::delete((int) ($_POST['agent_id'] ?? 0), true);
                return t('ai_apps.msg_deleted');
            }),
        ]);
        $edit = null;
        $agents = AiAppService::all(true);
        if (isset($_POST['save_agent']) && $notices['error'] !== '') {
            $edit = $_POST;
        } elseif (isset($_GET['new'])) {
            $edit = [];
        } elseif (isset($_GET['edit'])) {
            foreach ($agents as $agent) {
                if ((int) $agent['id'] === (int) $_GET['edit']) {
                    $edit = $agent + AiAppService::config($agent) + ['fallback' => $agent['dest_type'] . ':' . $agent['dest_id']];
                    break;
                }
            }
        }
        $savedQueries = array_values(array_filter(
            ExternalDbQueryService::all(),
            [ExternalDbQueryService::class, 'available']
        ));
        static::renderPage('builtin_ai_agent/index', [
            'agents' => $agents, 'edit' => $edit, 'models' => $edit !== null ? BuiltinAiAgentService::models() : [],
            'collections' => PluginManager::isEnabled('ai-knowledge') ? AiKnowledgeService::collections() : [],
            'saved_queries' => $savedQueries,
            'default_instructions' => BuiltinAiAgentService::defaultInstructionsTemplate(),
            'targets' => BuiltinAiAgentService::targets(true), 'csrf_token' => getCSRFToken(),
        ], ['title' => t('builtin_ai_agent.title')] + $notices);
    }
}
