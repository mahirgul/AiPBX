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

        $defaultName = $invite['name'] ?? ($_SESSION['full_name'] ?? '');

        // Render standalone WebRTC meeting room layout
        require __DIR__ . '/../../templates/views/meetings/lobby.php';
    }
}
