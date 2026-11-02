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
            'save_did' => fn() => FaxSettingsService::saveDidMapping($_POST),
            'toggle_status' => fn() => PBXHelper::toggleStatus('sys_did_mappings', $_POST['did_id'] ?? 0, static::csrfToken()),
            'save_fax_cover' => fn() => FaxCoverService::saveSettings($_POST),
            'delete_did' => fn() => FaxSettingsService::deleteDidMapping($_POST['did_id'] ?? 0, static::csrfToken()),
        ]);

        $mappings = FaxSettingsRepository::allMappingsWithUser();
        $fax_users = FaxSettingsRepository::faxUsersForDropdown();

        $page_title = t('fax_settings.title');
        static::renderPage('fax_settings/index', [
            'mappings' => $mappings,
            'fax_users' => $fax_users,
            'cover' => FaxCoverService::settings(),
            'coverLogoFound' => FaxCoverService::logoPath() !== null,
        ], ['title' => $page_title] + $notices);
    }
}
