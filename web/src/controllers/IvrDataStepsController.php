<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/IvrDataStepService.php';
require_once __DIR__ . '/../repositories/IvrDataStepRepository.php';
require_once __DIR__ . '/../repositories/ExternalDbRepository.php';

/**
 * Controller for /ivr-data-steps (Roadmap 2.3).
 * Manage IVR data steps (database / HTTP lookups).
 */
class IvrDataStepsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');
        static::requireModule('ivrs', 'view');

        if (static::isPost()) {
            if (!static::verifyCsrf()) {
                static::renderPage('ivr_data_steps/index', self::getViewData(), [
                    'title' => t('ivr_data.page_title'),
                    'error' => t('common.invalid_csrf'),
                ]);
                return;
            }

            $action = (string)($_POST['action'] ?? '');

            if ($action === 'save_step') {
                static::requireModule('ivrs', 'edit');
                if (($_POST['data_source'] ?? 'database') === 'database') {
                    static::requireModule('external_dbs', 'view');
                }
                $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
                $res = IvrDataStepService::saveStep($_POST, $userId);
                if ($res['ok']) {
                    static::notifySuccess(t('ivr_data.saved_successfully'));
                    static::redirect('/ivr-data-steps');
                } else {
                    $errMsg = implode(', ', $res['errors']);
                    static::renderPage('ivr_data_steps/index', self::getViewData(), [
                        'title' => t('ivr_data.page_title'),
                        'error' => $errMsg,
                    ]);
                    return;
                }
            }

            if ($action === 'delete_step') {
                static::requireModule('ivrs', 'delete');
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0 && ($used = IvrDataStepService::usageCount($id)) > 0) {
                    static::notifyError(sprintf(t('ivr_data.err_in_use'), $used));
                    static::redirect('/ivr-data-steps');
                }
                if ($id > 0) {
                    IvrDataStepService::deleteStep($id);
                    static::notifySuccess(t('ivr_data.deleted_successfully'));
                }
                static::redirect('/ivr-data-steps');
            }

            if ($action === 'toggle_active') {
                static::requireModule('ivrs', 'edit');
                $id = (int)($_POST['id'] ?? 0);
                $isActive = !empty($_POST['is_active']);
                if ($id > 0) {
                    IvrDataStepService::toggleActive($id, $isActive);
                }
                static::redirect('/ivr-data-steps');
            }
        }

        static::renderPage('ivr_data_steps/index', self::getViewData(), [
            'title' => t('ivr_data.page_title'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function getViewData(): array
    {
        return [
            'steps' => IvrDataStepRepository::findAll('name ASC'),
            'dbs' => ExternalDbRepository::findActive(),
        ];
    }
}
