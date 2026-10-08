<?php
require_once __DIR__ . '/auth_helper.php';
mobileApiStart('GET, POST, DELETE, OPTIONS');

require_once __DIR__ . '/../../src/core/BaseRepository.php';

$user = requireMobileAuth();
$userId = (int)$user['id'];
$extension = trim($user['extension'] ?? '');

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = mobileInput();

    $fcmTokenRaw = trim($json['fcm_token'] ?? $_POST['fcm_token'] ?? '');
    $deviceId = trim($json['device_id'] ?? $_POST['device_id'] ?? '');
    $deviceName = trim($json['device_name'] ?? $_POST['device_name'] ?? '');
    $platform = trim($json['platform'] ?? $_POST['platform'] ?? 'android');
    $appVersion = trim($json['app_version'] ?? $_POST['app_version'] ?? '');

    // Treat fake/test tokens or empty values as null
    $fcmToken = ($fcmTokenRaw === '' || str_starts_with($fcmTokenRaw, 'device_') || str_starts_with($fcmTokenRaw, 'test_')) ? null : $fcmTokenRaw;

    // iOS sends its APNs device token in fcm_token with push_type = 'apns',
    // plus the PushKit VoIP token (incoming calls) in voip_token.
    $tokenType = (trim($json['push_type'] ?? $_POST['push_type'] ?? '') === 'apns') ? 'apns' : 'fcm';
    $hasVoipParam = array_key_exists('voip_token', $json) || isset($_POST['voip_token']);
    $voipTokenRaw = trim((string)($json['voip_token'] ?? $_POST['voip_token'] ?? ''));
    $voipToken = preg_match('/^[0-9a-fA-F]{64}$/', $voipTokenRaw) ? strtolower($voipTokenRaw) : null;
    // A VoIP token alone is enough to wake an iPhone for a call.
    $pushType = ($fcmToken !== null || ($tokenType === 'apns' && $voipToken !== null)) ? $tokenType : 'none';
    if ($voipToken !== null) {
        // A VoIP token belongs to one install: drop it from any other row.
        $db->prepare("UPDATE sys_mobile_devices SET voip_token = NULL WHERE voip_token = ? AND NOT (user_id = ? AND device_id <=> ?)")
           ->execute([$voipToken, $userId, $deviceId !== '' ? $deviceId : null]);
    }

    if ($deviceId === '' && $fcmToken === null) {
        mobileError('device_id veya fcm_token parametresi zorunludur.', 400);
    }

    // Find the existing record: by device_id first, otherwise by fcm_token
    $existing = null;
    if ($deviceId !== '') {
        $stmt = $db->prepare("SELECT id, fcm_token, push_type, voip_token FROM sys_mobile_devices WHERE user_id = ? AND device_id = ? LIMIT 1");
        $stmt->execute([$userId, $deviceId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$existing && $fcmToken !== null) {
        // If the same FCM token is registered to ANOTHER user, that row is NOT TAKEN OVER.
        $stmt = $db->prepare("SELECT id, user_id, fcm_token, push_type, voip_token FROM sys_mobile_devices WHERE fcm_token = ? LIMIT 1");
        $stmt->execute([$fcmToken]);
        $yabanci = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($yabanci && (int) $yabanci['user_id'] === (int) $userId) {
            $existing = $yabanci;
        } elseif ($yabanci) {
            $db->prepare("UPDATE sys_mobile_devices 
                             SET is_active = 0, 
                                 fcm_token = CONCAT('revoked:', id, ':', UNIX_TIMESTAMP()), 
                                 push_type = 'none',
                                 voip_token = NULL,
                                 updated_at = NOW() 
                           WHERE id = ?")
               ->execute([$yabanci['id']]);
            error_log("fcm_token.php: token reassigned, old record deactivated (id={$yabanci['id']}, eski user_id={$yabanci['user_id']})");
        }
    }

    if ($existing) {
        // If the new token is null and a token was registered before, keep the old token
        $tokenToSave = ($fcmToken !== null) ? $fcmToken : ($existing['fcm_token'] ?: null);
        $voipToSave = $hasVoipParam ? $voipToken : ($existing['voip_token'] ?? null);
        if ($pushType !== 'none') {
            $typeToSave = $pushType;
        } elseif ($tokenToSave !== null || $voipToSave !== null) {
            $typeToSave = (($existing['push_type'] ?? '') === 'apns' || $voipToSave !== null) ? 'apns' : 'fcm';
        } else {
            $typeToSave = 'none';
        }

        $stmt = $db->prepare("UPDATE sys_mobile_devices 
                              SET user_id = ?, extension = ?, fcm_token = ?, push_type = ?, voip_token = ?,
                                  device_id = IF(? != '', ?, device_id),
                                  device_name = IF(? != '', ?, device_name),
                                  platform = ?, app_version = ?, is_active = 1, updated_at = NOW()
                              WHERE id = ?");
        $stmt->execute([
            $userId, $extension, $tokenToSave, $typeToSave, $voipToSave,
            $deviceId, $deviceId,
            $deviceName, $deviceName,
            $platform, $appVersion,
            $existing['id']
        ]);
        $id = $existing['id'];
    } else {
        $stmt = $db->prepare("INSERT INTO sys_mobile_devices 
                              (user_id, extension, fcm_token, push_type, voip_token, device_id, device_name, platform, app_version, is_active, created_at, updated_at) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");
        $stmt->execute([
            $userId, $extension, $fcmToken, $pushType, $voipToken,
            $deviceId !== '' ? $deviceId : null,
            $deviceName !== '' ? $deviceName : null,
            $platform,
            $appVersion !== '' ? $appVersion : null
        ]);
        $id = $db->lastInsertId();
    }

    mobileJson([
        'success' => true,
        'message' => t('mobile_api.device_saved'),
        'device_id' => $deviceId,
        'push_type' => $existing ? $typeToSave : $pushType,
        'id' => (int)$id
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $json = mobileInput();

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

    mobileJson([
        'success' => true,
        'message' => t('mobile_api.device_deactivated')
    ]);
}

// GET - list the user's active devices
$stmt = $db->prepare("SELECT id, extension, device_id, device_name, platform, app_version, is_active, created_at, updated_at 
                      FROM sys_mobile_devices 
                      WHERE user_id = ? AND is_active = 1 
                      ORDER BY updated_at DESC");
$stmt->execute([$userId]);
$devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

mobileJson([
    'success' => true,
    'total' => count($devices),
    'devices' => $devices
]);
