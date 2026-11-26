<?php
require_once __DIR__ . '/../services/CdrAiService.php';
require_once __DIR__ . '/../services/LocalAiService.php';
require_once __DIR__ . '/../services/ai/CloudAiService.php';
require_once __DIR__ . '/../services/ai/AiBindingService.php';
require_once __DIR__ . '/../services/ai/AiJobService.php';

/**
 * /ai-call-analysis — AI -> Call Analysis (admin / ai_call_analysis RBAC):
 * Configures STT & Chat bindings for CDR transcription, summaries, and Auto-QA criteria,
 * and shows recent `ai_jobs` status for `ai_call_analysis`.
 */
class AiCallAnalysisController extends BaseController
{
    public const TABS = ['settings', 'jobs'];

    public static function index(): void
    {
        static::requireLogin();
        if (!hasModulePermission('ai_call_analysis', 'view') && !userHasRole('admin')) {
            static::requireRole('admin');
        }

        $pluginEnabled = PluginManager::isEnabled('ai-call-analysis');
        $message = '';
        $error = '';

        $activeTab = (string) ($_POST['_tab'] ?? $_GET['tab'] ?? 'settings');
        if (!in_array($activeTab, self::TABS, true)) {
            $activeTab = 'settings';
        }

        if (static::isPost()) {
            if (!static::verifyCsrf()) {
                $error = t('common.invalid_csrf');
            } elseif (!$pluginEnabled) {
                $error = t('ai_gateway.error_permission');
            } else {
                try {
                    if (isset($_POST['save_call_analysis'])) {
                        foreach (['stt', 'chat'] as $task) {
                            AiBindingService::save('ai_call_analysis', $task, (string) ($_POST[$task . '_model_ref'] ?? ''));
                        }

                        CdrAiService::saveQaSettings($_POST);
                        $message = t('common.saved', 'Saved.');
                        $activeTab = 'settings';
                    } elseif (isset($_POST['cancel_job'])) {
                        $jobId = (int) $_POST['cancel_job'];
                        if ($jobId > 0 && AiJobService::cancel($jobId)) {
                            $message = t('ai_call_analysis.msg_job_cancelled', 'Job cancelled.');
                        }
                        $activeTab = 'jobs';
                    }
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }

        $jobs = [];
        try {
            $jobs = AiJobService::recent('ai_call_analysis', 50);
        } catch (\Throwable $e) {
            $jobs = [];
        }

        static::renderPage('ai_call_analysis/index', [
            'plugin_enabled' => $pluginEnabled,
            'active_tab'     => $activeTab,
            'qa_settings'    => CdrAiService::qaSettings(),
            'jobs'           => $jobs,
            'csrf_token'     => getCSRFToken(),
        ], ['title' => t('ai_call_analysis.title', 'Call Analysis'), 'message' => $message, 'error' => $error]);
    }
}
