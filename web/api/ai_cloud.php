<?php
/**
 * AI → Cloud services API (admin only).
 *   POST action=test provider=   → a cheap authenticated call; form fields typed but not yet saved override the stored account
 *   POST action=discover provider= [refresh=1] → the provider's models and what each is for (cached 15 minutes)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/ai/CloudAiService.php';
require_once __DIR__ . '/../src/services/ai/AiAccountTester.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!hasModulePermission('ai_cloud', 'edit') && !userHasRole('admin')) {
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
    $action = (string) ($_POST['action'] ?? '');
    $id = (string) ($_POST['provider'] ?? '');
    $isNew = !empty($_POST['is_new']);
    if (!in_array($action, ['test', 'discover'], true)) {
        throw new CloudAiException('Unknown action');
    }
    if (!CloudAiService::configuredForTest($id, $_POST, $isNew)) {
        throw new CloudAiException(t('ai_tts.not_configured'));
    }
    if ($action === 'discover') {
        $res = CloudAiService::discoverModels($id, $_POST, !empty($_POST['refresh']), $isNew);
        echo json_encode(['success' => true, 'models' => $res['models'], 'kinds' => (object) $res['kinds'], 'cached' => $res['cached']], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => true, 'message' => AiAccountTester::test($id, $_POST, $isNew)], JSON_UNESCAPED_UNICODE);
    }
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => mb_substr($e instanceof AiGatewayException ? $e->userMessage() : $e->getMessage(), 0, 300)], JSON_UNESCAPED_UNICODE);
}
