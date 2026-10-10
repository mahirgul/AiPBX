<?php
/**
 * AI → Cloud services API (admin only).
 *   POST action=test provider=   → a cheap authenticated call with the stored key
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/ai/CloudAiService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => t('common.invalid_csrf')]);
    exit;
}
session_write_close();

try {
    if (($_POST['action'] ?? '') !== 'test') {
        throw new CloudAiException('Unknown action');
    }
    $id = (string) ($_POST['provider'] ?? '');
    if (!CloudAiService::configured($id)) {
        throw new CloudAiException(t('ai_tts.not_configured'));
    }
    echo json_encode(['success' => true, 'message' => CloudAiService::test($id)], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => mb_substr($e->getMessage(), 0, 300)], JSON_UNESCAPED_UNICODE);
}
