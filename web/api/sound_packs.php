<?php
/**
 * Asterisk sound packs API (admin only) — Sounds page, "Asterisk sound packs" tab.
 *   GET  ?action=status                          → installed packs + state + log tail (the tab polls it)
 *   POST action=install|remove kind lang format  → start in the background
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/SoundPackService.php';

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
        'installed' => SoundPackService::installed(),
        'status' => SoundPackService::status(),
        'log' => SoundPackService::logTail(),
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

if ($action === 'install' || $action === 'remove') {
    echo json_encode(SoundPackService::start(
        $action,
        (string) ($_POST['kind'] ?? ''),
        (string) ($_POST['lang'] ?? ''),
        (string) ($_POST['format'] ?? '')
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action']);
