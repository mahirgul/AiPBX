<?php

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../services/GoogleAuthService.php';

class GoogleIntegrationController extends BaseController
{
    public static function index(): void
    {
        requireLogin();
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost()) {
            $csrf = $_POST['csrf_token'] ?? '';
            if (!verifyCSRFToken($csrf)) {
                $error = t('common.invalid_csrf', 'Geçersiz güvenlik kodu (CSRF)! Lütfen sayfayı yenileyin.');
            } else {
                $action = $_POST['action'] ?? '';
                if ($action === 'save_settings') {
                    $res = GoogleAuthService::saveSettings($_POST);
                    if ($res['success']) {
                        $message = $res['message'];
                    } else {
                        $error = $res['error'];
                    }
                }
            }
        }

        $googleSettings = [
            'enabled' => GoogleAuthService::isEnabled(),
            'raw_enabled' => (function_exists('getSystemSetting') ? getSystemSetting('google_oauth_enabled', '0') : '0') === '1',
            'client_id' => GoogleAuthService::getClientId(),
            'client_secret' => GoogleAuthService::getClientSecret(),
            'redirect_uri' => GoogleAuthService::getRedirectUri(),
        ];

        $page_title = t('google_integration.title', 'Google ile Giriş Entegrasyonu');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('google_integration/index', [
            'settings' => $googleSettings,
            'message' => $message,
            'error' => $error,
            'csrf_token' => getCSRFToken(),
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
