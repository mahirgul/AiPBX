<?php
require_once __DIR__ . '/auth_helper.php';
mobileApiStart('GET, OPTIONS');

require_once __DIR__ . '/../../src/core/BaseRepository.php';
require_once __DIR__ . '/../../src/repositories/MyPhoneRepository.php';
require_once __DIR__ . '/../../src/asterisk_helper.php';

$user = requireMobileAuth();

$directory = MyPhoneRepository::getInternalDirectory();
$pjsipStatuses = AsteriskHelper::getPJSIPStatuses();

// Get the online user list from the chat service (aipbx-chat)
$chatOnline = [];
try {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 0.5,
            'ignore_errors' => true
        ]
    ]);
    $chatResp = @file_get_contents('http://127.0.0.1:8086/api/internal/presence', false, $ctx);
    if ($chatResp !== false) {
        $chatData = json_decode($chatResp, true);
        if (!empty($chatData['online']) && is_array($chatData['online'])) {
            $chatOnline = array_flip($chatData['online']);
        }
    }
} catch (\Throwable $e) {}

$contacts = [];
foreach ($directory as $contact) {
    $cExt = trim($contact['extension'] ?? '');
    if ($cExt === '') {
        continue;
    }

    $sipStatus = $pjsipStatuses["{$cExt}-sip"] ?? 'Unavailable';
    $webrtcStatus = $pjsipStatuses["{$cExt}-webrtc"] ?? 'Unavailable';
    $mobWebrtcStatus = $pjsipStatuses["{$cExt}-mob-webrtc"] ?? 'Unavailable';
    $isChatOnline = isset($chatOnline[$cExt]);

    // Durum belirleme: SIP, WebRTC veya Sohbetten herhangi birinde aktifse 'online'
    $isOnline = ($sipStatus === 'Not in use' || $sipStatus === 'In use' ||
                 $webrtcStatus === 'Not in use' || $webrtcStatus === 'In use' ||
                 $mobWebrtcStatus === 'Not in use' || $mobWebrtcStatus === 'In use' ||
                 $isChatOnline);
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
        'chat_status' => $isChatOnline ? 'online' : 'offline',
    ];
}

mobileJson([
    'success' => true,
    'total' => count($contacts),
    'contacts' => $contacts
]);
