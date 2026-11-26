<?php
require_once __DIR__ . '/../services/ai/CloudAiService.php';
require_once __DIR__ . '/../services/ai/AiBindingService.php';
require_once __DIR__ . '/../asterisk_sync.php';

/**
 * /ai-cloud — AI -> Cloud AI (admin / ai_cloud RBAC):
 * Manages cloud provider accounts (one provider + one settings set each), usage limits.
 */
class AiCloudController extends BaseController
{
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
                    } elseif (isset($_POST['copy_account'])) {
                        CloudAiService::copyAccount((string) $_POST['copy_account']);
                        $message = t('ai_cloud.msg_account_added');
                    } elseif (isset($_POST['toggle_account'])) {
                        CloudAiService::setEnabled((string) $_POST['toggle_account'], !empty($_POST['enable']));
                        $message = t('ai_tts.msg_provider_saved');
                    } elseif (isset($_POST['delete_account'])) {
                        if (!hasModulePermission('ai_cloud', 'delete') && !userHasRole('admin')) {
                            throw new \RuntimeException(t('auth.no_delete_module'));
                        }
                        CloudAiService::deleteAccount((string) $_POST['delete_account']);
                        $message = t('ai_cloud.msg_account_deleted');
                    }
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }

        static::renderPage('ai_cloud/index', [
            'plugin_enabled'  => $pluginEnabled,
            'privacy_blocked' => $privacyBlocked,
            'providers'       => CloudAiService::forPage(),
            'types'           => CloudAiService::typesForPage(),
            'dependencies'    => AiBindingService::dependenciesForScope('cloud'),
            'csrf_token'      => getCSRFToken(),
        ], ['title' => t('ai_cloud.title', 'Cloud AI'), 'message' => $message, 'error' => $error]);
    }
}
