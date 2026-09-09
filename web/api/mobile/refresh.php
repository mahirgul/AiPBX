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

// 1. Yeni 30 günlük Bearer Token
$new_token = generateMobileToken($user, 30 * 86400);

// 2. Güncel Coturn TURNS Kimliği
$turn = null;
if (defined('TURN_SECRET') && TURN_SECRET !== '') {
    $turn_username = (time() + 2592000) . ':' . $user['extension'];
    $turn_password = base64_encode(hash_hmac('sha1', $turn_username, TURN_SECRET, true));
    $turn_host = defined('TURN_HOST') && TURN_HOST !== '' ? TURN_HOST : ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
    $turn_port = defined('TURNS_PORT') && TURNS_PORT !== '' ? TURNS_PORT : '443';
    $turn = [
        'username' => $turn_username,
        'credential' => $turn_password,
        'urls' => [
            'turns:' . $turn_host . ':' . $turn_port . '?transport=tcp'
        ]
    ];
}

$host = $_SERVER['HTTP_HOST'] ?? getSystemSetting('portal_domain', 'localhost');
$host_parts = explode(':', $host);
$domain = $host_parts[0];
$ws_path = getSystemSetting('pjsip_ws_path', '/ws');

$push_config = [
    'enabled' => getSystemSetting('push_enabled', '0') === '1',
    'provider' => getSystemSetting('push_provider', 'none'),
    'fcm_project_id' => getSystemSetting('push_fcm_project_id', ''),
    'fcm_app_id' => getSystemSetting('push_fcm_app_id', ''),
    'fcm_api_key' => getSystemSetting('push_fcm_api_key', ''),
    'fcm_sender_id' => getSystemSetting('push_fcm_sender_id', ''),
];

echo json_encode([
    'success' => true,
    'token' => $new_token,
    'user' => [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'extension' => $user['extension'],
        'role' => $user['role']
    ],
    'sip' => [
        'extension' => $user['extension'],
        'sip_username' => $user['extension'] . '-mob-webrtc',
        'sip_password' => $user['sip_password'] ?? '',
        'domain' => $domain,
        'ws_url' => 'wss://' . $domain . $ws_path,
        'turn' => $turn
    ],
    'push_config' => $push_config
], JSON_UNESCAPED_UNICODE);
