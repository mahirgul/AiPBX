<?php

require_once __DIR__ . '/../services/ai/BuiltinAiAgentService.php';
require_once __DIR__ . '/../services/LocalAiService.php';
require_once __DIR__ . '/../services/ExternalDbQueryService.php';

final class BuiltinAiAgentController extends BaseController
{
    /** /ai-wizard: the agent's setup as a checklist; every step links to the page that does it. */
    public static function wizard(): void
    {
        static::requireRole('admin');
        $token = (string) ($_POST['csrf_token'] ?? '');
        $notices = ['message' => '', 'error' => ''];
        $slug = (string) ($_POST['enable_plugin'] ?? '');
        if ($slug !== '' && in_array($slug, BuiltinAiAgentService::WIZARD_PLUGINS, true)) {
            $res = PluginManager::enable($slug, $token);
            $notices[!empty($res['success']) ? 'message' : 'error'] = (string) ($res['message'] ?? $res['error'] ?? '');
            PluginManager::resetCache();
        }
        static::renderPage('builtin_ai_agent/wizard', [
            'steps' => BuiltinAiAgentService::wizardSteps(), 'csrf_token' => getCSRFToken(),
            'plugins' => array_combine(BuiltinAiAgentService::WIZARD_PLUGINS, array_map([PluginManager::class, 'isEnabled'], BuiltinAiAgentService::WIZARD_PLUGINS)),
        ], ['title' => t('builtin_ai_agent.wizard_title')] + $notices);
    }

    public static function index(): void
    {
        static::requireRole('admin');
        if (($_GET['export'] ?? '') === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="ai-agent-calls-' . date('Ymd') . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");                       // BOM: spreadsheets read UTF-8
            foreach (BuiltinAiAgentService::callsCsv() as $row) {
                fputcsv($out, $row, ',', '"', '');
            }
            fclose($out);
            exit;
        }
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
            'stats' => $edit === null ? BuiltinAiAgentService::stats() : [],
            'ai_calls' => $edit === null ? LocalAiService::callLog(30) : [],
        ], ['title' => t('builtin_ai_agent.title')] + $notices);
    }
}
