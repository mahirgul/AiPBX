<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/PhoneLogService.php';
require_once __DIR__ . '/../services/NetworkServicesService.php';

/** PBX → Phone logs: the syslog of desk phones, by IP or MAC, followed live. */
class PhoneLogController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        if (static::isPost() && ($_POST['action'] ?? '') === 'read') {
            if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
                static::json(['success' => false, 'message' => t('common.invalid_csrf')]);
            }
            $cursor = isset($_POST['ino'], $_POST['pos']) ? ['ino' => (int) $_POST['ino'], 'pos' => (int) $_POST['pos']] : null;
            $res = PhoneLogService::read(
                mb_substr((string) ($_POST['who'] ?? ''), 0, 64),
                mb_substr((string) ($_POST['text'] ?? ''), 0, 200),
                $cursor
            );
            static::json(['success' => true] + $res);
        }

        $on = getSystemSetting('netsvc_syslog', '0') === '1';
        static::renderPage('phone_logs/index', [
            'csrf' => getCSRFToken(),
            'receiving' => $on && NetworkServicesService::syslogStatus() === 'active',
            'enabled' => $on,
            'labels' => PhoneLogService::phoneLabels(),
            'who' => mb_substr((string) ($_GET['who'] ?? ''), 0, 64),
        ], ['title' => t('phonelogs.title')]);
    }
}
