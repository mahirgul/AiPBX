<?php
require_once __DIR__ . '/../services/FaxSendService.php';

class FaxSendController extends BaseController
{
    public static function index(): void
    {
        static::requireRole(['admin', 'fax_user']);

        $user_id = $_SESSION['user_id'];
        $user_ext = $_SESSION['extension'] ?? '8960';
        $user_role = $_SESSION['user_role'] ?? 'user';

        $message = '';
        $error = '';

        if (static::isPost()) {
            $res = FaxSendService::sendFax($_POST, $_FILES, $user_id, $user_ext);
            if ($res['success']) $message = $res['message']; else $error = $res['error'];
        }

        $csrf_token = getCSRFToken();

        // Admin kendi SIP dahilisi (ör. 19000) yerine bir faks birimi ADINA
        // gönderim yapabilsin diye dropdown listesi — sadece admin için
        // sorgulanıyor, sıradan faks kullanıcısı zaten hep kendi dahilisini
        // kullanıyor (2026-08-31, kullanıcı isteği).
        $fax_users = ($user_role === 'admin') ? FaxSettingsRepository::faxUsersForDropdown() : [];

        $page_title = t('fax_send.title');
        static::renderPage('fax_send/index', [
            'user_ext' => $user_ext,
            'user_role' => $user_role,
            'fax_users' => $fax_users,
            'csrf_token' => $csrf_token,
        ], ['title' => $page_title, 'message' => $message ?? '', 'error' => $error ?? '']);
    }
}
