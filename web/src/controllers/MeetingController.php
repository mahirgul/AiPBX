<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/MeetingService.php';

/**
 * Controller for Video Meetings management (/meetings).
 */
class MeetingController extends BaseController
{
    public static function index(): void
    {
        static::requireLogin();
        static::requireModule('meetings', 'access');

        $canEdit = hasModulePermission('meetings', 'edit');
        $canDelete = hasModulePermission('meetings', 'delete');

        if (isset($_GET['ics'])) {
            $meetingId = (int)$_GET['ics'];
            $m = MeetingService::getMeeting($meetingId);
            if ($m) {
                $ics = MeetingService::generateIcs($m);
                header('Content-Type: text/calendar; charset=utf-8');
                header('Content-Disposition: attachment; filename="meeting-' . $m['room_number'] . '.ics"');
                echo $ics;
                exit;
            }
        }

        $userId = $_SESSION['user_id'] ?? null;
        $notices = static::handlePost([
            'save_meeting' => function() use ($userId) {
                if (!static::verifyCsrf()) {
                    return ['success' => false, 'error' => t('common.invalid_csrf')];
                }
                return MeetingService::saveMeeting($_POST, $userId ? (int)$userId : null);
            },
            'cancel_meeting' => function() use ($userId) {
                if (!static::verifyCsrf()) {
                    return ['success' => false, 'error' => t('common.invalid_csrf')];
                }
                $meetingId = (int)($_POST['meeting_id'] ?? 0);
                $ok = MeetingService::cancelMeeting($meetingId, $userId ? (int)$userId : null);
                return $ok ? ['success' => true, 'message' => t('meetings.msg_cancelled')] : ['success' => false, 'error' => t('meetings.err_cancel_failed')];
            },
            'add_invite' => function() {
                if (!static::verifyCsrf()) {
                    return ['success' => false, 'error' => t('common.invalid_csrf')];
                }
                $meetingId = (int)($_POST['meeting_id'] ?? 0);
                $email = (string)($_POST['email'] ?? '');
                $name = (string)($_POST['name'] ?? '');
                return MeetingService::addInvite($meetingId, $email, $name);
            },
        ]);

        $meetings = MeetingService::getMeetings();
        $pageTitle = t('meetings.title');

        static::renderPage('meetings/index', [
            'meetings' => $meetings,
            'can_edit' => $canEdit,
            'can_delete' => $canDelete,
            'csrf_token' => getCSRFToken(),
        ], ['title' => $pageTitle] + $notices);
    }
}
