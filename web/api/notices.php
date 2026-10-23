<?php
/**
 * The top bar's bell (administrators): GET lists the open problems and the
 * recent changes, POST action=seen marks them seen for the signed-in user.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/NoticeService.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => t('api.err_no_permission')]);
    return;
}
$userId = (int) ($_SESSION['user_id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $in = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
    if (!verifyCSRFToken((string) ($in['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => t('pending_sync.msg_csrf_error')]);
        return;
    }
    if (($in['action'] ?? '') !== 'seen') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => t('api.err_invalid_action')]);
        return;
    }
    NoticeService::markSeen($userId);
    echo json_encode(['success' => true]);
    return;
}

echo json_encode(['success' => true] + NoticeService::forUser($userId));
