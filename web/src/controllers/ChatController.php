<?php

require_once __DIR__ . '/../../api/mobile/auth_helper.php';

class ChatController extends BaseController
{
    public static function index(): void
    {
        requireLogin();
        static::requireModule('dashboard', 'view');

        $user = getCurrentUser();
        $ext = trim($user['extension'] ?? '');

        if ($ext === '') {
            static::notifyError('Sohbet özelliğini kullanabilmek için kullanıcınıza bir dahili numara atanmış olmalıdır.');
            static::redirect('/dashboard');
            return;
        }

        $token = generateMobileToken($user, 86400); // Web sohbet oturumu için 24 saat geçerli
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        setcookie('chat_token', $token, [
            'expires' => time() + 86400,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $isSecure,
        ]);
        $page_title = function_exists('t') ? t('sidebar.item_chat') : 'Sohbet';

        require_once dirname(__DIR__, 2) . '/header.php';
        static::render('chat/index', [
            'user' => $user,
            'ext' => $ext,
            'token' => $token,
            'page_title' => $page_title
        ]);
        require_once dirname(__DIR__, 2) . '/footer.php';
    }
}
