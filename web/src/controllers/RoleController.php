<?php
require_once __DIR__ . '/../services/RoleService.php';

class RoleController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $modules_definition = RoleRepository::modulesDefinition();

        $message = '';
        $error = '';

        if (static::isPost()) {
            if (isset($_POST['save_role'])) {
                $res = RoleService::saveRole($_POST, $modules_definition);
                if (isset($res['redirect'])) {
                    static::redirect($res['redirect']);
                }
                $error = $res['error'] ?? '';
            } elseif (isset($_POST['delete_role'])) {
                $res = RoleService::deleteRole($_POST);
                if (isset($res['redirect'])) {
                    static::redirect($res['redirect']);
                }
                $error = $res['error'] ?? '';
            }
        }

        $roles = RoleRepository::allWithUserCount();
        $all_permissions = RoleRepository::allPermissionsMap();

        $page_title = t('roles.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('roles/index', [
            'modules_definition' => $modules_definition,
            'roles' => $roles,
            'all_permissions' => $all_permissions,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
