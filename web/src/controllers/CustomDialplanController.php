<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/CustomDialplanService.php';
require_once __DIR__ . '/../sync/SyncCustomDialplan.php';

/**
 * Controller for Custom Asterisk Dialplans (/custom-dialplan).
 * Renders templates/views/custom_dialplan/index.php.
 */
class CustomDialplanController extends BaseController
{
    public static function index(): void
    {
        static::requireLogin();
        static::requireModule('custom_dialplan', 'access');

        $canEdit = hasModulePermission('custom_dialplan', 'edit');
        $canDelete = hasModulePermission('custom_dialplan', 'delete');

        // AJAX handlers
        if (isset($_GET['action'])) {
            header('Content-Type: application/json; charset=utf-8');
            $action = (string)$_GET['action'];

            if ($action === 'get_entry') {
                $id = intval($_GET['id'] ?? 0);
                $entry = $id > 0 ? CustomDialplanService::getById($id) : null;
                echo json_encode(['success' => (bool)$entry, 'entry' => $entry]);
                exit;
            }

            if ($action === 'get_template') {
                $key = (string)($_GET['key'] ?? '');
                $templates = CustomDialplanService::getTemplates();
                $tpl = $templates[$key] ?? null;
                echo json_encode(['success' => (bool)$tpl, 'template' => $tpl]);
                exit;
            }

            if ($action === 'preview_conf') {
                require_once __DIR__ . '/../repositories/CustomDialplanRepository.php';
                $entries = CustomDialplanRepository::findActive();
                $preview = buildCustomDialplanConf($entries);
                echo json_encode(['success' => true, 'conf' => $preview]);
                exit;
            }

            if ($action === 'validate_syntax') {
                $content = (string)($_POST['content'] ?? $_GET['content'] ?? '');
                $result = CustomDialplanService::validateSyntax($content);
                echo json_encode(['success' => true, 'result' => $result]);
                exit;
            }
        }

        $notices = ['message' => '', 'error' => '', 'warnings' => []];

        if (static::isPost()) {
            $csrf = (string)($_POST['csrf_token'] ?? '');
            if (!verifyCSRFToken($csrf)) {
                $notices['error'] = t('common.invalid_csrf');
            } else {
                $postAction = (string)($_POST['action'] ?? '');
                $userId = intval($_SESSION['user_id'] ?? 0);

                if ($postAction === 'save_entry' && $canEdit) {
                    $id = !empty($_POST['entry_id']) ? intval($_POST['entry_id']) : null;
                    $data = [
                        'id' => $id,
                        'title' => (string)($_POST['title'] ?? ''),
                        'context_name' => (string)($_POST['context_name'] ?? ''),
                        'dialplan_content' => (string)($_POST['dialplan_content'] ?? ''),
                        'include_in_internal' => !empty($_POST['include_in_internal']) ? 1 : 0,
                        'description' => (string)($_POST['description'] ?? ''),
                        'sort_order' => intval($_POST['sort_order'] ?? 10),
                        'is_active' => !empty($_POST['is_active']) ? 1 : 0,
                    ];

                    $res = CustomDialplanService::save($data, $userId);
                    if ($res['success']) {
                        $notices['message'] = $id !== null ? t('custom_dialplan.msg_updated') : t('custom_dialplan.msg_created');
                        if (!empty($res['warnings'])) {
                            $notices['warnings'] = $res['warnings'];
                        }
                    } else {
                        $notices['error'] = implode('<br>', $res['errors'] ?? [t('common.action_failed')]);
                    }
                } elseif ($postAction === 'delete_entry' && $canDelete) {
                    $id = intval($_POST['entry_id'] ?? 0);
                    if ($id > 0 && CustomDialplanService::delete($id, $userId)) {
                        $notices['message'] = t('custom_dialplan.msg_deleted');
                    } else {
                        $notices['error'] = t('common.action_failed');
                    }
                } elseif ($postAction === 'toggle_active' && $canEdit) {
                    $id = intval($_POST['entry_id'] ?? 0);
                    if ($id > 0 && CustomDialplanService::toggleActive($id, $userId)) {
                        $notices['message'] = t('custom_dialplan.msg_status_changed');
                    } else {
                        $notices['error'] = t('common.action_failed');
                    }
                }
            }
        }

        $entries = CustomDialplanService::getAll();
        $templates = CustomDialplanService::getTemplates();

        static::renderPage('custom_dialplan/index', [
            'entries' => $entries,
            'templates' => $templates,
            'canEdit' => $canEdit,
            'canDelete' => $canDelete,
            'notices' => $notices,
        ]);
    }
}
