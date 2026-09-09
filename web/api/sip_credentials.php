<?php
// Oturumlu kullanıcıya ait SIP kimlik bilgilerini döndürür.
// WebRTC softphone kaydı için lazım olan parola artık sayfa kaynağına
// gömülmüyor; yalnızca oturum + CSRF doğrulaması geçerse verilir.
require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCSRFToken($csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Geçersiz CSRF doğrulama kodu']);
        exit;
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$db = getDB();
$stmt = $db->prepare('SELECT extension, sip_password, extension_type, is_active FROM sys_users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Kullanıcı bulunamadı']);
    exit;
}

if (($user['extension_type'] ?? '') !== 'sip' || empty($user['is_active'])) {
    echo json_encode([
        'success' => false,
        'disabled' => true,
        'error' => 'WebRTC softphone bu kullanıcı türü için etkin değildir'
    ]);
    exit;
}

$webrtc_suffix = getSystemSetting('webrtc_username_suffix', '-webrtc');

// TURN REST API: 1 saat geçerli, kullanıcıya özel zaman-sınırlı kimlik bilgisi
// (coturn static-auth-secret ile HMAC-SHA1) — statik TURN parolası kodda/JS'te
// hiç bulunmaz, her çağrı öncesi taze üretilir.
$turn = null;
if (defined('TURN_SECRET') && TURN_SECRET !== '') {
    $turn_username = (time() + 3600) . ':' . ($user['extension'] ?? 'guest');
    $turn_password = base64_encode(hash_hmac('sha1', $turn_username, TURN_SECRET, true));
    $turn = [
        'username' => $turn_username,
        'credential' => $turn_password,
        // Sadece TURNS (TLS/TCP) — düz STUN/TURN (UDP/plain TCP) ağ
        // kenar cihazında protokol imzasından filtrelendiği için (dış test ile
        // doğrulandı, 2026-08-20) kaldırıldı. Ekstra aday denemesi ICE gathering'i
        // yavaşlatıyordu, tek çalıştığı doğrulanmış yol bırakıldı.
        'urls' => [
            'turns:' . TURN_HOST . ':' . TURNS_PORT . '?transport=tcp',
        ]
    ];
}

echo json_encode([
    'success' => true,
    'extension' => $user['extension'] ?? '',
    // Dual-Endpoint: WebRTC tarafının kimlik kullanıcı adı "<dahili><suffix>";
    // suffix sys_settings'ten gelir; standart SIP cihazları "<dahili>" kullanır.
    'sip_username' => ($user['extension'] ?? '') . $webrtc_suffix,
    'sip_password' => $user['sip_password'] ?? '',
    'turn' => $turn
]);
