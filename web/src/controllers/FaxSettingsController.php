<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/FaxSettingsService.php';
require_once __DIR__ . '/../services/FaxCoverService.php';

/**
 * Fax Units page (/fax-settings).
 * Renders templates/views/fax_settings/index.php with assets/js/fax_settings.js.
 * Logic: FaxSettingsRepository, FaxSettingsService.
 * POST actions: delete_did, save_did, toggle_status.
 * Admins only.
 */
class FaxSettingsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_did' => fn() => FaxSettingsService::saveAccount($_POST),
            'toggle_status' => fn() => FaxSettingsService::toggleAccount($_POST),
            'save_fax_cover' => fn() => FaxCoverService::saveSettings($_POST),
            'delete_did' => fn() => FaxSettingsService::deleteAccount($_POST),
        ]);

        $accounts = FaxSettingsService::accounts();

        $page_title = t('fax_settings.title');
        static::renderPage('fax_settings/index', [
            'accounts' => $accounts,
            'permissionGroups' => DialPermissionService::getGroups(),
            'faxRoles' => FaxSettingsService::faxRoles(),
            'linkableUsers' => FaxSettingsService::linkableUsers(),
            'cover' => FaxCoverService::settings(),
            'coverLogoFound' => FaxCoverService::logoPath() !== null,
        ], ['title' => $page_title] + $notices);
    }
}
