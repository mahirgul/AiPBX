<?php
require_once __DIR__ . '/../services/ai/CloudAiService.php';
require_once __DIR__ . '/../services/LocalAiService.php';

/**
 * /ai-cloud — AI → Cloud services (admin only): provider accounts with what
 * they can do, a connection test and this month's use, and the engine (local
 * model or cloud provider) chosen for each job and language.
 */
class AiCloudController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');
        $message = '';
        $error = '';
        if (static::isPost()) {
            if (!static::verifyCsrf()) {
                $error = t('common.invalid_csrf');
            } else {
                try {
                    if (isset($_POST['save_provider'])) {
                        $post = $_POST;
                        $up = $_FILES['service_account_file'] ?? null;
                        if ($up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && $up['size'] < 65536 && is_uploaded_file($up['tmp_name'])) {
                            $post['service_account'] = (string) file_get_contents($up['tmp_name']);
                        }
                        CloudAiService::save((string) $_POST['save_provider'], $post);
                        $message = t(!empty($_POST['clear']) ? 'ai_tts.msg_provider_cleared' : 'ai_tts.msg_provider_saved');
                    } elseif (isset($_POST['save_engines'])) {
                        CloudAiService::saveEngines($_POST);
                        $message = t('ai_cloud.msg_engines_saved');
                    }
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }

        $engines = [];
        foreach (CloudAiService::jobs() as $j) {
            $engines[] = $j + ['value' => CloudAiService::engine($j['job'], $j['lang'])];
        }
        static::renderPage('ai_cloud/index', [
            'providers' => CloudAiService::forPage(),
            'engines' => $engines,
            'local_models' => LocalAiService::models(),
            'csrf_token' => getCSRFToken(),
        ], ['title' => t('ai_cloud.title'), 'message' => $message, 'error' => $error]);
    }
}
