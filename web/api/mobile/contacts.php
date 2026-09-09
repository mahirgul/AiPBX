<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/../../src/core/BaseRepository.php';
require_once __DIR__ . '/../../src/repositories/MyPhoneRepository.php';
require_once __DIR__ . '/../../src/asterisk_helper.php';

$user = requireMobileAuth();

$directory = MyPhoneRepository::getInternalDirectory();
$pjsipStatuses = AsteriskHelper::getPJSIPStatuses();

$contacts = [];
foreach ($directory as $contact) {
    $cExt = trim($contact['extension'] ?? '');
    if ($cExt === '') {
        continue;
    }

    $sipStatus = $pjsipStatuses["{$cExt}-sip"] ?? 'Unavailable';
    $webrtcStatus = $pjsipStatuses["{$cExt}-webrtc"] ?? 'Unavailable';
    $mobWebrtcStatus = $pjsipStatuses["{$cExt}-mob-webrtc"] ?? 'Unavailable';

    // Durum belirleme: Herhangi biri çevrimiçi ise 'online'
    $isOnline = ($sipStatus === 'Not in use' || $sipStatus === 'In use' ||
                 $webrtcStatus === 'Not in use' || $webrtcStatus === 'In use' ||
                 $mobWebrtcStatus === 'Not in use' || $mobWebrtcStatus === 'In use');
    $isBusy = ($sipStatus === 'In use' || $sipStatus === 'Busy' ||
               $webrtcStatus === 'In use' || $webrtcStatus === 'Busy' ||
               $mobWebrtcStatus === 'In use' || $mobWebrtcStatus === 'Busy');

    $status = 'offline';
    if ($isBusy) {
        $status = 'busy';
    } elseif ($isOnline) {
        $status = 'online';
    }

    $contacts[] = [
        'extension' => $cExt,
        'name' => $contact['full_name'] ?? $cExt,
        'role' => $contact['role'] ?? '',
        'status' => $status,
        'sip_status' => $sipStatus,
        'webrtc_status' => $webrtcStatus,
        'mob_webrtc_status' => $mobWebrtcStatus,
    ];
}

echo json_encode([
    'success' => true,
    'total' => count($contacts),
    'contacts' => $contacts
], JSON_UNESCAPED_UNICODE);
