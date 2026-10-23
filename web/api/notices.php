<?php
/**
 * The top bar's notice cabinet: GET lists the newest notices, POST marks one
 * (or all) read.
 *
 * Administrator notices: a squawk about a trunk, a disk or a carrier is not a
 * phone-screen fact, so this endpoint is locked to the administrator role like
 * the other admin-only APIs. Read state is per person — the id below is the
 * signed-in administrator, never the client's.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/NoticeService.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => t('common.invalid_csrf')]);
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'read_all') {
        NoticeService::markAllRead($userId);
    } elseif ($action === 'read') {
        NoticeService::markRead((int) ($_POST['id'] ?? 0), $userId);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown action']);
        exit;
    }
    echo json_encode(['success' => true, 'unread' => NoticeService::unreadCount($userId)], JSON_UNESCAPED_UNICODE);
    exit;
}

$limit = (int) ($_GET['limit'] ?? 20);
$notices = [];
foreach (NoticeService::recent($userId, $limit) as $row) {
    [$colour, $icon] = NoticeService::look((string) $row['severity']);
    $notices[] = [
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'body' => (string) $row['body'],
        'severity' => (string) $row['severity'],
        'colour' => $colour,
        'icon' => $icon,
        'source' => (string) $row['source'],
        'created_at' => (string) $row['created_at'],
        'read' => (bool) $row['is_read'],
        'emailed' => $row['emailed_at'] !== null,
    ];
}

echo json_encode([
    'success' => true,
    'unread' => NoticeService::unreadCount($userId),
    'notices' => $notices,
], JSON_UNESCAPED_UNICODE);
