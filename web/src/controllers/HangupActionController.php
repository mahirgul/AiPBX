<?php
require_once __DIR__ . '/../helpers.php';

class HangupActionController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_hangup_action' => fn() => HangupActionService::saveHangupAction($_POST),
            'delete_hangup_action' => fn() => HangupActionService::deleteHangupAction($_POST['hangup_id'] ?? 0, static::csrfToken()),
        ]);

        $actions = HangupActionRepository::allWithAnnouncementTitle();
        $announcements = HangupActionRepository::allAnnouncementsForDropdown();

        $page_title = t('end_call.title');
        static::renderPage('end_call/index', [
            'actions' => $actions,
            'announcements' => $announcements,
        ], ['title' => $page_title] + $notices);
    }
}
