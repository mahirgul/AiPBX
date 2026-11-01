<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/MeetingService.php';

/**
 * Public Meeting Gateway and Lobby Controller (/meet/{token}).
 * Open to anonymous guests and subscribers.
 */
class MeetingLobbyController
{
    public static function index(string $token): void
    {
        $info = MeetingService::resolveToken($token);

        if (!$info) {
            http_response_code(404);
            require_once __DIR__ . '/../../config.php';
            renderFatalErrorPage(
                t('meetings.err_not_found_title', 'Meeting Not Found'),
                t('meetings.err_not_found_desc', 'This video meeting does not exist or has been cancelled.'),
                "Invalid meeting token: {$token}",
                404
            );
            return;
        }

        $meeting = $info['meeting'];
        $invite = $info['invite'];
        $role = $info['role'];

        if (empty($meeting['allow_guests']) && $role !== 'guest' && empty($_SESSION['user_id'])) {
            http_response_code(403);
            require_once __DIR__ . '/../../config.php';
            renderFatalErrorPage(
                t('meetings.err_not_found_title', 'Access Denied'),
                t('meetings.err_not_found_desc', 'External guest access is disabled for this video meeting.'),
                "Guest access disabled for meeting token: {$token}",
                403
            );
            return;
        }

        $defaultName = $invite['name'] ?? ($_SESSION['full_name'] ?? '');

        // Render standalone WebRTC meeting room layout
        require __DIR__ . '/../../templates/views/meetings/lobby.php';
    }
}
