<?php
require_once __DIR__ . '/../services/AiTtsService.php';

/**
 * /ai-tts — AI → Cloud TTS: synthesize text with a cloud provider, keep the
 * MP3, save it as an announcement; provider credentials on the second tab.
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
        $tab = ($_GET['tab'] ?? '') === 'providers' ? 'providers' : 'speak';

        if (static::isPost()) {
            if (!static::verifyCsrf()) {
                $error = t('common.invalid_csrf');
            } else {
                try {
                    if (isset($_POST['save_provider'])) {
                        // Keys are managed on AI → Cloud services, which is admin only.
                        if (($_SESSION['user_role'] ?? '') !== 'admin') {
                            throw new \RuntimeException(t('auth.no_edit_module'));
                        }
                        $post = $_POST;
                        // A service account key can be uploaded as its .json file instead of pasted.
                        $up = $_FILES['service_account_file'] ?? null;
                        if ($up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && $up['size'] < 65536 && is_uploaded_file($up['tmp_name'])) {
                            $post['service_account'] = (string) file_get_contents($up['tmp_name']);
                        }
                        AiTtsService::saveProvider((string) $_POST['save_provider'], $post);
                        $message = t(!empty($_POST['clear']) ? 'ai_tts.msg_provider_cleared' : 'ai_tts.msg_provider_saved');
                        $tab = 'providers';
                    } elseif (isset($_POST['delete_tts_id'])) {
                        AiTtsService::delete((int) $_POST['delete_tts_id']);
                        $message = t('ai_tts.msg_deleted');
                    }
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }

        static::renderPage('ai_tts/index', [
            'tab' => $tab,
            'providers' => AiTtsService::providersForPage(),
            'history' => AiTtsService::history(100),
            'csrf_token' => getCSRFToken(),
            'can_edit' => hasModulePermission('ai_tts', 'edit'),
            'can_delete' => hasModulePermission('ai_tts', 'delete'),
            'max_text' => AiTtsService::MAX_TEXT,
        ], ['title' => t('ai_tts.title'), 'message' => $message, 'error' => $error]);
    }
}
