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
require_once __DIR__ . '/../../src/core/BaseRepository.php';
require_once __DIR__ . '/../../src/repositories/MyPhoneRepository.php';
require_once __DIR__ . '/../../src/asterisk_sync.php';

$user = requireMobileAuth();
$userId = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_raw = file_get_contents('php://input');
    $json = json_decode($input_raw, true) ?: [];

    $currentDetails = MyPhoneRepository::getUserExtensionDetails($userId) ?: [];

    $dnd = isset($json['dnd_enabled'])
        ? ($json['dnd_enabled'] ? 1 : 0)
        : (isset($_POST['dnd_enabled']) ? ((int)$_POST['dnd_enabled'] ? 1 : 0) : (int)($currentDetails['dnd_enabled'] ?? 0));

    $forward = isset($json['call_forward_number'])
        ? preg_replace('/[^0-9+*#]/', '', trim((string)$json['call_forward_number']))
        : (isset($_POST['call_forward_number'])
            ? preg_replace('/[^0-9+*#]/', '', trim((string)$_POST['call_forward_number']))
            : ($currentDetails['call_forward_number'] ?? ''));

    $mode = $currentDetails['allowed_phone_mode'] ?? 'both';
    if (isset($json['allowed_phone_mode']) && in_array($json['allowed_phone_mode'], ['both', 'webrtc_only', 'sip_only'], true)) {
        $mode = $json['allowed_phone_mode'];
    } elseif (isset($_POST['allowed_phone_mode']) && in_array($_POST['allowed_phone_mode'], ['both', 'webrtc_only', 'sip_only'], true)) {
        $mode = $_POST['allowed_phone_mode'];
    }

    MyPhoneRepository::updatePhoneSettings($userId, $dnd, $forward, $mode);

    // Asterisk dialplan senkronizasyonu
    try {
        require_once dirname(__DIR__, 2) . '/sync/SyncGeneralDialplan.php';
        if (function_exists('syncGeneralDialplan')) {
            syncGeneralDialplan();
        }
    } catch (\Throwable $e) {
        // Non-fatal
    }

    if (function_exists('writeAuditLog')) {
        writeAuditLog(null, 'mobile_settings', 'phone', "DND: {$dnd}, CF: {$forward}, Mode: {$mode}", 'update', $userId);
    }

    $updated = MyPhoneRepository::getUserExtensionDetails($userId);

    echo json_encode([
        'success' => true,
        'message' => 'Ayarlar başarıyla güncellendi.',
        'features' => [
            'extension' => $updated['extension'] ?? '',
            'dnd_enabled' => (bool)($updated['dnd_enabled'] ?? false),
            'call_forward_number' => $updated['call_forward_number'] ?? '',
            'allowed_phone_mode' => $updated['allowed_phone_mode'] ?? 'both'
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// GET Metodu - Mevcut Durumu Döndür
$details = MyPhoneRepository::getUserExtensionDetails($userId);

echo json_encode([
    'success' => true,
    'features' => [
        'extension' => $details['extension'] ?? '',
        'dnd_enabled' => (bool)($details['dnd_enabled'] ?? false),
        'call_forward_number' => $details['call_forward_number'] ?? '',
        'allowed_phone_mode' => $details['allowed_phone_mode'] ?? 'both'
    ]
], JSON_UNESCAPED_UNICODE);
