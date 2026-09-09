<?php
require_once __DIR__ . '/../services/ForceResetService.php';

class ForceResetController extends BaseController
{
    public static function index(): void
    {
        if (empty($_SESSION['pending_reset_user_id'])) {
            static::redirect('/login');
        }

        $user_id = (int)$_SESSION['pending_reset_user_id'];
        $user = ForceResetRepository::findPendingResetUser($user_id);

        if (!$user || empty($user['must_reset_password'])) {
            unset($_SESSION['pending_reset_user_id']);
            static::redirect('/login');
        }

        $error = '';
        $sent = false;

        if (static::isPost()) {
            $res = ForceResetService::requestResetEmail($_POST, $user);
            $sent = $res['sent'];
            $error = $res['error'] ?? '';
        }

        $csrf_token = getCSRFToken();
        $brand_title = getSystemSetting('brand_title', 'AI PBX');
        $brand_sub = getSystemSetting('brand_sub', 'Santral & Çağrı Merkezi');
        $site_title = getSystemSetting('site_title', 'AI PBX Portalı');

        $maskedEmail = '';
        if (!empty($user['email']) && strpos($user['email'], '@') !== false) {
            [$local, $domain] = explode('@', $user['email'], 2);
            $visible = mb_substr($local, 0, 2);
            $maskedEmail = $visible . str_repeat('*', max(1, mb_strlen($local) - 2)) . '@' . $domain;
        }

        static::render('force_reset/index', [
            'error' => $error,
            'sent' => $sent,
            'csrf_token' => $csrf_token,
            'brand_title' => $brand_title,
            'brand_sub' => $brand_sub,
            'site_title' => $site_title,
            'maskedEmail' => $maskedEmail,
            'user' => $user,
        ]);
    }
}
