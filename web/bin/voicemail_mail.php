<?php
/**
 * Asterisk's voicemail "mailcmd" (pbx/voicemail_general.conf, written by
 * SyncVoicemail). Asterisk pipes the finished e-mail to stdin: its text part
 * carries the message details as "key=value" lines (emailbody), the recording
 * is attached. This script sends the "voicemail" e-mail template instead, in
 * the mailbox owner's language, with the same attachment.
 *
 * Whatever goes wrong, the original message is handed to sendmail, so a
 * voicemail notification is never lost.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raw = (string) stream_get_contents(STDIN);
$handled = false;
openlog('aipbx-voicemail', LOG_PID, LOG_MAIL);

/** Hands Asterisk's own message to sendmail (the old behaviour); true when sendmail took it. */
$deliverOriginal = function (string $why) use ($raw, &$handled): bool {
    $handled = true;
    syslog(LOG_WARNING, 'template mail not sent, original message delivered: ' . $why);
    $p = popen('/usr/sbin/sendmail -t', 'w');
    if (!$p) {
        syslog(LOG_ERR, 'sendmail could not be started, voicemail notification lost');
        return false;
    }
    fwrite($p, $raw);
    return pclose($p) === 0;
};

/** Delivers the original message and ends. */
$fallback = function (string $why) use ($deliverOriginal): never {
    exit($deliverOriginal($why) ? 0 : 1);
};

// Some failures end the script without reaching a catch: config.php prints its
// error page and exits when the database is unreachable, and a PHP fatal error
// is no Throwable. Registered before config.php, so this runs before config.php's
// own shutdown handler (which exits) and the notification is still delivered.
register_shutdown_function(function () use (&$handled, $deliverOriginal) {
    if (!$handled) {
        $err = error_get_last();
        $deliverOriginal('script ended early' . ($err ? ': ' . $err['message'] : ''));
    }
});

try {
    require_once dirname(__DIR__) . '/config.php';
    require_once dirname(__DIR__) . '/src/services/MailTemplateService.php';
    require_once dirname(__DIR__) . '/src/services/VoicemailMailParser.php';

    $msg = VoicemailMailParser::parse($raw);
    if ($msg === null) {
        $fallback('message not recognised');
    }

    $info = $msg['info'];
    $db = getDB();
    $st = $db->prepare("SELECT id, full_name, username FROM sys_users WHERE extension = ? AND extension_type = 'sip' ORDER BY id LIMIT 1");
    $st->execute([$info['mailbox'] ?? '']);
    $user = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    $lang = MailTemplateService::languageFor($msg['to'], $user ? (int) $user['id'] : null);
    $domain = getSystemSetting('pjsip_external_domain', '') ?: portalEnv('PORTAL_DOMAIN', gethostname() ?: 'localhost');

    $tmp = null;
    $attachments = [];
    if ($msg['attachment'] !== null) {
        $tmp = tempnam(sys_get_temp_dir(), 'aipbx-vm-');
        file_put_contents($tmp, $msg['attachment']['data']);
        $attachments[] = ['path' => $tmp, 'name' => $msg['attachment']['name'], 'type' => $msg['attachment']['type']];
    }

    $vars = VoicemailMailParser::templateVars($info, $user) + [
        'portal_link' => 'https://' . $domain . '/my-phone?tab=voicemail',
    ];
    if ($vars['caller'] === '') {
        $vars['caller'] = MailTemplateService::inLanguage($lang, fn() => t('mail_templates.unknown_caller'));
    }
    $res = MailTemplateService::send('voicemail', $msg['to'], $lang, $vars, [], $attachments);
    if ($tmp) {
        @unlink($tmp);
    }
    if (!$res['success']) {
        $fallback($res['error'] ?? 'send failed');
    }
    $handled = true;
    syslog(LOG_INFO, 'voicemail e-mail for mailbox ' . ($info['mailbox'] ?? '?') . ' handed to sendmail');
    exit(0);
} catch (\Throwable $e) {
    $fallback($e->getMessage());
}
