<?php
require_once __DIR__ . '/../services/LoginCaptchaService.php';
require_once __DIR__ . '/../services/SsoOnlyService.php';
require_once __DIR__ . '/../services/SamlAuthService.php';
require_once __DIR__ . '/../services/OidcAuthService.php';

/**
 * Sign-in page (/sign-in-settings): how everyone signs in to the portal — the
 * sign-in check (sum or Cloudflare Turnstile), the new-address alert and
 * sign-in only through the identity provider. Admins only.
 * Renders templates/views/sign_in_settings/index.php.
 */
class SignInSettingsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_sso_only' => fn() => SsoOnlyService::saveSettings($_POST),
            'save_login_captcha' => fn() => LoginCaptchaService::saveSettings($_POST),
            'save_login_alert' => fn() => LoginAlertService::saveSettings($_POST),
        ]);

        static::renderPage('sign_in_settings/index', [
            'sso_only_on' => getSystemSetting('auth_sso_only', '0') === '1',
            'sso_only_provider' => SsoOnlyService::provider(),
            'sso_saml_enabled' => SsoOnlyService::providerEnabled('saml'),
            'sso_oidc_enabled' => SsoOnlyService::providerEnabled('oidc'),
            'captcha_provider' => (string) getSystemSetting('login_captcha_provider', 'math'),
            'captcha_site_key' => LoginCaptchaService::siteKey(),
            'captcha_secret_set' => (string) getSystemSetting('login_turnstile_secret', '') !== '',
            'login_alert_on' => LoginAlertService::enabled(),
        ], ['title' => t('sign_in_settings.title')] + $notices);
    }
}
