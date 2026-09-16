<?php
require_once __DIR__ . '/../services/LoginService.php';

class LoginController extends BaseController
{
    public static function index(): void
    {
        $error = '';
        $client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        // Generate Math Captcha if not present
        if (empty($_SESSION['captcha_num1']) || empty($_SESSION['captcha_num2'])) {
            $_SESSION['captcha_num1'] = rand(1, 9);
            $_SESSION['captcha_num2'] = rand(1, 9);
        }

        if (static::isPost()) {
            $res = LoginService::attemptLogin($_POST, $client_ip);
            if (isset($res['redirect'])) {
                static::redirect($res['redirect']);
            }
            $error = $res['error'] ?? '';

            // Regenerate Captcha on failed attempt
            $_SESSION['captcha_num1'] = rand(1, 9);
            $_SESSION['captcha_num2'] = rand(1, 9);
        }

        $num1 = $_SESSION['captcha_num1'];
        $num2 = $_SESSION['captcha_num2'];
        $csrf_token = getCSRFToken();

        $site_title = getSystemSetting('site_title', 'AiPBX');
        $brand_title = getSystemSetting('brand_title', 'AiPBX');
        $brand_sub = getSystemSetting('brand_sub', 'Santral & Çağrı Merkezi');
        $site_logo_type = getSystemSetting('site_logo_type', 'image');
        $site_logo_icon = getSystemSetting('site_logo_icon', 'fa-network-wired');
        $site_logo_image = getSystemSetting('site_logo_image', BRAND_DEFAULT_LOGO_URL);
        $site_favicon_url = getSystemSetting('site_favicon_url', '');

        static::render('login/index', [
            'error' => $error,
            'num1' => $num1,
            'num2' => $num2,
            'csrf_token' => $csrf_token,
            'site_title' => $site_title,
            'brand_title' => $brand_title,
            'brand_sub' => $brand_sub,
            'site_logo_type' => $site_logo_type,
            'site_logo_icon' => $site_logo_icon,
            'site_logo_image' => $site_logo_image,
            'site_favicon_url' => $site_favicon_url,
        ]);
    }
}
