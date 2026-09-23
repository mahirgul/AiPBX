<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/DialPermissionService.php';
require_once __DIR__ . '/../services/BossSecretaryService.php';

class ExtensionController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost()) {
            if (isset($_POST['save_extension'])) {
                $res = PBXHelper::saveExtension($_POST);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['toggle_status'])) {
                $res = PBXHelper::toggleStatus('sys_users', $_POST['user_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['remove_extension'])) {
                $res = PBXHelper::removeExtension($_POST['user_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['sync_all_extensions'])) {
                $res = PBXHelper::syncAllExtensions($_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            }
        }

        $extensions = ExtensionRepository::allWithExtension();
        $pjsip_statuses = ExtensionRepository::livePjsipStatuses();
        $permission_groups = DialPermissionService::getGroups();
        $boss_secretary_groups = BossSecretaryService::getGroups();

        $page_title = t('extensions.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('extensions/index', [
            'extensions' => $extensions,
            'pjsip_statuses' => $pjsip_statuses,
            'permission_groups' => $permission_groups,
            'boss_secretary_groups' => $boss_secretary_groups,
            'message' => $message,
            'error' => $error,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
