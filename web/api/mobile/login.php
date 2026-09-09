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

require_once __DIR__ . '/auth_helper.php';

$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

$input_raw = file_get_contents('php://input');
$json = json_decode($input_raw, true) ?: [];

$username = trim($json['username'] ?? $_POST['username'] ?? '');
$password = trim($json['password'] ?? $_POST['password'] ?? '');
$device_name = trim($json['device_name'] ?? $_POST['device_name'] ?? 'Android');

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Kullanıcı adı ve şifre gereklidir.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Brute Force Kilitleme Kontrolü (5 hatalı deneme -> 15 dakika kilit)
if (checkBruteForceLockout($client_ip, $username)) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Çok fazla hatalı deneme yapıldı! Hesabınız ve IP adresiniz 15 dakika süreyle kilitlenmiştir.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = getDB();
// Kullanici adi ve dahili ayni alanda kabul ediliyor. Bir kullanicinin
// username'i baska birinin extension'ina esitse hangi satirin donecegi
// garanti degildi (bulgular.md 1.7). Kullanici adi eslesmesi onceliklidir.
$stmt = $db->prepare('SELECT id, username, password_hash, full_name, role, extension, extension_type, sip_password, is_active
                      FROM sys_users
                      WHERE username = ? OR extension = ?
                      ORDER BY (username = ?) DESC, id ASC
                      LIMIT 1');
$stmt->execute([$username, $username, $username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    logLoginAttempt($client_ip, $username, 'FAILED');
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Geçersiz kullanıcı adı veya şifre!'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($user['is_active'])) {
    logLoginAttempt($client_ip, $username, 'FAILED');
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Kullanıcı hesabı devre dışıdır.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($user['extension'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Bu kullanıcıya atanmış bir dahili numara bulunmamaktadır.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Giriş başarılı: oturum denemesini logla
logLoginAttempt($client_ip, $username, 'SUCCESS');

$webrtc_suffix = getSystemSetting('webrtc_username_suffix', '-webrtc');
$ws_path = getSystemSetting('pjsip_ws_path', '/ws');

// Coturn TURNS (TLS/TCP)
$turn = null;
if (defined('TURN_SECRET') && TURN_SECRET !== '') {
    // TURN kimlik süresi oturum süresiyle (30 gün / 2592000 sn) eşitleniyor —
    // 24 saat sonra kampüs dışı medyanın sessizce kopması engellenir.
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

$token = generateMobileToken($user, 30 * 86400);

$host = $_SERVER['HTTP_HOST'] ?? getSystemSetting('portal_domain', 'localhost');
$host_parts = explode(':', $host);
$domain = $host_parts[0];

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
    'token' => $token,
    'user' => [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'extension' => $user['extension'],
        'role' => $user['role']
    ],
    'sip' => [
        'extension' => $user['extension'],
        // Mobil WebRTC için özel 3. endpoint (<dahili>-mob-webrtc)
        'sip_username' => $user['extension'] . '-mob-webrtc',
        // Standart SIP (PJSIP / Linphone / UDP/TCP 5060) istemcileri için:
        'native_sip_username' => $user['extension'],
        // WebRTC (WSS / JsSIP / SIP.js) istemcileri için:
        'webrtc_username' => $user['extension'] . '-mob-webrtc',
        'sip_password' => $user['sip_password'] ?? '',
        'domain' => $domain,
        'sip_port' => 5060,
        'ws_url' => 'wss://' . $domain . $ws_path,
        'turn' => $turn
    ],
    'push_config' => $push_config
], JSON_UNESCAPED_UNICODE);
