<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/ai/AiGateway.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
session_write_close();
try {
    $result = AiGateway::execute('chat', (string) ($_POST['selection'] ?? ''), (string) ($_POST['prompt'] ?? ''), 'panel');
    echo json_encode(['success' => true, 'text' => $result['output'], 'model' => $result['resolved_model_ref'], 'ms' => $result['timings']['total_ms']], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code($e instanceof AiGatewayException && $e->errorCode === 'permission_denied' ? 403 : 422);
    echo json_encode(['success' => false, 'error' => $e instanceof AiGatewayException ? $e->userMessage() : t('ai_gateway.error_unavailable')]);
}
