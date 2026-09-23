<?php
/**
 * Mobile Google OAuth / ID Token Giriş Uç Noktası
 * Android ve iOS uygulamalarının Google ile şifresiz oturum açmasını sağlar.
 */
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
require_once __DIR__ . '/../../src/services/GoogleAuthService.php';

$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

$input_raw = file_get_contents('php://input');
$json = json_decode($input_raw, true) ?: [];

$idToken = trim($json['id_token'] ?? $_POST['id_token'] ?? '');
$code = trim($json['code'] ?? $_POST['code'] ?? '');
$device_name = trim($json['device_name'] ?? $_POST['device_name'] ?? 'Mobile');

if (empty($idToken) && empty($code)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Google id_token veya authorization code parametresi gereklidir.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userInfo = null;

// 1. Google ID Token doğrulaması
if (!empty($idToken)) {
    $userInfo = GoogleAuthService::verifyIdToken($idToken);
}

// 2. Veya Google Authorization Code takası
if (!$userInfo && !empty($code)) {
    $tokens = GoogleAuthService::exchangeCode($code);
    if (!empty($tokens['id_token'])) {
        $userInfo = GoogleAuthService::verifyIdToken($tokens['id_token']);
    } elseif (!empty($tokens['access_token'])) {
        $userInfo = GoogleAuthService::getUserInfo($tokens['access_token']);
    }
}

if (!$userInfo || empty($userInfo['email'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Google kimlik doğrulaması başarısız veya e-posta adresi doğrulanamadı.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$email = $userInfo['email'];

// 3. E-posta adresi ile veritabanında aktif kullanıcıyı eşleştir
$user = GoogleAuthService::findUserByEmail($email);

if (!$user) {
    if (function_exists('logLoginAttempt')) {
        logLoginAttempt($client_ip, $email, 'FAILED');
    }
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => "Google hesabınız ({$email}) ile eşleşen bir AiPBX dahili kullanıcısı bulunamadı."
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

if (empty($user['extension'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Bu kullanıcıya atanmış bir dahili numara bulunmamaktadır.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Giriş başarılı: log kaydet
if (function_exists('logLoginAttempt')) {
    logLoginAttempt($client_ip, $user['username'], 'SUCCESS');
}
if (function_exists('writeAuditLog')) {
    writeAuditLog($user['id'], 'sys_users', $user['id'], "Kullanıcı '{$user['username']}' Mobil Uygulamadan Google ({$email}) ile giriş yaptı.", 'google_mobile_login');
}

// Standart mobil giriş yanıtını döndür
$response = buildMobileLoginResponse($user);
echo json_encode($response, JSON_UNESCAPED_UNICODE);
