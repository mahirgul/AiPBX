<?php
if (!defined('CC_DISPATCH_ACTIVE')) { http_response_code(403); exit; }
/** @var string $user_ext Authenticated extension supplied by the dispatcher. */
require_once __DIR__ . '/../../src/services/QueueCallbackService.php';
echo json_encode(['success' => true, 'callbacks' => QueueCallbackService::pending((string) $user_ext)]);
exit;
