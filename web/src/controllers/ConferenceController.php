<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/ConferenceService.php';

class ConferenceController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'save_conference' => fn() => ConferenceService::saveConference($_POST),
            'delete_conference' => fn() => ConferenceService::deleteConference($_POST['conference_id'] ?? 0, static::csrfToken()),
            'kick_member' => fn() => ConferenceService::kickMember($_POST['room_number'] ?? '', $_POST['channel'] ?? '', static::csrfToken()),
            'mute_member' => fn() => ConferenceService::muteMember(
                $_POST['room_number'] ?? '',
                $_POST['channel'] ?? '',
                ($_POST['mute_action'] ?? 'mute') === 'mute',
                static::csrfToken()
            ),
        ]);

        $conferences = ConferenceService::getConferences();

        $page_title = t('conferences.title', 'Konferans Odaları');
        static::renderPage('conferences/index', [
            'conferences' => $conferences,
        ], ['title' => $page_title] + $notices);
    }
}
