<?php
require_once __DIR__ . '/../services/LoginService.php';
require_once __DIR__ . '/../services/SsoOnlyService.php';
require_once __DIR__ . '/../services/GoogleAuthService.php';
require_once __DIR__ . '/../services/OidcAuthService.php';
require_once __DIR__ . '/../services/SamlAuthService.php';

/**
 * Sign-in page (/login), public: password with a small sum captcha, Google and passkey
 * sign-in; LoginService locks out after 5 failures in 15 minutes and continues to
 * /login-2fa (two-factor) or /force-reset (password must be changed).
 * Logic: GoogleAuthService, LoginService.
 */
class LoginController extends BaseController
{
    public static function index(): void
    {
        $error = '';
        if (isset($_GET['recovery'])) {
            if (SsoOnlyService::useRecoveryToken((string) $_GET['recovery'])) {
                static::redirect('/login');
            }
            $error = t('sso_only.err_recovery');
        }
        if (SsoOnlyService::localSignInBlocked()) {
            // Not straight back to the provider after signing out or after it failed: that would never end.
            $recent = time() - (int) ($_SESSION['sso_auto_at'] ?? 0) < 60;
            if ($error === '' && !static::isPost() && !isset($_GET['signed_out']) && !$recent
                && SsoOnlyService::redirectsToProvider((string) ($_SERVER['HTTP_ACCEPT'] ?? ''))) {
                $_SESSION['sso_auto_at'] = time();
                static::redirect(SsoOnlyService::loginUrl());
            }
            static::renderAuthPage('login/sso_only', [
                'error' => $error,
                'sso_url' => SsoOnlyService::loginUrl(),
            ] + self::brandVars(), ['title' => t('login.page_title')]);
            return;
        }
        $client_ip = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

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
        $site_favicon_url = getSystemSetting('site_favicon_url', '');
        $googleLoginEnabled = GoogleAuthService::isEnabled();
        $oidcLoginEnabled = OidcAuthService::isEnabled();
        $oidcPublicSettings = $oidcLoginEnabled ? OidcAuthService::getPublicSettings() : [];
        $samlLoginEnabled = SamlAuthService::isEnabled();
        $samlPublicSettings = $samlLoginEnabled ? SamlAuthService::getPublicSettings() : [];

        static::renderAuthPage('login/index', [
            'error' => $error,
            'num1' => $num1,
            'num2' => $num2,
            'csrf_token' => $csrf_token,
            'site_title' => $site_title,
            'site_favicon_url' => $site_favicon_url,
            'googleLoginEnabled' => $googleLoginEnabled,
            'oidcLoginEnabled' => $oidcLoginEnabled,
            'oidcPublicSettings' => $oidcPublicSettings,
            'samlLoginEnabled' => $samlLoginEnabled,
            'samlPublicSettings' => $samlPublicSettings,
        ] + self::brandVars(), ['title' => t('login.page_title'), 'inline_lang_switch' => true]);
    }

    /** What templates/views/login/_brand.php shows. */
    private static function brandVars(): array
    {
        $type = getSystemSetting('site_logo_type', 'image');
        $image = getSystemSetting('site_logo_image', BRAND_DEFAULT_LOGO_URL);
        return [
            'brand_title' => getSystemSetting('brand_title', 'AiPBX'),
            'brand_sub' => getSystemSetting('brand_sub', 'PBX & Call Center'),
            'site_logo_type' => $type,
            'site_logo_icon' => getSystemSetting('site_logo_icon', 'fa-network-wired'),
            'site_logo_image' => $image,
            'site_logo_image_dark' => getSystemSetting('site_logo_image_dark', ''),
            'site_logo_wide' => $type === 'image' && !empty($image) && getSystemSetting('site_logo_style', 'square') === 'wide',
        ];
    }
}
