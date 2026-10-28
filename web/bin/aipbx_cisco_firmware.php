#!/usr/bin/env php
<?php
/**
 * The Cisco 7900 SIP firmware switch for phones that already exist
 * (docs/cisco-callmanager.md).
 *
 *   aipbx_cisco_firmware.php            check the load of every Cisco 7900 model
 *                                       named in the settings, arm the switch for
 *                                       the phones in the table, and say which of
 *                                       them to restart
 *   aipbx_cisco_firmware.php --dir=PATH check another folder, for a server whose
 *                                       phones fetch their firmware elsewhere
 *
 * This is the retrofit for a fleet provisioned before a load was named for its
 * model: provisioning arms the switch when a phone is created or updated, and
 * nothing goes back over the phones already in pbx_phones. It is its own script
 * because none of it is discovery — it reads the settings and pbx_phones, and the
 * phones it arms are ones somebody already added.
 *
 * Safe to run twice: a phone already pointed at its model's load is left alone.
 * Nothing is sent to a phone (a phone on SCCP is not registered, so there is no
 * NOTIFY to send): the restart in the output is the one step that is yours.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/helpers.php';
require_once dirname(__DIR__) . '/src/services/PhoneFirmwareService.php';

$dir = null;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--dir=')) {
        $dir = substr($arg, 6);
    } elseif ($arg === '-h' || $arg === '--help') {
        fwrite(STDOUT, "usage: aipbx_cisco_firmware.php [--dir=PATH]\n");
        exit(0);
    } else {
        fwrite(STDERR, "usage: aipbx_cisco_firmware.php [--dir=PATH]\n");
        exit(2);
    }
}

$loads = PhoneFirmwareService::loads();
if ($loads === []) {
    fwrite(STDOUT, "nothing to do: no model has a SIP load"
        . " (Phones -> settings -> SIP firmware load per model)\n");
    exit(0);
}
foreach ($loads as $model => $load) {
    $check = CiscoFirmware::check($load, $dir);
    printf("%-16s %-24s %s\n", $model, $load, $check['state']
        . ($check['missing'] === [] ? '' : ': ' . implode(', ', $check['missing'])));
}

$rows = PhoneFirmwareService::armAll($dir);
printf("%d Cisco 7900 phone(s)\n", count($rows));
foreach ($rows as $r) {
    printf("  %-17s %-12s %s%s\n", $r['mac'], $r['state'] === '' ? '-' : $r['state'], $r['load'],
        $r['missing'] === [] ? '' : '  missing: ' . implode(', ', $r['missing']));
}
foreach ($rows as $r) {
    if ($r['state'] !== PhoneFirmwareService::ARMED) {
        continue;
    }
    printf("restart the phone so it reads its file: %s (power cycle, or bounce its PoE port)\n", $r['mac']);
}
exit(0);
