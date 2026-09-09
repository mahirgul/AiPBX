<?php
require_once __DIR__ . '/../helpers.php';

class HangupActionController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost()) {
            if (isset($_POST['save_hangup_action'])) {
                $res = PBXHelper::saveHangupAction($_POST);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['delete_hangup_action'])) {
                $res = PBXHelper::deleteHangupAction($_POST['hangup_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            }
        }

        $actions = HangupActionRepository::allWithAnnouncementTitle();
        $announcements = HangupActionRepository::allAnnouncementsForDropdown();

        $page_title = t('end_call.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('end_call/index', [
            'actions' => $actions,
            'announcements' => $announcements,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
