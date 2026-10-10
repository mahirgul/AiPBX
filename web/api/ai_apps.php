<?php
/**
 * AI → Applications API (admin only).
 *   GET  ?action=audio&uuid=           → the caller's recording of a voice request (audio/wav)
 *   POST action=handled id= handled=0|1 → mark a request in the log as handled
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/ai/AiAppService.php';

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit;
}
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'audio') {
    $uuid = strtolower((string) ($_GET['uuid'] ?? ''));
    if (!preg_match('/^[0-9a-f-]{36}$/', $uuid)) {
        http_response_code(400);
        exit;
    }
    session_write_close();
    try {
        $wav = LocalAiService::callAudio($uuid);
    } catch (\Throwable $e) {
        $wav = '';
    }
    if ($wav === '') {
        http_response_code(404);
        exit;
    }
    header('Content-Type: audio/wav');
    header('Content-Length: ' . strlen($wav));
    header('Cache-Control: private, max-age=300');
    echo $wav;
    exit;
}

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => t('common.invalid_csrf')]);
    exit;
}
if ($action === 'handled') {
    AiAppService::markHandled((int) ($_POST['id'] ?? 0), !empty($_POST['handled']));
    echo json_encode(['success' => true]);
    exit;
}
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action']);
