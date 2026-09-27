#!/usr/bin/env php
<?php
/**
 * AI PBX Arka Plan Mobil Push Gönderici
 *
 * Asterisk dialplan'ı (DialplanBuilders::buildExtensionDialLines) gelen
 * çağrıda mobil cihazı uyandırmak için arka planda çağırır:
 *   System(/usr/local/bin/push_dispatcher.php <dahili> "<arayan_no>" "<arayan_ad>" &)
 *
 * 2026-09-27'ye kadar bu betik yalnızca Karabük sunucusunda elle kurulmuş bir
 * kopya olarak vardı; repoda ve install.sh'de yoktu — yeni kurulumlarda push
 * açılınca dialplan var olmayan bir dosyayı çağırıyordu.
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
