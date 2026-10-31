<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/InboundBlacklistService.php';

/**
 * Controller for Inbound Caller ID Blacklist & Spam Blocker (/blacklist).
 * Renders templates/views/blacklist/index.php.
 */
class InboundBlacklistController extends BaseController
{
    public static function index(): void
    {
        static::requireLogin();
        if (!hasModulePermission('did_routes', 'access')) {
            static::forbidden();
        }

        // AJAX helper: get last caller for an extension
        if (isset($_GET['action']) && $_GET['action'] === 'last_caller') {
            header('Content-Type: application/json; charset=utf-8');
            $ext = preg_replace('/[^0-9]/', '', (string)($_GET['extension'] ?? ''));
            $last = $ext !== '' ? InboundBlacklistService::getLastCallerForExtension($ext) : null;
            echo json_encode(['success' => true, 'caller' => $last]);
            exit;
        }

        $canEdit = hasModulePermission('did_routes', 'edit');
        $canDelete = hasModulePermission('did_routes', 'delete');

        $notices = ['message' => '', 'error' => ''];

        if (static::isPost()) {
            $csrf = (string)($_POST['csrf_token'] ?? '');
            if (!verifyCSRFToken($csrf)) {
                $notices['error'] = t('common.invalid_csrf');
            } else {
                $postAction = (string)($_POST['action'] ?? '');

                if ($postAction === 'save_entry' && $canEdit) {
                    $id = intval($_POST['entry_id'] ?? 0);
                    $num = trim((string)($_POST['number'] ?? ''));
                    $title = trim((string)($_POST['title'] ?? ''));
                    $act = (string)($_POST['block_action'] ?? 'busy');
                    $isActive = !empty($_POST['is_active']);

                    if ($id > 0) {
                        $res = InboundBlacklistService::update($id, $num, $title, $act, $isActive);
                        if ($res['success']) {
                            $notices['message'] = t('blacklist.msg_updated');
                        } else {
                            $notices['error'] = $res['error'] ?? t('common.action_failed');
                        }
                    } else {
                        $res = InboundBlacklistService::add($num, $title, $act, $isActive);
                        if ($res['success']) {
                            $notices['message'] = t('blacklist.msg_added');
                        } else {
                            $notices['error'] = $res['error'] ?? t('common.action_failed');
                        }
                    }
                } elseif ($postAction === 'delete_entry' && $canDelete) {
                    $id = intval($_POST['entry_id'] ?? 0);
                    if ($id > 0 && InboundBlacklistService::delete($id)) {
                        $notices['message'] = t('blacklist.msg_deleted');
                    } else {
                        $notices['error'] = t('common.action_failed');
                    }
                } elseif ($postAction === 'toggle_active' && $canEdit) {
                    $id = intval($_POST['entry_id'] ?? 0);
                    if ($id > 0 && InboundBlacklistService::toggleActive($id)) {
                        $notices['message'] = t('blacklist.msg_status_changed');
                    } else {
                        $notices['error'] = t('common.action_failed');
                    }
                } elseif ($postAction === 'resync' && $canEdit) {
                    InboundBlacklistService::rebuildAstDb();
                    require_once __DIR__ . '/../sync/SyncInboundDialplan.php';
                    syncInboundDialplan();
                    $notices['message'] = t('blacklist.resync_success');
                } elseif ($postAction === 'import_csv' && $canEdit) {
                    $notices = self::handleCsvImport();
                }
            }
        }

        $entries = InboundBlacklistService::listAll();

        static::renderPage('blacklist/index', [
            'entries' => $entries,
            'canEdit' => $canEdit,
            'canDelete' => $canDelete,
            'notices' => $notices,
        ]);
    }

    /**
     * Handle CSV import of numbers to blacklist.
     *
     * @return array{message: string, error: string}
     */
    private static function handleCsvImport(): array
    {
        if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            return ['message' => '', 'error' => t('blacklist.err_no_file', 'Lütfen geçerli bir CSV dosyası seçin.')];
        }

        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$handle) {
            return ['message' => '', 'error' => t('blacklist.err_read_file', 'Dosya okunamadı.')];
        }

        $defaultAction = (string)($_POST['default_action'] ?? 'busy');
        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle, 1000, ',')) !== false) {
            if (empty($row) || empty($row[0])) {
                continue;
            }
            $num = trim((string)$row[0]);
            // Skip header if present
            if (strcasecmp($num, 'number') === 0 || strcasecmp($num, 'numara') === 0) {
                continue;
            }
            $title = isset($row[1]) ? trim((string)$row[1]) : 'CSV İçe Aktarma';
            $act = isset($row[2]) && in_array(trim($row[2]), InboundBlacklistService::VALID_ACTIONS, true)
                ? trim($row[2])
                : $defaultAction;

            $res = InboundBlacklistService::add($num, $title, $act, true);
            if ($res['success']) {
                $imported++;
            } else {
                $skipped++;
            }
        }
        fclose($handle);

        return [
            'message' => sprintf(t('blacklist.msg_import_done', '%d numara başarıyla içe aktarıldı, %d kayıt atlandı.'), $imported, $skipped),
            'error' => '',
        ];
    }
}
