<?php
require_once __DIR__ . '/../services/ai/AiAppService.php';
require_once __DIR__ . '/../../modules/destinations/DestinationRegistry.php';

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
        $localModels = LocalAiService::models();
        static::renderPage('ai_apps/index', [
            'apps' => AiAppService::all(),
            'voices' => AiAppService::voiceModels(),
            'stt_models' => array_values(array_filter($localModels, fn($m) => ($m['kind'] ?? '') === 'stt'
                && in_array($m['state'] ?? '', ['ready', 'installed', 'loading'], true))),
            // A running sentence-meaning model: example sentences are compared by meaning.
            'meaning_ready' => array_filter($localModels, fn($m) => ($m['kind'] ?? '') === 'embedding' && ($m['state'] ?? '') === 'ready') !== [],
            'requests' => AiAppService::requests(100),
            'cloud_llm' => array_map(fn($id) => ['id' => $id, 'title' => CloudAiService::provider($id)['title']], CloudAiService::withCap('llm')),
            'cloud_stt' => array_map(fn($id) => ['id' => $id, 'title' => CloudAiService::provider($id)['title']], CloudAiService::withCap('stt')),
            'modules' => DestinationRegistry::getModuleList(),
            'service_ok' => LocalAiService::health() !== null,
            'csrf_token' => getCSRFToken(),
        ], ['title' => t('ai_apps.title'), 'message' => $message, 'error' => $error]);
    }
}
