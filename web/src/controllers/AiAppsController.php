<?php
require_once __DIR__ . '/../services/ai/AiAppService.php';

use PBX\Destinations\DestinationRegistry;

/**
 * /ai-apps — AI → Applications (admin only: an application can call a lookup
 * URL from the server). Each has an internal number and a destination after
 * it; changes take effect with "Apply" like other PBX settings.
 */
class AiAppsController extends BaseController
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
                    if (isset($_POST['delete_app'])) {
                        AiAppService::delete((int) $_POST['delete_app']);
                        $message = t('ai_apps.msg_deleted');
                    } else {
                        AiAppService::save($_POST);
                        $message = t('ai_apps.msg_saved');
                    }
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }
        static::renderPage('ai_apps/index', [
            'apps' => AiAppService::all(),
            'voices' => AiAppService::voiceModels(),
            'modules' => DestinationRegistry::getModuleList(),
            'service_ok' => LocalAiService::health() !== null,
            'csrf_token' => getCSRFToken(),
        ], ['title' => t('ai_apps.title'), 'message' => $message, 'error' => $error]);
    }
}
