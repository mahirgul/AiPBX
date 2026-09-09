<?php
require_once __DIR__ . '/../services/FaxInboxService.php';

class FaxInboxController extends BaseController
{
    public static function index(): void
    {
        static::requireRole(['admin', 'fax_user']);

        $user_role = $_SESSION['user_role'] ?? '';
        $user_id = intval($_SESSION['user_id'] ?? 0);
        $user_ext = $_SESSION['extension'] ?? '';

        $search = trim($_GET['search'] ?? '');
        $date_from = trim($_GET['date_from'] ?? '');
        $date_to = trim($_GET['date_to'] ?? '');

        $message = '';
        $error = '';

        if (static::isPost() && isset($_POST['delete_fax'])) {
            $res = FaxInboxService::deleteFax($_POST['fax_id'] ?? 0, $_POST['csrf_token'] ?? '', $user_role, $user_ext, $user_id);
            if ($res['success']) $message = $res['message']; else $error = $res['error'];
        }

        $page = max(1, intval($_GET['page'] ?? 1));
        $per_page = View::sayfaBoyutu(50);
        $offset = ($page - 1) * $per_page;

        $result = FaxInboxRepository::search($user_role, $user_ext, $search, $date_from, $date_to, $per_page, $offset, $user_id);

        $page_title = t('fax_inbox.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('fax_inbox/index', [
            'search' => $search,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'page' => $page,
            'page_size' => $per_page,
            'total_rows' => $result['total'] ?? null,
            'total_count' => $result['total_count'],
            'total_pages' => $result['total_pages'],
            'faxes' => $result['faxes'],
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
