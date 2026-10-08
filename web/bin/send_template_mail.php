<?php
/**
 * Sends an e-mail template from shell scripts (the fax scripts run as the
 * asterisk user, which can read /etc/ai-pbx.env and so reach the database).
 *
 *   php send_template_mail.php KEY TO [--sender=fax] [--attach=PATH|NAME|TYPE] [name=value ...]
 *
 * The language is the recipient's (portal user) or the system mail language.
 * Exit code 0 = handed to the mail system.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/MailTemplateService.php';

$args = array_slice($_SERVER['argv'] ?? [], 1);
$key = array_shift($args) ?? '';
$to = array_shift($args) ?? '';
if (!MailTemplateService::exists($key) || $to === '') {
    fwrite(STDERR, "usage: send_template_mail.php KEY TO [--sender=fax] [--attach=PATH|NAME|TYPE] [name=value ...]\n");
    exit(64);
}

$vars = [];
$attachments = [];
$sender = 'portal';
foreach ($args as $a) {
    if (str_starts_with($a, '--sender=')) {
        $sender = substr($a, 9) === 'fax' ? 'fax' : 'portal';
    } elseif (str_starts_with($a, '--attach=')) {
        [$path, $name, $type] = array_pad(explode('|', substr($a, 9), 3), 3, '');
        $attachments[] = ['path' => $path, 'name' => $name !== '' ? $name : basename($path), 'type' => $type];
    } elseif (preg_match('/^([a-z_]+)=(.*)$/s', $a, $m)) {
        $vars[$m[1]] = $m[2];
    }
}

$res = MailTemplateService::send($key, $to, MailTemplateService::languageFor($to), $vars, [], $attachments, $sender);
if (!$res['success']) {
    fwrite(STDERR, ($res['error'] ?? 'send failed') . "\n");
    exit(1);
}
exit(0);
