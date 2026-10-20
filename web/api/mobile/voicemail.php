<?php
/**
 * Voicemail of the signed-in user's own mailbox for the mobile apps.
 *
 *   GET  ?action=list                     → {messages: [...], new_count}
 *   GET  ?action=play&id=INBOX:msg0000    → the recording (audio/wav)
 *   POST {"action": "heard",  "id": ...}  → moves a new message to Old, {id: new id}
 *   POST {"action": "delete", "id": ...}
 *
 * Only the user's own extension; ids are "<INBOX|Old>:msgNNNN", or "Archive:<n>"
 * for heard messages archived to the S3 bucket (#69).
 */
require_once __DIR__ . '/auth_helper.php';
mobileApiStart('GET, POST, OPTIONS');

require_once __DIR__ . '/../../src/services/VoicemailService.php';

$user = requireMobileAuth();
$ext = preg_replace('/[^0-9]/', '', (string) ($user['extension'] ?? ''));
if ($ext === '') {
    mobileError(t('mobile_api.no_extension_for_user'), 400);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = mobileInput();
    $id = (string) ($in['id'] ?? '');
    try {
        switch ((string) ($in['action'] ?? '')) {
            case 'heard':
                mobileJson(['success' => true, 'id' => VoicemailService::markHeard($ext, $id)]);
            case 'delete':
                VoicemailService::deleteFiles($ext, $id);
                mobileJson(['success' => true]);
        }
    } catch (\Exception $e) {
        mobileError($e->getMessage(), 404);
    }
    mobileError(t('mobile_api.invalid_request'), 400);
}

if (($_GET['action'] ?? 'list') === 'play') {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $archived = VoicemailService::archivedKey($ext, (string) ($_GET['id'] ?? ''));
    if ($archived !== null) {
        require_once __DIR__ . '/../../src/services/BucketFiles.php';
        if (!BucketFiles::passthru($archived, ['Content-Type' => 'audio/wav', 'Cache-Control' => 'private, no-store'])) {
            mobileError(t('api.err_audio_not_found'), 404);
        }
        exit;
    }
    $path = VoicemailService::audioPath($ext, (string) ($_GET['id'] ?? ''));
    if ($path === null) {
        mobileError(t('api.err_audio_not_found'), 404);
    }
    header('Content-Type: audio/wav');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

$messages = [];
foreach (VoicemailService::getVoicemailMessages($ext) as $m) {
    if (!preg_match('/^((INBOX|Old):msg[0-9]{4}|Archive:\d+)$/', $m['id'])) {
        continue;
    }
    // callerid: "Name" <number>
    $name = '';
    $number = $m['callerid'];
    if (preg_match('/^"?([^"<]*)"?\s*<([^>]*)>$/', $m['callerid'], $c)) {
        $name = trim($c[1]);
        $number = trim($c[2]);
    }
    $messages[] = [
        'id' => $m['id'],
        'new' => $m['folder'] === 'INBOX',
        'caller_name' => $name,
        'caller_number' => $number,
        'time' => $m['origtime'] > 0 ? date('c', $m['origtime']) : '',
        'duration' => (int) $m['duration'],
        'has_audio' => (bool) $m['has_audio'],
        // Voicemail as text (server 1.24+), empty when not made.
        'summary' => (string) ($m['summary'] ?? ''),
        'transcript' => (string) ($m['transcript'] ?? ''),
    ];
}
mobileJson([
    'success' => true,
    'messages' => $messages,
    'new_count' => count(array_filter($messages, fn($m) => $m['new'])),
]);
