<?php
require_once __DIR__ . '/../services/MailSettingsService.php';

class MailSettingsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_mail_settings' => fn() => MailSettingsService::saveSettings($_POST),
            'send_test_email' => fn() => static::verifyCsrf()
                ? MailSettingsService::sendTestEmail(trim($_POST['test_recipient'] ?? ''))
                : ['success' => false, 'error' => t('common.invalid_csrf')],
        ]);

        $sys_settings = MailSettingsRepository::allSettings();
        $postfix_status = MailSettingsRepository::getPostfixStatus();

        $page_title = t('mail_settings.title', 'E-Posta & Mail Relay Ayarları');
        static::renderPage('mail_settings/index', [
            'settings' => $sys_settings,
            'postfix' => $postfix_status,
        ], ['title' => $page_title] + $notices);
    }
}
