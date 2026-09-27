<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/auth_helper.php';

$token = getMobileBearerToken();
$user = validateMobileToken($token);

if (!$user) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Oturum süresi dolmuş veya geçersiz token.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($user['is_active'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Kullanıcı hesabı devre dışıdır.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Giriş yanıtıyla birebir aynı paket (yeni 30 günlük token, SIP, TURN, push).
// Önceden burada ayrı bir kopyası vardı ve native_sip_username/sip_port/email
// alanları eksikti.
echo json_encode(buildMobileLoginResponse($user), JSON_UNESCAPED_UNICODE);
