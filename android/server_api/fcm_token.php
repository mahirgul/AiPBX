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

    $fcmToken = trim($json['fcm_token'] ?? $_POST['fcm_token'] ?? '');
    $deviceId = trim($json['device_id'] ?? $_POST['device_id'] ?? '');
    $deviceName = trim($json['device_name'] ?? $_POST['device_name'] ?? '');
    $platform = trim($json['platform'] ?? $_POST['platform'] ?? 'android');
    $appVersion = trim($json['app_version'] ?? $_POST['app_version'] ?? '');

    if ($fcmToken === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'fcm_token parametresi zorunludur.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Var olan kaydı bul: Önce device_id'ye göre, yoksa fcm_token'a göre
    $existing = null;
    if ($deviceId !== '') {
        $stmt = $db->prepare("SELECT id FROM sys_mobile_devices WHERE user_id = ? AND device_id = ? LIMIT 1");
        $stmt->execute([$userId, $deviceId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$existing) {
        $stmt = $db->prepare("SELECT id FROM sys_mobile_devices WHERE fcm_token = ? LIMIT 1");
        $stmt->execute([$fcmToken]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($existing) {
        $stmt = $db->prepare("UPDATE sys_mobile_devices 
                              SET user_id = ?, extension = ?, fcm_token = ?, 
                                  device_id = IF(? != '', ?, device_id),
                                  device_name = IF(? != '', ?, device_name),
                                  platform = ?, app_version = ?, is_active = 1, updated_at = NOW()
                              WHERE id = ?");
        $stmt->execute([
            $userId, $extension, $fcmToken,
            $deviceId, $deviceId,
            $deviceName, $deviceName,
            $platform, $appVersion,
            $existing['id']
        ]);
        $id = $existing['id'];
    } else {
        $stmt = $db->prepare("INSERT INTO sys_mobile_devices 
                              (user_id, extension, fcm_token, device_id, device_name, platform, app_version, is_active, created_at, updated_at) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");
        $stmt->execute([
            $userId, $extension, $fcmToken,
            $deviceId !== '' ? $deviceId : null,
            $deviceName !== '' ? $deviceName : null,
            $platform,
            $appVersion !== '' ? $appVersion : null
        ]);
        $id = $db->lastInsertId();
    }

    echo json_encode([
        'success' => true,
        'message' => 'FCM token başarıyla kaydedildi.',
        'device_id' => $deviceId,
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