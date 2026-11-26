<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/VoicemailAiService.php';
require_once __DIR__ . '/../services/LocalAiService.php';
require_once __DIR__ . '/../services/ai/CloudAiService.php';
require_once __DIR__ . '/../services/ai/AiBindingService.php';
require_once __DIR__ . '/../services/ai/AiJobService.php';

/**
 * /ai-voicemail — AI -> Voicemail AI (admin / ai_voicemail RBAC):
 * Configures voicemail transcription & one-sentence summary, AiBindingService
 * model choices (one per task, or off), and monitors ai_jobs.
 */
class AiVoicemailController extends BaseController
{
    public const TABS = ['settings', 'history'];

    public static function index(): void
    {
        static::requireLogin();
        if (!hasModulePermission('ai_voicemail', 'view') && !userHasRole('admin')) {
            static::requireRole('admin');
        }

        $pluginEnabled = VoicemailAiService::pluginEnabled();
        $canEdit = hasModulePermission('ai_voicemail', 'edit') || userHasRole('admin');
        $message = '';
        $error = '';

        if (static::isPost()) {
            if (!static::verifyCsrf()) {
                $error = t('common.invalid_csrf');
            } elseif (!$canEdit) {
                $error = t('ai_gateway.error_permission', 'Permission denied.');
            } elseif (!$pluginEnabled) {
                $error = t('ai_gateway.error_permission', 'Plugin is disabled.');
            } else {
                try {
                    if (isset($_POST['save_voicemail_ai'])) {
                        VoicemailAiService::save($_POST);
                        foreach (['stt', 'chat'] as $task) {
                            AiBindingService::save('ai_voicemail', $task, (string) ($_POST[$task . '_model_ref'] ?? ''));
                        }
                        $message = t('ai_cloud.msg_engines_saved', 'Settings saved.');
                    } elseif (isset($_POST['cancel_job_id'])) {
                        $jobId = (int) $_POST['cancel_job_id'];
                        if ($jobId > 0 && AiJobService::cancel($jobId, 'ai_voicemail')) {
                            $message = t('ai_jobs.msg_cancelled', 'Job cancelled.');
                        }
                    }
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }

        $activeTab = (string) ($_POST['_tab'] ?? $_GET['tab'] ?? 'settings');
        if (!in_array($activeTab, self::TABS, true)) {
            $activeTab = 'settings';
        }

        $jobs = [];
        try {
            $jobs = VoicemailAiService::history(100);
        } catch (\Throwable $e) {
            $jobs = [];
        }

        static::renderPage('ai_voicemail/index', [
            'plugin_enabled' => $pluginEnabled,
            'can_edit'       => $canEdit,
            'active_tab'     => $activeTab,
            'transcribe_on'  => getSystemSetting('ai_vm_transcribe', '0') === '1',
            'summary_on'     => getSystemSetting('ai_vm_summary', '0') === '1',
            'history'        => $jobs,
            'summary_lang'   => (string) getSystemSetting('ai_vm_summary_lang', ''),
            'local_enabled'  => class_exists('PluginManager') ? PluginManager::isEnabled('ailocal') : true,
            'cloud_enabled'  => class_exists('PluginManager') ? PluginManager::isEnabled('aicloud') : true,
            'csrf_token'     => getCSRFToken(),
        ], ['title' => t('ai_voicemail.title', 'Voicemail AI'), 'message' => $message, 'error' => $error]);
    }
}
