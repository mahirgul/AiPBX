<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/ScheduledTaskService.php';
require_once __DIR__ . '/../services/ScheduledTaskRunner.php';
require_once __DIR__ . '/../services/CronScheduleHelper.php';
require_once __DIR__ . '/../repositories/ScheduledTaskRepository.php';

/**
 * Controller for /scheduled-tasks (Roadmap 2.1).
 * Administrators only. Sandboxed shell, PHP, and Python scripts on a cron schedule.
 */
class ScheduledTasksController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        if (static::isPost()) {
            if (!static::verifyCsrf()) {
                if (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
                    static::json(['ok' => false, 'error' => t('common.invalid_csrf')]);
                }
                static::renderPage('scheduled_tasks/index', self::getViewData(), [
                    'title' => t('scheduled_tasks.page_title'),
                    'error' => t('common.invalid_csrf'),
                ]);
                return;
            }

            $action = (string)($_POST['action'] ?? '');

            if ($action === 'toggle_enable') {
                $enabled = !empty($_POST['enabled']);
                ScheduledTaskService::setEnabled($enabled);
                static::redirect('/scheduled-tasks');
            }

            if ($action === 'save_task') {
                $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
                $res = ScheduledTaskService::saveTask($_POST, $userId);
                if ($res['ok']) {
                    static::notifySuccess(t('scheduled_tasks.saved_successfully'));
                    static::redirect('/scheduled-tasks');
                } else {
                    $errMsg = implode(', ', $res['errors']);
                    static::renderPage('scheduled_tasks/index', self::getViewData(), [
                        'title' => t('scheduled_tasks.page_title'),
                        'error' => $errMsg,
                    ]);
                    return;
                }
            }

            if ($action === 'delete_task') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    ScheduledTaskService::deleteTask($id);
                    static::notifySuccess(t('scheduled_tasks.deleted_successfully'));
                }
                static::redirect('/scheduled-tasks');
            }

            if ($action === 'toggle_task') {
                $id = (int)($_POST['id'] ?? 0);
                $isActive = !empty($_POST['is_active']);
                if ($id > 0) {
                    ScheduledTaskService::toggleTask($id, $isActive);
                }
                static::redirect('/scheduled-tasks');
            }

            if ($action === 'run_now') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    static::json(['ok' => false, 'error' => 'Invalid task ID']);
                }
                $res = ScheduledTaskRunner::run($id, 'manual');
                static::json($res);
            }

            if ($action === 'get_details') {
                $id = (int)($_POST['id'] ?? 0);
                $details = ScheduledTaskService::getTaskDetails($id);
                static::json(['ok' => $details !== null, 'task' => $details]);
            }
        }

        static::renderPage('scheduled_tasks/index', self::getViewData(), [
            'title' => t('scheduled_tasks.page_title'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function getViewData(): array
    {
        $tasks = ScheduledTaskRepository::findAll('name ASC');
        foreach ($tasks as &$task) {
            $cronExpr = (string)($task['cron_expression'] ?? '0 3 * * *');
            $task['next_runs'] = CronScheduleHelper::getNextRuns($cronExpr, 5);
            $task['is_running'] = ScheduledTaskRepository::isTaskRunning((int)$task['id']);
        }
        unset($task);

        return [
            'tasks' => $tasks,
            'isEnabled' => ScheduledTaskService::isEnabled(),
            'presets' => CronScheduleHelper::PRESETS,
        ];
    }
}
