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

/** Hands Asterisk's own message to sendmail (the old behaviour) and ends. */
$fallback = function (string $why) use ($raw): never {
    openlog('aipbx-voicemail', LOG_PID, LOG_MAIL);
    syslog(LOG_WARNING, 'template mail not sent, original message delivered: ' . $why);
    $p = popen('/usr/sbin/sendmail -t', 'w');
    if ($p) {
        fwrite($p, $raw);
        exit(pclose($p) === 0 ? 0 : 1);
    }
    exit(1);
};

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
    exit(0);
} catch (\Throwable $e) {
    $fallback($e->getMessage());
}
