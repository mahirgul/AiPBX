<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/FaxSettingsService.php';

class FaxSettingsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_did' => fn() => FaxSettingsService::saveDidMapping($_POST),
            'toggle_status' => fn() => PBXHelper::toggleStatus('sys_did_mappings', $_POST['did_id'] ?? 0, static::csrfToken()),
            'delete_did' => fn() => FaxSettingsService::deleteDidMapping($_POST['did_id'] ?? 0, static::csrfToken()),
        ]);

        $mappings = FaxSettingsRepository::allMappingsWithUser();
        $fax_users = FaxSettingsRepository::faxUsersForDropdown();

        $page_title = t('fax_settings.title');
        static::renderPage('fax_settings/index', [
            'mappings' => $mappings,
            'fax_users' => $fax_users,
        ], ['title' => $page_title] + $notices);
    }
}
