<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/VoicemailAiService.php';
require_once __DIR__ . '/../services/LocalAiService.php';
require_once __DIR__ . '/../services/ai/CloudAiService.php';
require_once __DIR__ . '/../services/ai/AiBindingService.php';
require_once __DIR__ . '/../services/ai/AiJobService.php';
require_once __DIR__ . '/../services/ai/OllamaService.php';

/**
 * /ai-voicemail — AI -> Voicemail AI (admin / ai_voicemail RBAC):
 * Configures voicemail transcription & one-sentence summary, AiBindingService
 * selectors (local_default / cloud_default / explicit / off), and monitors ai_jobs.
 */
class AiVoicemailController extends BaseController
{
    public const TABS = ['settings', 'languages', 'jobs'];

    public static function index(): void
    {
        static::requireLogin();
        if (!hasModulePermission('ai_voicemail', 'view') && !userHasRole('admin')) {
            static::requireRole('admin');
        }

        $pluginEnabled = VoicemailAiService::pluginEnabled();
        $canEdit = hasModulePermission('ai_voicemail', 'edit') || userHasRole('admin');
        $languages = CloudAiService::languages();
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
                            $mode = trim((string) ($_POST[$task . '_mode'] ?? 'off'));
                            if (!in_array($mode, AiBindingService::MODES, true)) {
                                $mode = 'off';
                            }
                            $ref = trim((string) ($_POST[$task . '_model_ref'] ?? ''));
                            if ($mode === 'explicit' && $ref === '') {
                                $mode = 'off';
                            }
                            AiBindingService::saveBinding(
                                'ai_voicemail',
                                $task,
                                $mode,
                                $mode === 'explicit' ? $ref : null,
                                'default',
                                'global'
                            );
                        }
                        $message = t('ai_cloud.msg_engines_saved', 'Settings saved.');
                    } elseif (isset($_POST['save_voicemail_languages'])) {
                        foreach (['stt', 'chat'] as $task) {
                            foreach (array_keys($languages) as $langCode) {
                                $lMode = trim((string) ($_POST['lang_mode'][$langCode][$task] ?? 'off'));
                                $lRef = trim((string) ($_POST['lang_model'][$langCode][$task] ?? ''));
                                if (!in_array($lMode, AiBindingService::MODES, true)) {
                                    $lMode = 'off';
                                }
                                if ($lMode === 'explicit' && $lRef === '') {
                                    $lMode = 'off';
                                }
                                AiBindingService::saveBinding(
                                    'ai_voicemail',
                                    $task,
                                    $lMode,
                                    $lMode === 'explicit' ? $lRef : null,
                                    (string) $langCode,
                                    'global'
                                );
                            }
                        }
                        $message = t('ai_cloud.msg_engines_saved', 'Settings saved.');
                    } elseif (isset($_POST['cancel_job_id'])) {
                        $jobId = (int) $_POST['cancel_job_id'];
                        if ($jobId > 0 && AiJobService::cancel($jobId)) {
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

        $localModels = array_merge(LocalAiService::models(), OllamaService::models());
        $sttOptions = [];
        $chatOptions = [];
        foreach ($localModels as $m) {
            $kind = (string) ($m['kind'] ?? '');
            $id = (string) ($m['id'] ?? '');
            $state = (string) ($m['state'] ?? '');
            if ($id === '' || !in_array($state, ['ready', 'installed'], true)) {
                continue;
            }
            $title = (string) ($m['title'] ?? $id);
            if ($kind === 'stt') {
                $sttOptions[] = ['ref' => 'local:' . $id, 'label' => '[Local] ' . $title];
            } elseif ($kind === 'llm') {
                $chatOptions[] = ['ref' => 'local:' . $id, 'label' => '[Local] ' . $title];
            }
        }
        foreach (CloudAiService::withCap('stt') as $pid) {
            if (!CloudAiService::configured($pid)) {
                continue;
            }
            $sttOptions[] = ['ref' => 'cloud:' . $pid, 'label' => '[Cloud] ' . CloudAiService::provider($pid)['title']];
        }
        foreach (CloudAiService::withCap('llm') as $pid) {
            if (!CloudAiService::configured($pid)) {
                continue;
            }
            $chatOptions[] = ['ref' => 'cloud:' . $pid, 'label' => '[Cloud] ' . CloudAiService::provider($pid)['title']];
        }

        $bindings = [
            'stt'  => AiBindingService::getBinding('ai_voicemail', 'stt', 'default', 'global'),
            'chat' => AiBindingService::getBinding('ai_voicemail', 'chat', 'default', 'global'),
        ];
        $langBindings = [];
        foreach (array_keys($languages) as $langCode) {
            foreach (['stt', 'chat'] as $task) {
                $langBindings[$langCode][$task] = AiBindingService::getBinding('ai_voicemail', $task, (string) $langCode, 'global');
            }
        }

        $jobs = [];
        try {
            $jobs = AiJobService::recent('ai_voicemail', 50);
        } catch (\Throwable $e) {
            $jobs = [];
        }

        static::renderPage('ai_voicemail/index', [
            'plugin_enabled' => $pluginEnabled,
            'can_edit'       => $canEdit,
            'active_tab'     => $activeTab,
            'transcribe_on'  => getSystemSetting('ai_vm_transcribe', '0') === '1',
            'summary_on'     => getSystemSetting('ai_vm_summary', '0') === '1',
            'bindings'       => $bindings,
            'resolved_stt'   => VoicemailAiService::resolveEngine('stt', 'default', 'vm_stt'),
            'resolved_chat'  => VoicemailAiService::resolveEngine('chat', 'default', 'vm_llm'),
            'stt_options'    => $sttOptions,
            'chat_options'   => $chatOptions,
            'languages'      => $languages,
            'lang_bindings'  => $langBindings,
            'jobs'           => $jobs,
            'local_enabled'  => class_exists('PluginManager') ? PluginManager::isEnabled('ailocal') : true,
            'cloud_enabled'  => class_exists('PluginManager') ? PluginManager::isEnabled('aicloud') : true,
            'csrf_token'     => getCSRFToken(),
        ], ['title' => t('ai_voicemail.title', 'Voicemail AI'), 'message' => $message, 'error' => $error]);
    }
}
