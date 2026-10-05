<?php
require_once __DIR__ . '/auth_helper.php';
mobileApiStart('GET, POST, OPTIONS');

require_once __DIR__ . '/../../src/core/BaseRepository.php';
require_once __DIR__ . '/../../src/repositories/MyPhoneRepository.php';
require_once __DIR__ . '/../../src/asterisk_sync.php';

$user = requireMobileAuth();
$userId = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = mobileInput();

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

    // Asterisk dialplan sync.
    //
    // The old `require_once dirname(__DIR__, 2) . '/sync/SyncGeneralDialplan.php'`
    // line here looked for /var/www/html/sync/... — no such directory exists
    // (the right one is /src/sync/). In PHP 8.5 require_once with a missing
    // file throws a catchable Error, the catch below swallowed it silently and
    // syncGeneralDialplan() was NEVER called: the user turned DND on from
    // mobile, got a "success" answer and sys_users was updated, but the PBX
    // behaviour did not change (2026-09-05 audit, bulgu2.md B2-1).
    //
    // The line was removed completely: the function is already loaded —
    // features.php:16 -> src/asterisk_sync.php -> sync/SyncGeneralDialplan.php.
    try {
        if (function_exists('syncGeneralDialplan')) {
            syncGeneralDialplan();
        } else {
            error_log('mobile/features.php: syncGeneralDialplan() yuklu degil, dialplan guncellenmedi');
        }
    } catch (\Throwable $e) {
        // The setting was written to the DB but the dialplan could not be
        // generated. Do not swallow it silently — this bug stayed invisible
        // exactly because of silent swallowing.
        error_log('mobile/features.php dialplan senkron hatasi: ' . $e->getMessage());
    }

    if (function_exists('writeAuditLog')) {
        writeAuditLog(null, 'mobile_settings', 'phone', "DND: {$dnd}, CFA: {$forward}, CFB: {$forwardBusy}, CFNA: {$forwardNoAnswer} ({$noAnswerTimeout}s), Mode: {$mode}", 'update', $userId);
    }

    $updated = MyPhoneRepository::getUserExtensionDetails($userId);

    mobileJson([
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
    ]);
}

// GET method - return the current state
$details = MyPhoneRepository::getUserExtensionDetails($userId);

mobileJson([
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
]);
