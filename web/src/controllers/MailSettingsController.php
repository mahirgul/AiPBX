<?php
require_once __DIR__ . '/../services/MailSettingsService.php';

class MailSettingsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost()) {
            if (isset($_POST['save_mail_settings'])) {
                $res = MailSettingsService::saveSettings($_POST);
                if ($res['success']) {
                    static::notifySuccess($res['message']);
                    $message = $res['message'];
                } else {
                    static::notifyError($res['error']);
                    $error = $res['error'];
                }
            } elseif (isset($_POST['send_test_email'])) {
                if (!static::verifyCsrf()) {
                    static::notifyError('Geçersiz CSRF güvenlik doğrulama kodu!');
                } else {
                    $test_recipient = trim($_POST['test_recipient'] ?? '');
                    $res = MailSettingsService::sendTestEmail($test_recipient);
                    if ($res['success']) {
                        static::notifySuccess($res['message']);
                        $message = $res['message'];
                    } else {
                        static::notifyError($res['error']);
                        $error = $res['error'];
                    }
                }
            }
        }

        $sys_settings = MailSettingsRepository::allSettings();
        $postfix_status = MailSettingsRepository::getPostfixStatus();

        $page_title = t('mail_settings.title', 'E-Posta & Mail Relay Ayarları');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('mail_settings/index', [
            'settings' => $sys_settings,
            'postfix' => $postfix_status,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
