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

        $notices = static::isPost()
            ? static::notices(FaxSendService::sendFax($_POST, $_FILES, $user_id, $user_ext))
            : ['message' => '', 'error' => ''];

        $csrf_token = getCSRFToken();

        // A dropdown so the admin can send ON BEHALF of a fax unit instead of
        // their own SIP extension (e.g. 19000) — queried only for admin; a
        // regular fax user always uses their own extension anyway (2026-08-31,
        // user request).
        $fax_users = ($user_role === 'admin') ? FaxSettingsRepository::faxUsersForDropdown() : [];

        $page_title = t('fax_send.title');
        static::renderPage('fax_send/index', [
            'user_ext' => $user_ext,
            'user_role' => $user_role,
            'fax_users' => $fax_users,
            'csrf_token' => $csrf_token,
        ], ['title' => $page_title] + $notices);
    }
}
