<?php
require_once __DIR__ . '/../services/AiTtsService.php';
require_once __DIR__ . '/../services/ai/AiBindingService.php';

/**
 * /ai-tts — AI → Cloud TTS: synthesize text with a cloud provider, keep the
 * MP3, save it as an announcement; provider accounts are on AI → Cloud services.
 * Synthesis, voices and audio go through /api/ai_tts.php (AJAX).
 */
class AiTtsController extends BaseController
{
    public static function index(): void
    {
        requireLogin();
        static::requireModule('ai_tts', 'view');

        $message = '';
        $error = '';

        if (static::isPost()) {
            if (!static::verifyCsrf()) {
                $error = t('common.invalid_csrf');
            } else {
                try {
                    if (isset($_POST['delete_tts_id'])) {
                        if (!hasModulePermission('ai_tts', 'delete')) {
                            throw new \RuntimeException(t('auth.no_delete_module'));
                        }
                        AiTtsService::delete((int) $_POST['delete_tts_id']);
                        $message = t('ai_tts.msg_deleted');
                    }
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }

        static::renderPage('ai_tts/index', [
            'providers' => AiTtsService::providersForPage(),
            'history' => AiTtsService::history(100),
            'csrf_token' => getCSRFToken(),
            'can_edit' => hasModulePermission('ai_tts', 'edit'),
            'can_delete' => hasModulePermission('ai_tts', 'delete'),
            'max_text' => AiTtsService::MAX_TEXT,
        ], ['title' => t('ai_tts.title'), 'message' => $message, 'error' => $error]);
    }
}
