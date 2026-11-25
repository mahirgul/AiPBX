<?php
require_once __DIR__ . '/../services/ai/CloudAiService.php';
require_once __DIR__ . '/../services/ai/AiBindingService.php';
require_once __DIR__ . '/../services/ai/AiGateway.php';
require_once __DIR__ . '/../asterisk_sync.php';

/**
 * /ai-cloud — AI -> Cloud AI (admin / ai_cloud RBAC):
 * Manages cloud provider accounts, cloud default bindings, usage limits, and cloud chat testing.
 */
class AiCloudController extends BaseController
{
    public const TABS = ['models', 'connections', 'languages', 'try'];

    public static function index(): void
    {
        static::requireLogin();
        if (!hasModulePermission('ai_cloud', 'view') && !userHasRole('admin')) {
            static::requireRole('admin');
        }

        $pluginEnabled = PluginManager::isEnabled('aicloud');
        $privacyBlocked = getSystemSetting('privacy_block_cloud_ai', '0') === '1';
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
                    if (isset($_POST['save_account'])) {
                        // Add (no id) or edit one account from the dialog.
                        $post = $_POST;
                        $up = $_FILES['service_account_file'] ?? null;
                        if ($up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && $up['size'] < 65536 && is_uploaded_file($up['tmp_name'])) {
                            $post['service_account'] = (string) file_get_contents($up['tmp_name']);
                        }
                        $id = (string) ($_POST['account_id'] ?? '');
                        $name = (string) ($_POST['account_name'] ?? '');
                        if ($id === '') {
                            CloudAiService::addAccount((string) ($_POST['account_type'] ?? ''), $name, $post);
                            $message = t('ai_cloud.msg_account_added');
                        } else {
                            CloudAiService::save($id, $post);
                            CloudAiService::renameAccount($id, $name);
                            if (isset(CloudAiService::REALTIME_MODELS[CloudAiService::typeOf($id)])) {
                                markPendingSync('ai_apps', 'ai_cloud_provider', $id, CloudAiService::label($id), 'update', $_SESSION['user_id'] ?? null);
                            }
                            $message = t('ai_tts.msg_provider_saved');
                        }
                    } elseif (isset($_POST['toggle_account'])) {
                        CloudAiService::setEnabled((string) $_POST['toggle_account'], !empty($_POST['enable']));
                        $message = t('ai_tts.msg_provider_saved');
                    } elseif (isset($_POST['delete_account'])) {
                        if (!hasModulePermission('ai_cloud', 'delete') && !userHasRole('admin')) {
                            throw new \RuntimeException(t('auth.no_delete_module'));
                        }
                        CloudAiService::deleteAccount((string) $_POST['delete_account']);
                        $message = t('ai_cloud.msg_account_deleted');
                    } elseif (isset($_POST['save_cloud_defaults'])) {
                        foreach (['chat', 'stt', 'tts'] as $task) {
                            $ref = trim((string) ($_POST['model_' . $task] ?? ''));
                            if ($ref !== '' && !str_starts_with($ref, 'cloud:')) {
                                $ref = '';
                            }
                            $mode = $ref !== '' ? 'explicit' : 'off';
                            AiBindingService::saveBinding('panel_default', $task, $mode, $ref !== '' ? $ref : null, 'default', 'cloud');
                        }
                        $message = t('ai_cloud.msg_engines_saved');
                    } elseif (isset($_POST['save_cloud_languages'])) {
                        foreach (['chat', 'stt', 'tts'] as $task) {
                            foreach (array_keys($languages) as $langCode) {
                                $lRef = trim((string) ($_POST['lang_model'][$langCode][$task] ?? ''));
                                if ($lRef !== '' && !str_starts_with($lRef, 'cloud:')) {
                                    $lRef = '';
                                }
                                AiBindingService::saveBinding('panel_default', $task, $lRef !== '' ? 'explicit' : 'off', $lRef !== '' ? $lRef : null, (string) $langCode, 'cloud');
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
                    } elseif (isset($_POST['try_cloud_chat'])) {
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

        $activeTab = (string) ($_POST['_tab'] ?? $_GET['tab'] ?? 'connections');
        if (!in_array($activeTab, self::TABS, true)) {
            $activeTab = 'connections';
        }

        $bindings = [
            'chat' => AiBindingService::getBinding('panel_default', 'chat', 'default', 'cloud'),
            'stt'  => AiBindingService::getBinding('panel_default', 'stt', 'default', 'cloud'),
            'tts'  => AiBindingService::getBinding('panel_default', 'tts', 'default', 'cloud'),
        ];
        $langBindings = [];
        foreach (array_keys($languages) as $langCode) {
            foreach (['chat', 'stt', 'tts'] as $task) {
                $langBindings[$langCode][$task] = AiBindingService::getBinding('panel_default', $task, (string) $langCode, 'cloud');
            }
        }

        static::renderPage('ai_cloud/index', [
            'plugin_enabled'  => $pluginEnabled,
            'privacy_blocked' => $privacyBlocked,
            'active_tab'      => $activeTab,
            'providers'       => CloudAiService::forPage(),
            'types'           => CloudAiService::typesForPage(),
            'bindings'        => $bindings,
            'languages'       => $languages,
            'lang_bindings'   => $langBindings,
            'dependencies'    => AiBindingService::dependenciesForScope('cloud'),
            'try_output'      => $tryOutput,
            'csrf_token'      => getCSRFToken(),
        ], ['title' => t('ai_cloud.title', 'Cloud AI'), 'message' => $message, 'error' => $error]);
    }
}
