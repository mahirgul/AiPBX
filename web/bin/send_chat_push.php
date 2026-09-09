<?php
/**
 * CLI Push Notification Sender for AI-PBX Chat
 * Usage: php /var/www/html/bin/send_chat_push.php <extension> <title> <body> [action] [extra_json]
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/services/push/PushService.php';

use App\Services\Push\PushService;

$ext = $argv[1] ?? '';
$title = $argv[2] ?? '';
$body = $argv[3] ?? '';
$action = $argv[4] ?? 'new_message';
$extraJson = $argv[5] ?? '{}';

if ($ext === '') {
    echo json_encode(['success' => false, 'error' => 'Missing extension']);
    exit(1);
}

$extra = json_decode($extraJson, true) ?: [];
$payload = array_merge($extra, [
    'action' => $action,
    'title' => $title,
    'body' => $body,
]);

$provider = PushService::getProvider();
$result = $provider->sendToExtension($ext, $payload);
echo json_encode($result);
