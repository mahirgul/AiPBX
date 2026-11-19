<?php
require_once __DIR__ . '/../services/CdrAiService.php';
require_once __DIR__ . '/../services/LocalAiService.php';
require_once __DIR__ . '/../services/ai/CloudAiService.php';
require_once __DIR__ . '/../services/ai/AiBindingService.php';
require_once __DIR__ . '/../services/ai/AiJobService.php';
require_once __DIR__ . '/../services/ai/OllamaService.php';

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
                        $sttMode = (string) ($_POST['stt_mode'] ?? 'off');
                        $sttRef = trim((string) ($_POST['stt_model_ref'] ?? ''));
                        AiBindingService::saveBinding(
                            'ai_call_analysis',
                            'stt',
                            $sttMode,
                            $sttMode === 'explicit' ? $sttRef : null,
                            'default',
                            'global'
                        );

                        $chatMode = (string) ($_POST['chat_mode'] ?? 'off');
                        $chatRef = trim((string) ($_POST['chat_model_ref'] ?? ''));
                        AiBindingService::saveBinding(
                            'ai_call_analysis',
                            'chat',
                            $chatMode,
                            $chatMode === 'explicit' ? $chatRef : null,
                            'default',
                            'global'
                        );

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

        $sttOptions = [];
        $chatOptions = [];
        try {
            foreach (LocalAiService::models() as $m) {
                $kind = (string) ($m['kind'] ?? '');
                $state = (string) ($m['state'] ?? '');
                if (!in_array($state, ['ready', 'installed'], true)) {
                    continue;
                }
                if ($kind === 'stt') {
                    $sttOptions[] = ['ref' => 'local:' . $m['id'], 'label' => 'Local · ' . ($m['title'] ?? $m['id'])];
                } elseif ($kind === 'llm') {
                    $chatOptions[] = ['ref' => 'local:' . $m['id'], 'label' => 'Local · ' . ($m['title'] ?? $m['id'])];
                }
            }
            foreach (OllamaService::models() as $m) {
                $state = (string) ($m['state'] ?? '');
                if (($m['kind'] ?? '') === 'llm' && in_array($state, ['ready', 'installed'], true)) {
                    $chatOptions[] = ['ref' => 'local:' . $m['id'], 'label' => 'Local · ' . ($m['title'] ?? $m['id'])];
                }
            }
        } catch (\Throwable $e) {
        }

        foreach (CloudAiService::withCap('stt') as $pid) {
            if (!CloudAiService::configured($pid)) {
                continue;
            }
            $sttOptions[] = ['ref' => 'cloud:' . $pid, 'label' => 'Cloud · ' . CloudAiService::provider($pid)['title']];
        }
        foreach (CloudAiService::withCap('llm') as $pid) {
            if (!CloudAiService::configured($pid)) {
                continue;
            }
            $chatOptions[] = ['ref' => 'cloud:' . $pid, 'label' => 'Cloud · ' . CloudAiService::provider($pid)['title']];
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
            'stt_binding'    => AiBindingService::getBinding('ai_call_analysis', 'stt', 'default', 'global'),
            'chat_binding'   => AiBindingService::getBinding('ai_call_analysis', 'chat', 'default', 'global'),
            'stt_options'    => $sttOptions,
            'chat_options'   => $chatOptions,
            'qa_settings'    => CdrAiService::qaSettings(),
            'jobs'           => $jobs,
            'csrf_token'     => getCSRFToken(),
        ], ['title' => t('ai_call_analysis.title', 'Call Analysis'), 'message' => $message, 'error' => $error]);
    }
}
