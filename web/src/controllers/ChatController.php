<?php

require_once __DIR__ . '/../../api/mobile/auth_helper.php';
require_once __DIR__ . '/../services/ChatSettingsService.php';

class ChatController extends BaseController
{
    public static function index(): void
    {
        requireLogin();
        static::requireModule('chat', 'view');

        $user = getCurrentUser();
        $ext = trim($user['extension'] ?? '');

        if ($ext === '') {
            static::notifyError(t('chat.err_no_extension'));
            static::redirect('/dashboard');
            return;
        }

        // Chat settings: administrators only.
        $isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
        $notices = $isAdmin
            ? static::handlePost([
                'save_chat_settings' => fn() => static::verifyCsrf()
                    ? ChatSettingsService::saveDeleteWindow($_POST['chat_delete_window_minutes'] ?? '')
                    : ['success' => false, 'error' => t('common.invalid_csrf')],
            ])
            : ['message' => '', 'error' => ''];

        $token = generateMobileToken($user, 86400); // valid for 24 hours for the web chat session
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        setcookie('chat_token', $token, [
            'expires' => time() + 86400,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $isSecure,
        ]);
        $page_title = function_exists('t') ? t('sidebar.item_chat') : 'Sohbet';

        static::renderPage('chat/index', [
            'user' => $user,
            'ext' => $ext,
            'token' => $token,
            'page_title' => $page_title,
            'is_admin' => $isAdmin,
            'delete_window' => ChatSettingsService::deleteWindowMinutes(),
        ], ['title' => $page_title] + $notices);
    }
}
