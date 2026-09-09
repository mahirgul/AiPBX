<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/../../src/core/BaseRepository.php';

$user = requireMobileAuth();
$userId = (int)$user['id'];
$extension = trim($user['extension'] ?? '');

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_raw = file_get_contents('php://input');
    $json = json_decode($input_raw, true) ?: [];

    $fcmTokenRaw = trim($json['fcm_token'] ?? $_POST['fcm_token'] ?? '');
    $deviceId = trim($json['device_id'] ?? $_POST['device_id'] ?? '');
    $deviceName = trim($json['device_name'] ?? $_POST['device_name'] ?? '');
    $platform = trim($json['platform'] ?? $_POST['platform'] ?? 'android');
    $appVersion = trim($json['app_version'] ?? $_POST['app_version'] ?? '');

    // Sahte/test tokenları veya boş değerleri null olarak değerlendir
    $fcmToken = ($fcmTokenRaw === '' || str_starts_with($fcmTokenRaw, 'device_') || str_starts_with($fcmTokenRaw, 'test_')) ? null : $fcmTokenRaw;
    $pushType = ($fcmToken !== null) ? 'fcm' : 'none';

    if ($deviceId === '' && $fcmToken === null) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'device_id veya fcm_token parametresi zorunludur.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Var olan kaydı bul: Önce device_id'ye göre, yoksa fcm_token'a göre
    $existing = null;
    if ($deviceId !== '') {
        $stmt = $db->prepare("SELECT id, fcm_token FROM sys_mobile_devices WHERE user_id = ? AND device_id = ? LIMIT 1");
        $stmt->execute([$userId, $deviceId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$existing && $fcmToken !== null) {
        // Ayni FCM tokeni BASKA bir kullanicida kayitliysa o satir DEVRALINMAZ.
        $stmt = $db->prepare("SELECT id, user_id FROM sys_mobile_devices WHERE fcm_token = ? LIMIT 1");
        $stmt->execute([$fcmToken]);
        $yabanci = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($yabanci && (int) $yabanci['user_id'] === (int) $userId) {
            $existing = $yabanci;
        } elseif ($yabanci) {
            $db->prepare("UPDATE sys_mobile_devices 
                             SET is_active = 0, 
                                 fcm_token = CONCAT('revoked:', id, ':', UNIX_TIMESTAMP()), 
                                 push_type = 'none',
                                 updated_at = NOW() 
                           WHERE id = ?")
               ->execute([$yabanci['id']]);
            error_log("fcm_token.php: token yeniden atandi, eski kayit pasiflendi (id={$yabanci['id']}, eski user_id={$yabanci['user_id']})");
        }
    }

    if ($existing) {
        // Eger yeni token null ise ve eskiden kayitli token varsa, eski tokeni koru
        $tokenToSave = ($fcmToken !== null) ? $fcmToken : ($existing['fcm_token'] ?: null);
        $typeToSave = ($tokenToSave !== null) ? 'fcm' : 'none';

        $stmt = $db->prepare("UPDATE sys_mobile_devices 
                              SET user_id = ?, extension = ?, fcm_token = ?, push_type = ?,
                                  device_id = IF(? != '', ?, device_id),
                                  device_name = IF(? != '', ?, device_name),
                                  platform = ?, app_version = ?, is_active = 1, updated_at = NOW()
                              WHERE id = ?");
        $stmt->execute([
            $userId, $extension, $tokenToSave, $typeToSave,
            $deviceId, $deviceId,
            $deviceName, $deviceName,
            $platform, $appVersion,
            $existing['id']
        ]);
        $id = $existing['id'];
    } else {
        $stmt = $db->prepare("INSERT INTO sys_mobile_devices 
                              (user_id, extension, fcm_token, push_type, device_id, device_name, platform, app_version, is_active, created_at, updated_at) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");
        $stmt->execute([
            $userId, $extension, $fcmToken, $pushType,
            $deviceId !== '' ? $deviceId : null,
            $deviceName !== '' ? $deviceName : null,
            $platform,
            $appVersion !== '' ? $appVersion : null
        ]);
        $id = $db->lastInsertId();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Cihaz ve bildirim bilgisi başarıyla kaydedildi.',
        'device_id' => $deviceId,
        'push_type' => $pushType,
        'id' => (int)$id
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $input_raw = file_get_contents('php://input');
    $json = json_decode($input_raw, true) ?: [];

    $fcmToken = trim($json['fcm_token'] ?? $_GET['fcm_token'] ?? '');
    $deviceId = trim($json['device_id'] ?? $_GET['device_id'] ?? '');

    if ($deviceId !== '') {
        $stmt = $db->prepare("UPDATE sys_mobile_devices SET is_active = 0, updated_at = NOW() WHERE user_id = ? AND device_id = ?");
        $stmt->execute([$userId, $deviceId]);
    } elseif ($fcmToken !== '') {
        $stmt = $db->prepare("UPDATE sys_mobile_devices SET is_active = 0, updated_at = NOW() WHERE user_id = ? AND fcm_token = ?");
        $stmt->execute([$userId, $fcmToken]);
    } else {
        $stmt = $db->prepare("UPDATE sys_mobile_devices SET is_active = 0, updated_at = NOW() WHERE user_id = ?");
        $stmt->execute([$userId]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Cihaz kaydı pasife alındı.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// GET - Kullanıcıya ait aktif cihazları listele
$stmt = $db->prepare("SELECT id, extension, device_id, device_name, platform, app_version, is_active, created_at, updated_at 
                      FROM sys_mobile_devices 
                      WHERE user_id = ? AND is_active = 1 
                      ORDER BY updated_at DESC");
$stmt->execute([$userId]);
$devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'total' => count($devices),
    'devices' => $devices
], JSON_UNESCAPED_UNICODE);