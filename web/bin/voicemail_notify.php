#!/usr/bin/env php
<?php
/**
 * Asterisk's externnotify (voicemail.conf, SyncVoicemail): called whenever the
 * message count of a mailbox changes, as
 *   voicemail_notify.php <context> <mailbox> <new> <old> <urgent>
 * When the number of new messages went up, the mailbox owner's apps get a
 * notification: over the chat service's connection while an app is connected,
 * and by push (Firebase / APNs) when push is set up. The app builds the text in
 * its own language from caller, duration and new_count.
 *
 * Asterisk waits for this command, so the sending part runs detached
 * (--send) and this call returns at once.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config.php';

$args = $_SERVER['argv'] ?? [];

if (($args[1] ?? '') === '--send') {
    voicemailNotifySend((string) ($args[2] ?? ''), (int) ($args[3] ?? 0));
    exit(0);
}

$mailbox = preg_replace('/[^0-9]/', '', (string) ($args[2] ?? ''));
$new = (int) ($args[3] ?? 0);
if ($mailbox === '') {
    exit(0);
}

// Last known count of new messages: a notification only when it went up
// (listening, deleting or a phone's own check also call this command).
$key = 'vm_notify_new_' . $mailbox;
$last = (int) getSystemSetting($key, '0');
getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
    ->execute([$key, (string) $new]);
if ($new <= $last) {
    exit(0);
}
exec('nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --send ' . escapeshellarg($mailbox) . ' ' . (int) $new . ' >/dev/null 2>&1 &');
exit(0);

/** The newest message of INBOX: caller and length, sent to the extension's apps. */
function voicemailNotifySend(string $ext, int $newCount): void
{
    $ext = preg_replace('/[^0-9]/', '', $ext);
    if ($ext === '') {
        return;
    }
    $caller = '';
    $duration = 0;
    $newest = 0;
    foreach (glob("/var/spool/asterisk/voicemail/default/{$ext}/INBOX/msg*.txt") ?: [] as $txt) {
        $m = (int) @filemtime($txt);
        if ($m < $newest) {
            continue;
        }
        $newest = $m;
        $info = @parse_ini_string((string) @file_get_contents($txt), false, INI_SCANNER_RAW) ?: [];
        $caller = trim((string) ($info['callerid'] ?? ''));
        $duration = (int) ($info['duration'] ?? 0);
    }
    // "Name" <number> → Name (number); Asterisk writes "Unknown" without a caller ID.
    if (preg_match('/^"?([^"<]*)"?\s*<([^>]*)>$/', $caller, $c)) {
        $name = trim($c[1]);
        $num = trim($c[2]);
        $caller = $name !== '' && $name !== $num ? "{$name} ({$num})" : $num;
    }
    if (strcasecmp($caller, 'Unknown') === 0) {
        $caller = '';
    }
    // Voicemail as text (AI -> Cloud services -> Voicemail): the e-mail has
    // usually made it already; without an e-mail address it is made here.
    $text = '';
    try {
        require_once dirname(__DIR__) . '/src/services/VoicemailAiService.php';
        $ai = VoicemailAiService::forNewest($ext);
        $text = (string) (($ai['summary'] ?? '') !== '' ? $ai['summary'] : mb_strimwidth((string) ($ai['transcript'] ?? ''), 0, 200, '…'));
    } catch (\Throwable $e) {
        $text = '';
    }
    $data = ['caller' => $caller, 'duration' => $duration, 'new_count' => $newCount, 'text' => $text];

    try {
        require_once dirname(__DIR__) . '/src/services/WebhookService.php';
        WebhookService::emit('voicemail.created', [
            'extension' => $ext,
            'caller'    => $caller,
            'duration'  => $duration,
            'new_count' => $newCount,
        ]);
        if ($text !== '') {
            WebhookService::emit('voicemail.transcribed', [
                'extension' => $ext,
                'caller'    => $caller,
                'duration'  => $duration,
                'summary'   => $text,
            ]);
        }
    } catch (\Throwable $e) {
    }

    // 1. Connected apps: the chat service's live connection.
    require_once dirname(__DIR__) . '/src/services/FileStorageService.php';
    $internalTok = FileStorageService::internalToken();
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => 2, 'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\nX-AiPBX-Internal: {$internalTok}\r\n",
        'content' => json_encode(['extension' => $ext, 'event' => 'voicemail', 'data' => $data]),
    ]]);
    $port = preg_replace('/[^0-9]/', '', (string) portalEnv('CHAT_PORT')) ?: '8086';
    $live = json_decode((string) @file_get_contents('http://127.0.0.1:' . $port . '/api/internal/notify', false, $ctx), true);
    $log = 'new voicemail for ' . $ext . ': app ' . (!empty($live['delivered']) ? 'connected, delivered' : 'not connected');

    // 2. Push (also reaches an app that is not connected); the same notification
    // id on the phone, so both together show one notification.
    try {
        require_once dirname(__DIR__) . '/src/services/push/PushService.php';
        if (!\App\Services\Push\PushService::isEnabled()) {
            $log .= '; push off (Admin -> Push)';
        } else {
            $r = \App\Services\Push\PushService::getProvider()->sendToExtension($ext, [
                'action' => 'voicemail',
                'title' => 'Voicemail',
                'body' => trim(($caller !== '' ? $caller : 'Unknown caller') . ' ' . gmdate('i:s', $duration)) . ($text !== '' ? ' — ' . $text : ''),
                'caller' => $caller,
                'duration' => (string) $duration,
                'new_count' => (string) $newCount,
                'text' => $text,
            ]);
            $log .= '; push to ' . (int) ($r['delivered'] ?? 0) . ' device(s)'
                . (empty($r['errors']) ? '' : ', failed: ' . implode(' | ', array_slice($r['errors'], 0, 3)))
                . (isset($r['message']) ? ' (' . $r['message'] . ')' : '');
        }
    } catch (\Throwable $e) {
        $log .= '; push error: ' . $e->getMessage();
    }
    // The only trace of this detached run: journalctl -t aipbx-voicemail
    openlog('aipbx-voicemail', LOG_PID, LOG_USER);
    syslog(LOG_INFO, $log);
}
