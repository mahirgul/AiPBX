<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/DialPermissionService.php';

class DialPermissionController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_group' => fn() => DialPermissionService::saveGroup($_POST),
            'delete_group' => fn() => DialPermissionService::deleteGroup($_POST['group_id'] ?? 0, static::csrfToken()),
            'save_rule' => fn() => DialPermissionService::saveRule($_POST),
            'delete_rule' => fn() => DialPermissionService::deleteRule($_POST['rule_id'] ?? 0, static::csrfToken()),
        ]);

        $groups = DialPermissionService::getGroups();
        $selected_group_id = intval($_GET['group_id'] ?? ($groups[0]['id'] ?? 1));
        $rules = DialPermissionService::getRules($selected_group_id);

        $page_title = t('dial_permissions.title', 'Arama Yetki Grupları');
        static::renderPage('dial_permissions/index', [
            'groups' => $groups,
            'selected_group_id' => $selected_group_id,
            'rules' => $rules,
        ], ['title' => $page_title] + $notices);
    }
}
