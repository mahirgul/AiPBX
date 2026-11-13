<?php

declare(strict_types=1);

/**
 * Loopback endpoint for the [chat-calls-pbx] dialplan context (a conference
 * started from a group chat). Asterisk asks with CURL():
 *   event=join    may this endpoint enter the room? "JOIN:<limit>:<video mode>" or "DENY"
 *   event=parties how many are in the room now (0: the last one left)
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
require_once dirname(__DIR__) . '/src/services/ChatCallService.php';

$room = preg_replace('/[^0-9]/', '', (string) ($_GET['room'] ?? '')) ?? '';

if (($_GET['event'] ?? '') === 'parties') {
    ChatCallService::parties($room, (int) preg_replace('/[^0-9]/', '', (string) ($_GET['parties'] ?? '0')));
    echo "OK\n";
    exit(0);
}

$endpoint = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_GET['endpoint'] ?? '')) ?? '';
echo ChatCallService::joinCheck($room, $endpoint) . "\n";
exit(0);
