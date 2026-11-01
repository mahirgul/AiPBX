<?php

declare(strict_types=1);

/**
 * Loopback API endpoint for the [app-meeting-guest] dialplan context.
 * Called by Asterisk CURL() when a browser participant of /meet/{token} calls a room.
 * Returns "JOIN:<participant limit>", or "DENY" when the call must be refused.
 */
// Asterisk on this server only; a request that came through a proxy is not it.
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)
    || !empty($_SERVER['HTTP_X_FORWARDED_FOR']) || !empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
    http_response_code(403);
    echo "DENY\n";
    exit(0);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/MeetingService.php';

$room = preg_replace('/[^0-9]/', '', (string)($_GET['room'] ?? '')) ?? '';
$token = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($_GET['token'] ?? '')) ?? '';

$limit = MeetingService::joinCheck($token, $room);
echo ($limit > 0 ? "JOIN:{$limit}" : 'DENY') . "\n";
exit(0);
