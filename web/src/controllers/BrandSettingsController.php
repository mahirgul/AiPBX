<?php
require_once __DIR__ . '/../services/BrandSettingsService.php';

class BrandSettingsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost() && isset($_POST['save_brand_settings'])) {
            $res = BrandSettingsService::saveSettings($_POST);
            if ($res['success']) $message = $res['message']; else $error = $res['error'];
        } elseif (static::isPost() && isset($_POST['reset_brand_settings'])) {
            $res = BrandSettingsService::resetToDefaults($_POST);
            if ($res['success']) $message = $res['message']; else $error = $res['error'];
        }

        $defaults = BrandSettingsService::defaults();
        $current_db_settings = BrandSettingsRepository::currentSettings();
        $s = array_merge($defaults, $current_db_settings);

        // site_logo_image/site_favicon_url'deki eski ?v= cache-bust parametresini önizlemede tekrarlamamak için ayıkla
        $logo_preview_url = $s['site_logo_image'] ? preg_replace('/\?.*$/', '', $s['site_logo_image']) . '?v=' . time() : BRAND_DEFAULT_LOGO_URL;
        $favicon_preview_url = $s['site_favicon_url'] ? preg_replace('/\?.*$/', '', $s['site_favicon_url']) . '?v=' . time() : '';

        $page_title = t('brand_settings.title');
        static::renderPage('brand_settings/index', [
            's' => $s,
            'logo_preview_url' => $logo_preview_url,
            'favicon_preview_url' => $favicon_preview_url,
        ], ['title' => $page_title, 'message' => $message ?? '', 'error' => $error ?? '']);
    }
}
