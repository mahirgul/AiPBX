<?php
require_once __DIR__ . '/../services/BrandSettingsService.php';

class BrandSettingsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_brand_settings' => fn() => BrandSettingsService::saveSettings($_POST),
            'reset_brand_settings' => fn() => BrandSettingsService::resetToDefaults($_POST),
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
        ], ['title' => $page_title] + $notices);
    }
}
