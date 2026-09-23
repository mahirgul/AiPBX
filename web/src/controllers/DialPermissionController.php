<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/DialPermissionService.php';

class DialPermissionController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost()) {
            if (isset($_POST['save_group'])) {
                $res = DialPermissionService::saveGroup($_POST);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['delete_group'])) {
                $res = DialPermissionService::deleteGroup($_POST['group_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['save_rule'])) {
                $res = DialPermissionService::saveRule($_POST);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['delete_rule'])) {
                $res = DialPermissionService::deleteRule($_POST['rule_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            }
        }

        $groups = DialPermissionService::getGroups();
        $selected_group_id = intval($_GET['group_id'] ?? ($groups[0]['id'] ?? 1));
        $rules = DialPermissionService::getRules($selected_group_id);

        $page_title = t('dial_permissions.title', 'Arama Yetki Grupları');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('dial_permissions/index', [
            'groups' => $groups,
            'selected_group_id' => $selected_group_id,
            'rules' => $rules,
            'message' => $message,
            'error' => $error,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
