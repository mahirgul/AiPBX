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

    $forwardBusy = isset($json['cf_busy_number'])
        ? preg_replace('/[^0-9+*#]/', '', trim((string)$json['cf_busy_number']))
        : (isset($_POST['cf_busy_number'])
            ? preg_replace('/[^0-9+*#]/', '', trim((string)$_POST['cf_busy_number']))
            : ($currentDetails['cf_busy_number'] ?? ''));

    $forwardNoAnswer = isset($json['cf_noanswer_number'])
        ? preg_replace('/[^0-9+*#]/', '', trim((string)$json['cf_noanswer_number']))
        : (isset($_POST['cf_noanswer_number'])
            ? preg_replace('/[^0-9+*#]/', '', trim((string)$_POST['cf_noanswer_number']))
            : ($currentDetails['cf_noanswer_number'] ?? ''));

    $noAnswerTimeout = isset($json['cf_noanswer_timeout'])
        ? max(5, min(120, (int)$json['cf_noanswer_timeout']))
        : (isset($_POST['cf_noanswer_timeout'])
            ? max(5, min(120, (int)$_POST['cf_noanswer_timeout']))
            : (int)($currentDetails['cf_noanswer_timeout'] ?? 20));

    $mode = $currentDetails['allowed_phone_mode'] ?? 'web,mobil,sip,video';
    if (isset($json['phone_modes']) && is_array($json['phone_modes'])) {
        $mode = formatPhoneModes($json['phone_modes']);
    } elseif (isset($json['allowed_phone_mode'])) {
        $mode = formatPhoneModes(parsePhoneModes($json['allowed_phone_mode']));
    } elseif (isset($_POST['allowed_phone_mode'])) {
        $mode = formatPhoneModes(parsePhoneModes($_POST['allowed_phone_mode']));
    }

    MyPhoneRepository::updatePhoneSettings($userId, $dnd, $forward, $mode, $forwardBusy, $forwardNoAnswer, $noAnswerTimeout);

    // Asterisk dialplan senkronizasyonu.
    //
    // Buradaki eski `require_once dirname(__DIR__, 2) . '/sync/SyncGeneralDialplan.php'`
    // satiri /var/www/html/sync/... yolunu ariyordu — boyle bir dizin yok
    // (dogrusu /src/sync/). PHP 8.5'te eksik dosyayla require_once yakalanabilir
    // bir Error firlatiyor, asagidaki catch onu sessizce yutuyordu ve
    // syncGeneralDialplan() HIC cagrilmiyordu: kullanici mobilden DND aciyor,
    // "basarili" cevabi aliyor, sys_users guncelleniyor, ama santral davranisi
    // degismiyordu (2026-09-05 denetimi, bulgu2.md B2-1).
    //
    // Satir tamamen kaldirildi: fonksiyon zaten yuklu —
    // features.php:16 -> src/asterisk_sync.php -> sync/SyncGeneralDialplan.php.
    try {
        if (function_exists('syncGeneralDialplan')) {
            syncGeneralDialplan();
        } else {
            error_log('mobile/features.php: syncGeneralDialplan() yuklu degil, dialplan guncellenmedi');
        }
    } catch (\Throwable $e) {
        // Ayar DB'ye yazildi ama dialplan uretilemedi. Sessizce yutma — bu bug
        // tam olarak sessiz yutma yuzunden gorunmez kalmisti.
        error_log('mobile/features.php dialplan senkron hatasi: ' . $e->getMessage());
    }

    if (function_exists('writeAuditLog')) {
        writeAuditLog(null, 'mobile_settings', 'phone', "DND: {$dnd}, CFA: {$forward}, CFB: {$forwardBusy}, CFNA: {$forwardNoAnswer} ({$noAnswerTimeout}s), Mode: {$mode}", 'update', $userId);
    }

    $updated = MyPhoneRepository::getUserExtensionDetails($userId);

    echo json_encode([
        'success' => true,
        'message' => 'Ayarlar başarıyla güncellendi.',
        'features' => [
            'extension' => $updated['extension'] ?? '',
            'dnd_enabled' => (bool)($updated['dnd_enabled'] ?? false),
            'call_forward_number' => $updated['call_forward_number'] ?? '',
            'cf_busy_number' => $updated['cf_busy_number'] ?? '',
            'cf_noanswer_number' => $updated['cf_noanswer_number'] ?? '',
            'cf_noanswer_timeout' => (int)($updated['cf_noanswer_timeout'] ?? 20),
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
        'cf_busy_number' => $details['cf_busy_number'] ?? '',
        'cf_noanswer_number' => $details['cf_noanswer_number'] ?? '',
        'cf_noanswer_timeout' => (int)($details['cf_noanswer_timeout'] ?? 20),
        'allowed_phone_mode' => $details['allowed_phone_mode'] ?? 'both'
    ]
], JSON_UNESCAPED_UNICODE);
