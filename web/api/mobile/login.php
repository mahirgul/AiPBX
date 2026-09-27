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
$stmt = $db->prepare('SELECT id, username, password_hash, full_name, email, role, extension, extension_type, sip_password, is_active, two_factor_enabled, two_factor_secret
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

// İki adımlı doğrulama açık hesapta yalnızca şifre yetmez (önceden mobil
// giriş 2FA'yı tamamen atlıyordu). Kod gönderilmediyse uygulamaya kod
// istemesi söylenir; hatalı kod başarısız deneme sayılır (5 → 15 dk kilit).
if (!empty($user['two_factor_enabled'])) {
    require_once __DIR__ . '/../../src/services/TwoFactorService.php';
    $otp = trim((string)($json['otp'] ?? $_POST['otp'] ?? ''));
    if ($otp === '' || !TwoFactorService::verifyCode((string)$user['two_factor_secret'], $otp)) {
        if ($otp !== '') {
            logLoginAttempt($client_ip, $username, 'FAILED');
        }
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'otp_required' => true,
            'error' => $otp === ''
                ? 'Bu hesapta iki adımlı doğrulama açık. Doğrulama uygulamanızdaki 6 haneli kodu girin.'
                : 'Doğrulama kodu hatalı.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Giriş başarılı: oturum denemesini logla
logLoginAttempt($client_ip, $username, 'SUCCESS');

echo json_encode(buildMobileLoginResponse($user), JSON_UNESCAPED_UNICODE);
