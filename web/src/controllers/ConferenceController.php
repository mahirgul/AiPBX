<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/ConferenceService.php';

class ConferenceController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost()) {
            if (isset($_POST['save_conference'])) {
                $res = ConferenceService::saveConference($_POST);
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['delete_conference'])) {
                $res = ConferenceService::deleteConference($_POST['conference_id'] ?? 0, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['kick_member'])) {
                $res = ConferenceService::kickMember($_POST['room_number'] ?? '', $_POST['channel'] ?? '', $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            } elseif (isset($_POST['mute_member'])) {
                $mute = ($_POST['mute_action'] ?? 'mute') === 'mute';
                $res = ConferenceService::muteMember($_POST['room_number'] ?? '', $_POST['channel'] ?? '', $mute, $_POST['csrf_token'] ?? '');
                if ($res['success']) $message = $res['message']; else $error = $res['error'];
            }
        }

        $conferences = ConferenceService::getConferences();

        $page_title = t('conferences.title', 'Konferans Odaları');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('conferences/index', [
            'conferences' => $conferences,
            'message' => $message,
            'error' => $error,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
