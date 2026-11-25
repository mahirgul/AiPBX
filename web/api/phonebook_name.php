<?php

declare(strict_types=1);

/**
 * Loopback endpoint for [sub-phonebook-name]: Asterisk asks for the address book name of an
 * inbound caller before the call rings anyone. "NAME:<name>" when found, "NONE" otherwise.
 * A dialplan requesting VIP information receives "VIP:<name>" for a matched VIP contact.
 */
// Asterisk on this server only; a request that came through a proxy is not it.
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)
    || !empty($_SERVER['HTTP_X_FORWARDED_FOR'])
    || !empty($_SERVER['HTTP_X_FORWARDED_HOST'])
    || !empty($_SERVER['HTTP_FORWARDED'])
    || !empty($_SERVER['HTTP_X_REAL_IP'])
    || !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'NONE';
    exit(0);
}

define('AIPBX_NO_SESSION', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/PhonebookService.php';
require_once dirname(__DIR__) . '/src/services/ConnectionService.php';

header('Content-Type: text/plain; charset=utf-8');
try {
    $num = (string) ($_GET['num'] ?? '');
    $contact = PhonebookService::findByNumber($num);
    $name = $contact ? PhonebookService::callerIdName($contact) : '';
    if ($name === '') {
        $ext = ConnectionService::lookupCaller($num);
        if ($ext && $ext['display_name'] !== '') {
            $name = PhonebookService::callerIdName([
                'display_name' => $ext['display_name'],
                'company' => $ext['company'],
            ]);
        }
    }
    $name = str_replace(["\r", "\n"], '', $name);
    $vip = ($_GET['include_vip'] ?? '') === '1' && !empty($contact['is_vip']);
    echo $name !== '' ? ($vip ? 'VIP:' : 'NAME:') . $name : 'NONE';
} catch (\Throwable $e) {
    error_log('[phonebook_name] ' . $e->getMessage());
    echo 'NONE';
}
