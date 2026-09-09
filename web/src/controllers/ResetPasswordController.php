<?php
require_once __DIR__ . '/../services/ResetPasswordService.php';

class ResetPasswordController extends BaseController
{
    public static function index(): void
    {
        $error = '';
        $success = false;

        $token = trim($_GET['token'] ?? $_POST['token'] ?? '');
        $user = ResetPasswordRepository::lookupResetUser($token);

        if (static::isPost()) {
            $res = ResetPasswordService::resetPassword($_POST, $user);
            $success = $res['success'];
            $error = $res['error'] ?? '';
        }

        $csrf_token = getCSRFToken();
        $site_title = getSystemSetting('site_title', 'AI PBX Portalı');
        $brand_title = getSystemSetting('brand_title', 'AI PBX');
        $brand_sub = getSystemSetting('brand_sub', 'Santral & Çağrı Merkezi');

        static::render('reset_password/index', [
            'error' => $error,
            'success' => $success,
            'token' => $token,
            'user' => $user,
            'csrf_token' => $csrf_token,
            'site_title' => $site_title,
            'brand_title' => $brand_title,
            'brand_sub' => $brand_sub,
        ]);
    }
}
