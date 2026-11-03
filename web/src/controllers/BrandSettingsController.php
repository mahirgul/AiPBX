<?php
require_once __DIR__ . '/../services/BrandSettingsService.php';
require_once __DIR__ . '/../services/LoginCaptchaService.php';
require_once __DIR__ . '/../services/SsoOnlyService.php';
require_once __DIR__ . '/../services/SamlAuthService.php';
require_once __DIR__ . '/../services/OidcAuthService.php';

/**
 * Appearance page (/brand-settings).
 * Renders templates/views/brand_settings/index.php with assets/js/brand_settings.js.
 * Logic: BrandSettingsRepository, BrandSettingsService.
 * POST actions: reset_brand_settings, save_brand_settings.
 * Admins only.
 */
class BrandSettingsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_brand_settings' => fn() => BrandSettingsService::saveSettings($_POST),
            'reset_brand_settings' => fn() => BrandSettingsService::resetToDefaults($_POST),
            'save_sso_only' => fn() => SsoOnlyService::saveSettings($_POST),
            'save_login_captcha' => fn() => LoginCaptchaService::saveSettings($_POST),
            'save_login_alert' => fn() => LoginAlertService::saveSettings($_POST),
        ]);

        $defaults = BrandSettingsService::defaults();
        $current_db_settings = BrandSettingsRepository::currentSettings();
        $s = array_merge($defaults, $current_db_settings);

        // Strip the old ?v= cache-bust parameter from site_logo_image/site_favicon_url so it is not repeated in the preview
        $logo_preview_url = $s['site_logo_image'] ? preg_replace('/\?.*$/', '', $s['site_logo_image']) . '?v=' . time() : BRAND_DEFAULT_LOGO_URL;
        $logo_dark_preview_url = $s['site_logo_image_dark'] ? preg_replace('/\?.*$/', '', $s['site_logo_image_dark']) . '?v=' . time() : '';
        $favicon_preview_url = $s['site_favicon_url'] ? preg_replace('/\?.*$/', '', $s['site_favicon_url']) . '?v=' . time() : '';

        $page_title = t('brand_settings.title');
        static::renderPage('brand_settings/index', [
            's' => $s,
            'logo_preview_url' => $logo_preview_url,
            'logo_dark_preview_url' => $logo_dark_preview_url,
            'favicon_preview_url' => $favicon_preview_url,
            'sso_only_on' => getSystemSetting('auth_sso_only', '0') === '1',
            'sso_only_provider' => SsoOnlyService::provider(),
            'sso_saml_enabled' => SsoOnlyService::providerEnabled('saml'),
            'sso_oidc_enabled' => SsoOnlyService::providerEnabled('oidc'),
            'captcha_provider' => (string) getSystemSetting('login_captcha_provider', 'math'),
            'captcha_site_key' => LoginCaptchaService::siteKey(),
            'captcha_secret_set' => (string) getSystemSetting('login_turnstile_secret', '') !== '',
            'login_alert_on' => LoginAlertService::enabled(),
        ], ['title' => $page_title] + $notices);
    }
}
