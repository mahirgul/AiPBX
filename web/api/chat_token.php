<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/mobile/auth_helper.php';

header('Content-Type: application/json; charset=utf-8');

$user = getCurrentUser();
if (!$user || empty($user['extension'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Aktif dahili bulunamadı.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = generateMobileToken($user);

echo json_encode([
    'success' => true,
    'token' => $token,
    'user' => [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'extension' => (string)$user['extension'],
        'full_name' => (string)($user['full_name'] ?? $user['username']),
        'role' => (string)$user['role']
    ]
], JSON_UNESCAPED_UNICODE);
