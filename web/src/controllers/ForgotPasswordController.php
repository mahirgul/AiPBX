<?php
require_once __DIR__ . '/../services/ForgotPasswordService.php';

/** "Forgot your password?" (#13): asks for the password reset e-mail. */
class ForgotPasswordController extends BaseController
{
    public static function index(): void
    {
        if (!empty($_SESSION['user_id'])) {
            static::redirect('/');
        }
        $error = '';
        $sent = false;

        if (empty($_SESSION['forgot_captcha'])) {
            $_SESSION['forgot_captcha'] = [rand(1, 9), rand(1, 9)];
        }
        if (static::isPost()) {
            [$a, $b] = $_SESSION['forgot_captcha'];
            if (!static::verifyCsrf()) {
                $error = t('common.invalid_csrf');
            } elseif ((int) ($_POST['captcha_answer'] ?? -1) !== $a + $b) {
                $error = t('srv_login.err_captcha');
            } else {
                $res = ForgotPasswordService::request((string) ($_POST['identifier'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
                $sent = $res['success'];
                $error = $res['error'] ?? '';
            }
            $_SESSION['forgot_captcha'] = [rand(1, 9), rand(1, 9)];
        }

        static::renderAuthPage('forgot_password/index', [
            'error' => $error,
            'sent' => $sent,
            'csrf_token' => getCSRFToken(),
            'captcha' => $_SESSION['forgot_captcha'],
            'brand_title' => getSystemSetting('brand_title', 'AiPBX'),
            'brand_sub' => getSystemSetting('brand_sub', 'PBX & Call Center'),
        ], ['title' => t('forgot.title')]);
    }
}
