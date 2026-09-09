<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/FaxSettingsService.php';

class FaxSettingsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost()) {
            if (isset($_POST['save_did'])) {
                $res = FaxSettingsService::saveDidMapping($_POST);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['toggle_status'])) {
                $res = PBXHelper::toggleStatus('sys_did_mappings', $_POST['did_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['delete_did'])) {
                $res = FaxSettingsService::deleteDidMapping($_POST['did_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            }
        }

        $mappings = FaxSettingsRepository::allMappingsWithUser();
        $fax_users = FaxSettingsRepository::faxUsersForDropdown();

        $page_title = t('fax_settings.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('fax_settings/index', [
            'mappings' => $mappings,
            'fax_users' => $fax_users,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
