<?php
require_once __DIR__ . '/../services/FaxSentService.php';

class FaxSentController extends BaseController
{
    public static function index(): void
    {
        static::requireRole(['admin', 'fax_user']);

        $user_id = $_SESSION['user_id'];
        $user_role = $_SESSION['user_role'] ?? '';

        $notices = static::handlePost([
            'delete_sent_fax' => fn() => hasModulePermission('fax_sent', 'delete')
                ? FaxSentService::deleteSentFax($_POST['fax_id'] ?? 0, static::csrfToken(), $user_role, $user_id)
                : ['success' => false, 'error' => 'Faks silme yetkiniz bulunmamaktadır.'],
            'resend_sent_fax' => fn() => hasModulePermission('fax_sent', 'edit')
                ? FaxSentService::resendFax($_POST['fax_id'] ?? 0, static::csrfToken(), $user_role, $user_id)
                : ['success' => false, 'error' => 'Faks yeniden gönderme yetkiniz bulunmamaktadır.'],
        ]);

        $sent_faxes = FaxSentRepository::listForUser($user_role, $user_id);

        $page_title = t('fax_sent.title');
        static::renderPage('fax_sent/index', [
            'sent_faxes' => $sent_faxes,
        ], ['title' => $page_title] + $notices);
    }
}
