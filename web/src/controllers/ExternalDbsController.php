<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/ExternalDbService.php';
require_once __DIR__ . '/../repositories/ExternalDbRepository.php';

/**
 * Controller for /external-dbs (Roadmap 2.2).
 * Administrators only. Manage external MySQL/MariaDB and PostgreSQL connections.
 */
class ExternalDbsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        if (static::isPost()) {
            if (!static::verifyCsrf()) {
                if (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
                    static::json(['ok' => false, 'message' => t('common.invalid_csrf')]);
                }
                static::renderPage('external_dbs/index', self::getViewData(), [
                    'title' => t('external_dbs.page_title'),
                    'error' => t('common.invalid_csrf'),
                ]);
                return;
            }

            $action = (string)($_POST['action'] ?? '');

            if ($action === 'test_connection') {
                $res = ExternalDbService::testConnection($_POST);
                static::json($res);
            }

            if ($action === 'save_connection') {
                $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
                $res = ExternalDbService::saveConnection($_POST, $userId);
                if ($res['ok']) {
                    // The password itself is never logged.
                    writeAuditLog('external_dbs', 'connection', $res['id'], mb_substr((string) ($_POST['name'] ?? ''), 0, 100),
                        empty($_POST['id']) ? 'create' : 'update', $userId);
                    static::notifySuccess(t('external_dbs.saved_successfully'));
                    static::redirect('/external-dbs');
                } else {
                    $errMsg = implode(', ', $res['errors']);
                    static::renderPage('external_dbs/index', self::getViewData(), [
                        'title' => t('external_dbs.page_title'),
                        'error' => $errMsg,
                    ]);
                    return;
                }
            }

            if ($action === 'delete_connection') {
                $id = (int)($_POST['id'] ?? 0);
                $inUse = (int) DBHelper::fetchColumn('SELECT COUNT(*) FROM pbx_ivr_data_steps WHERE external_db_id = ?', [$id]);
                if ($inUse > 0) {
                    static::notifyError(sprintf(t('external_dbs.err_in_use'), $inUse));
                    static::redirect('/external-dbs');
                }
                if ($id > 0) {
                    $name = (string) (ExternalDbRepository::findById($id)['name'] ?? "#{$id}");
                    ExternalDbService::deleteConnection($id);
                    writeAuditLog('external_dbs', 'connection', $id, $name, 'delete', $_SESSION['user_id'] ?? null);
                    static::notifySuccess(t('external_dbs.deleted_successfully'));
                }
                static::redirect('/external-dbs');
            }

            if ($action === 'toggle_active') {
                $id = (int)($_POST['id'] ?? 0);
                $isActive = !empty($_POST['is_active']);
                if ($id > 0) {
                    ExternalDbService::toggleActive($id, $isActive);
                    writeAuditLog('external_dbs', 'connection', $id, 'Connection ' . ($isActive ? 'on' : 'off'), 'update', $_SESSION['user_id'] ?? null);
                }
                static::redirect('/external-dbs');
            }
        }

        static::renderPage('external_dbs/index', self::getViewData(), [
            'title' => t('external_dbs.page_title'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function getViewData(): array
    {
        return [
            'connections' => ExternalDbRepository::findAll('name ASC'),
            'drivers' => ExternalDbService::SUPPORTED_DRIVERS,
        ];
    }
}
