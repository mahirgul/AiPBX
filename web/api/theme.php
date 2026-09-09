<?php
require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$csrf = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCSRFToken($csrf)) {
    echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
    exit;
}

$theme = $input['theme'] ?? 'light';

if (!in_array($theme, ['dark', 'light'])) {
    $theme = 'light';
}

$db = getDB();
$stmt = $db->prepare('UPDATE sys_users SET theme_preference = ? WHERE id = ?');
$stmt->execute([$theme, $_SESSION['user_id']]);

echo json_encode(['success' => true, 'theme' => $theme]);
