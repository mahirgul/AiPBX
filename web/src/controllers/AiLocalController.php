<?php
require_once __DIR__ . '/../services/ai/CloudAiService.php';
require_once __DIR__ . '/../services/ai/AiBindingService.php';
require_once __DIR__ . '/../services/ai/AiGateway.php';
require_once __DIR__ . '/../services/ai/OllamaService.php';
require_once __DIR__ . '/../services/LocalAiService.php';

/**
 * /ai-local — AI -> Local AI (admin / ai_local RBAC):
 * Manages local HF/Whisper/Piper/Ollama models, local default bindings, and local chat testing.
 */
class AiLocalController extends BaseController
{
    public const TABS = ['ollama', 'huggingface', 'connections', 'languages', 'try'];

    public static function index(): void
    {
        static::requireLogin();
        if (!hasModulePermission('ai_local', 'view') && !userHasRole('admin')) {
            static::requireRole('admin');
        }

        $pluginEnabled = PluginManager::isEnabled('ailocal');
        $message = '';
        $error = '';
        $tryOutput = '';

        $languages = CloudAiService::languages();

        if (static::isPost()) {
            if (!static::verifyCsrf()) {
                $error = t('common.invalid_csrf');
            } elseif (!$pluginEnabled) {
                $error = t('ai_gateway.error_permission');
            } else {
                try {
                    if (isset($_POST['save_local_defaults'])) {
                        foreach (['chat', 'stt', 'tts'] as $task) {
                            $ref = trim((string) ($_POST['model_' . $task] ?? ''));
                            if ($ref !== '' && !str_starts_with($ref, 'local:')) {
                                $ref = '';
                            }
                            $mode = $ref !== '' ? 'explicit' : 'off';
                            AiBindingService::saveBinding('panel_default', $task, $mode, $ref !== '' ? $ref : null, 'default', 'local');
                        }
                        $message = t('ai_cloud.msg_engines_saved');
                    } elseif (isset($_POST['save_local_languages'])) {
                        foreach (['chat', 'stt', 'tts'] as $task) {
                            foreach (array_keys($languages) as $langCode) {
                                $lRef = trim((string) ($_POST['lang_model'][$langCode][$task] ?? ''));
                                if ($lRef !== '' && !str_starts_with($lRef, 'local:')) {
                                    $lRef = '';
                                }
                                AiBindingService::saveBinding('panel_default', $task, $lRef !== '' ? 'explicit' : 'off', $lRef !== '' ? $lRef : null, (string) $langCode, 'local');
                            }
                        }
                        $message = t('ai_cloud.msg_engines_saved');
                    } elseif (isset($_POST['add_language'])) {
                        CloudAiService::addLanguage($_POST);
                        $languages = CloudAiService::languages();
                        $message = t('ai_cloud.msg_lang_added', 'Language added.');
                    } elseif (isset($_POST['delete_language'])) {
                        CloudAiService::deleteLanguage((string) $_POST['delete_language']);
                        $languages = CloudAiService::languages();
                        $message = t('ai_cloud.msg_lang_deleted', 'Language deleted.');
                    } elseif (isset($_POST['save_hf_token'])) {
                        LocalAiService::saveHfToken(!empty($_POST['clear_hf_token']) ? '' : (string) ($_POST['hf_token'] ?? ''));
                        $message = t('ai_tts.msg_provider_saved');
                    } elseif (isset($_POST['try_local_chat'])) {
                        $sel = trim((string) ($_POST['try_model'] ?? ''));
                        $prompt = trim((string) ($_POST['try_prompt'] ?? ''));
                        $tryOutput = AiGateway::chat($sel, $prompt, 'panel', 200);
                    }
                } catch (AiGatewayException $e) {
                    $error = $e->userMessage();
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }

        $activeTab = (string) ($_POST['_tab'] ?? $_GET['tab'] ?? 'ollama');
        if (!in_array($activeTab, self::TABS, true)) {
            $activeTab = 'ollama';
        }

        $localModels = array_merge(LocalAiService::models(), OllamaService::models());
        $bindings = [
            'chat' => AiBindingService::getBinding('panel_default', 'chat', 'default', 'local'),
            'stt'  => AiBindingService::getBinding('panel_default', 'stt', 'default', 'local'),
            'tts'  => AiBindingService::getBinding('panel_default', 'tts', 'default', 'local'),
        ];
        $langBindings = [];
        foreach (array_keys($languages) as $langCode) {
            foreach (['chat', 'stt', 'tts'] as $task) {
                $langBindings[$langCode][$task] = AiBindingService::getBinding('panel_default', $task, (string) $langCode, 'local');
            }
        }

        static::renderPage('ai_local/index', [
            'plugin_enabled' => $pluginEnabled,
            'active_tab'     => $activeTab,
            'local_models'   => $localModels,
            'status'         => LocalAiService::status(),
            'bindings'       => $bindings,
            'languages'      => $languages,
            'lang_bindings'  => $langBindings,
            'dependencies'   => AiBindingService::dependenciesForScope('local'),
            'try_output'     => $tryOutput,
            'csrf_token'     => getCSRFToken(),
        ], ['title' => t('ai_local.title', 'Local AI'), 'message' => $message, 'error' => $error]);
    }
}
