<?php
/** POST: the signed-in user closes their welcome card for good. */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/WelcomeService.php';

header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id']) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || !verifyCSRFToken($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}
WelcomeService::dismiss((int) $_SESSION['user_id']);
echo json_encode(['success' => true]);
