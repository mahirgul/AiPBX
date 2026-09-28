<?php
/**
 * System Update API (admin only).
 *   GET  ?action=status  → state + log tail (the page polls progress)
 *   POST action=check    → is a newer release available on GitHub
 *   POST action=start    → start the update in the background (optional allow_calls=1)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/SystemUpdateService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'status') {
    echo json_encode([
        'success' => true,
        'current' => SystemUpdateService::currentVersion(),
        'status' => SystemUpdateService::status(),
        'check' => SystemUpdateService::lastCheck(),
        'log' => SystemUpdateService::logTail(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}
if (!verifyCSRFToken($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

if ($action === 'check') {
    echo json_encode(SystemUpdateService::check(), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($action === 'start') {
    echo json_encode(SystemUpdateService::start(!empty($_POST['allow_calls'])), JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action']);
