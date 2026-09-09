<?php
require_once __DIR__ . '/../services/FaxSentService.php';

class FaxSentController extends BaseController
{
    public static function index(): void
    {
        static::requireRole(['admin', 'fax_user']);

        $user_id = $_SESSION['user_id'];
        $user_role = $_SESSION['user_role'] ?? '';

        $message = '';
        $error = '';

        if (static::isPost() && isset($_POST['delete_sent_fax'])) {
            $res = FaxSentService::deleteSentFax($_POST['fax_id'] ?? 0, $_POST['csrf_token'] ?? '', $user_role, $user_id);
            if ($res['success']) $message = $res['message']; else $error = $res['error'];
        } elseif (static::isPost() && isset($_POST['resend_sent_fax'])) {
            $res = FaxSentService::resendFax($_POST['fax_id'] ?? 0, $_POST['csrf_token'] ?? '', $user_role, $user_id);
            if ($res['success']) $message = $res['message']; else $error = $res['error'];
        }

        $sent_faxes = FaxSentRepository::listForUser($user_role, $user_id);

        $page_title = t('fax_sent.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('fax_sent/index', [
            'sent_faxes' => $sent_faxes,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
