<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/DialPermissionService.php';
require_once __DIR__ . '/../services/BossSecretaryService.php';

class ExtensionController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_extension' => fn() => ExtensionService::saveExtension($_POST),
            'toggle_status' => fn() => PBXHelper::toggleStatus('sys_users', $_POST['user_id'] ?? 0, static::csrfToken()),
            'remove_extension' => fn() => ExtensionService::removeExtension($_POST['user_id'] ?? 0, static::csrfToken()),
            'sync_all_extensions' => fn() => ExtensionService::syncAll(static::csrfToken()),
        ]);

        $extensions = ExtensionRepository::allWithExtension();
        $pjsip_statuses = ExtensionRepository::livePjsipStatuses();
        $permission_groups = DialPermissionService::getGroups();
        $boss_secretary_groups = BossSecretaryService::getGroups();

        $page_title = t('extensions.title');
        static::renderPage('extensions/index', [
            'extensions' => $extensions,
            'pjsip_statuses' => $pjsip_statuses,
            'permission_groups' => $permission_groups,
            'boss_secretary_groups' => $boss_secretary_groups,
        ], ['title' => $page_title] + $notices);
    }
}
