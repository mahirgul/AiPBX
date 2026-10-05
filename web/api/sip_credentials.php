<?php
// Returns the SIP credentials of the signed-in user.
// The password needed for the WebRTC softphone registration is no longer
// embedded in the page source; it is handed out only after session + CSRF checks.
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

// TURN REST API: a user-specific, time-limited credential valid for 1 hour
// (HMAC-SHA1 with the coturn static-auth-secret) — no static TURN password ever
// exists in code/JS; a fresh one is generated before every call.
$turn = null;
if (defined('TURN_SECRET') && TURN_SECRET !== '') {
    $turn_username = (time() + 3600) . ':' . ($user['extension'] ?? 'guest');
    $turn_password = base64_encode(hash_hmac('sha1', $turn_username, TURN_SECRET, true));
    $turn = [
        'username' => $turn_username,
        'credential' => $turn_password,
        // TURNS (TLS/TCP) only — plain STUN/TURN (UDP/plain TCP) was removed
        // because the network edge filters it by protocol signature (verified
        // with an external test, 2026-08-20). Extra candidate attempts slowed
        // down ICE gathering; the single path verified to work was kept.
        'urls' => [
            'turns:' . TURN_HOST . ':' . TURNS_PORT . '?transport=tcp',
        ]
    ];
}

echo json_encode([
    'success' => true,
    'extension' => $user['extension'] ?? '',
    // Dual endpoint: the WebRTC side's credential username is "<extension><suffix>";
    // the suffix comes from sys_settings; standard SIP devices use "<extension>".
    'sip_username' => ($user['extension'] ?? '') . $webrtc_suffix,
    'sip_password' => $user['sip_password'] ?? '',
    'turn' => $turn
]);
