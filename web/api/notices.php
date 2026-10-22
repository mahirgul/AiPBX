<?php
/**
 * The top bar's notice cabinet: GET lists the newest notices, POST marks one
 * (or all) read. Per install, per session — any signed-in person may read.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/NoticeService.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => t('common.invalid_csrf')]);
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'read_all') {
        NoticeService::markAllRead();
    } elseif ($action === 'read') {
        NoticeService::markRead((int) ($_POST['id'] ?? 0));
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown action']);
        exit;
    }
    echo json_encode(['success' => true, 'unread' => NoticeService::unreadCount()], JSON_UNESCAPED_UNICODE);
    exit;
}

$limit = (int) ($_GET['limit'] ?? 20);
$notices = [];
foreach (NoticeService::recent($limit) as $row) {
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
        'read' => $row['read_at'] !== null,
        'emailed' => $row['emailed_at'] !== null,
    ];
}

echo json_encode([
    'success' => true,
    'unread' => NoticeService::unreadCount(),
    'notices' => $notices,
], JSON_UNESCAPED_UNICODE);
