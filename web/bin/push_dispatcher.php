#!/usr/bin/env php
<?php
/**
 * AI PBX background mobile push sender
 *
 * The Asterisk dialplan (DialplanBuilders::buildExtensionDialLines) calls it
 * in the background on an incoming call to wake the mobile device:
 *   System(/usr/local/bin/push_dispatcher.php <extension> "<caller_no>" "<caller_name>" &)
 *
 * Until 2026-09-27 this script existed only as a copy installed by hand on
 * the Karabük server; it was in neither the repo nor install.sh — on new
 * installs, enabling push made the dialplan call a file that did not exist.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/push/PushService.php';

use App\Services\Push\PushService;

$ext = preg_replace('/[^0-9]/', '', $argv[1] ?? '');
$callerId = trim($argv[2] ?? '');
$callerName = trim($argv[3] ?? '');

if ($ext === '') {
    exit(0);
}

$provider = PushService::getProvider();
if (!$provider->isConfigured()) {
    exit(0);
}

$provider->sendToExtension($ext, [
    'action' => 'incoming_call',
    'caller_id' => $callerId,
    'caller_name' => $callerName,
    'timestamp' => (string) time(),
]);

exit(0);
