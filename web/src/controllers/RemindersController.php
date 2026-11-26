<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/ai/ReminderService.php';

/**
 * /reminders: Personal & admin Reminders + Wake-Up Calls.
 */
final class RemindersController extends BaseController
{
    public static function index(): void
    {
        static::requireLogin();
        static::requireModule('my_phone', 'view');
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $isAdmin = userHasRole('admin');
        $userExt = (string) ($_SESSION['extension'] ?? '');
        if ($userExt === '' && $userId) {
            $st = getDB()->prepare('SELECT extension FROM sys_users WHERE id = ? LIMIT 1');
            $st->execute([$userId]);
            $userExt = (string) ($st->fetchColumn() ?: '');
        }
        $canEdit = hasModulePermission('my_phone', 'edit');
        $token = (string) ($_POST['csrf_token'] ?? '');

        $notices = static::handlePost([
            'create_reminder' => fn () => PBXHelper::handleAction($token, static function () use ($userId, $isAdmin, $userExt, $canEdit): string {
                if (!$canEdit) {
                    throw new \RuntimeException(t('common.action_failed'));
                }
                $data = $_POST;
                if (!$isAdmin) {
                    if ($userExt === '') {
                        throw new \RuntimeException(t('common.action_failed'));
                    }
                    $data['extension'] = $userExt;
                }
                $res = ReminderService::create($data, $userId);
                if (empty($res['ok'])) {
                    throw new \RuntimeException((string) ($res['error'] ?? t('common.action_failed')));
                }
                return t('reminders.msg_created');
            }),
            'save_voice' => fn () => PBXHelper::handleAction($token, static function () use ($isAdmin): string {
                if (!$isAdmin) {
                    throw new \RuntimeException(t('common.action_failed'));
                }
                require_once __DIR__ . '/../services/ai/AiBindingService.php';
                AiBindingService::save('reminders', 'tts', (string) ($_POST['reminder_voice'] ?? ''));
                return t('common.saved');
            }),
            'cancel_reminder' => fn () => PBXHelper::handleAction($token, static function () use ($userId, $isAdmin, $canEdit): string {
                if (!$canEdit) {
                    throw new \RuntimeException(t('common.action_failed'));
                }
                if (!ReminderService::cancel((int) ($_POST['reminder_id'] ?? 0), $userId, $isAdmin)) {
                    throw new \RuntimeException(t('common.action_failed'));
                }
                return t('reminders.msg_cancelled');
            }),
            'snooze_reminder' => fn () => PBXHelper::handleAction($token, static function () use ($userId, $isAdmin, $canEdit): string {
                if (!$canEdit) {
                    throw new \RuntimeException(t('common.action_failed'));
                }
                if (!ReminderService::snooze((int) ($_POST['reminder_id'] ?? 0), (int) ($_POST['minutes'] ?? 10), $userId, $isAdmin)) {
                    throw new \RuntimeException(t('common.action_failed'));
                }
                return t('reminders.msg_snoozed');
            }),
        ]);

        require_once __DIR__ . '/../services/ai/AiBindingService.php';
        $extensions = [];
        if ($isAdmin) {
            $extensions = getDB()->query("SELECT extension, full_name FROM sys_users WHERE is_active = 1 AND extension IS NOT NULL AND extension <> '' ORDER BY extension ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        static::renderPage('reminders/index', [
            'reminders' => ReminderService::listForUser($userId, $isAdmin, $userExt),
            'isAdmin' => $isAdmin,
            'canEdit' => $canEdit,
            'userExt' => $userExt,
            'extensions' => $extensions,
            'csrf_token' => getCSRFToken(),
            'notices' => $notices,
        ], ['title' => t('reminders.title')] + $notices);
    }
}
