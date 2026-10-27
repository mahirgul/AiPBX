#!/usr/bin/env php
<?php
/**
 * Phone discovery, the portal's half (docs/phones.md).
 *
 *   aipbx_phone_discovery.php provision   provision the phones the settings
 *                                         allow (the engine's cron entry runs
 *                                         this right after the detection run)
 *   aipbx_phone_discovery.php status      what the engine saw, and what the
 *                                         settings would do about it, without
 *                                         changing anything
 *
 * `provision` creates phones only when an administrator has named an extension
 * pool and a model for the phone's vendor; otherwise it records what it would
 * have done and exits 0. It never touches a MAC that is already a phone.
 *
 * The lock keeps a slow run from overlapping the next cron tick; a second run
 * exits immediately (0) rather than queueing.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/helpers.php';
require_once dirname(__DIR__) . '/src/services/PhoneDiscoveryService.php';

$command = $argv[1] ?? 'status';

if ($command === 'status') {
    $settings = PhoneDiscoveryService::settings();
    $pool = PhoneDiscoveryService::parsePool($settings['provision_discovery_pool']);
    $engine = PhoneDiscoveryService::engineStatus(10);
    $state = $engine['state'];

    printf("discovery      : %s\n", PhoneDiscoveryService::isOn($settings['provision_discovery']) ? 'on' : 'off');
    printf("sweep          : %s  networks: %s\n",
        PhoneDiscoveryService::isOn($settings['provision_discovery_sweep']) ? 'on' : 'off',
        $settings['provision_discovery_networks'] === '' ? '(none)' : str_replace("\n", ' ', $settings['provision_discovery_networks']));
    printf("extension pool : %s%s\n", $pool === [] ? '(none — nothing is provisioned automatically)' : implode(',', array_slice($pool, 0, 5)) . (count($pool) > 5 ? '…' : ''),
        $pool === [] ? '' : sprintf(' (%d extensions, %d free)', count($pool), PhoneDiscoveryService::poolFree($pool)));
    printf("vendor models  : %s\n", $settings['provision_discovery_models'] === ''
        ? '(none — no vendor is provisioned automatically)' : str_replace("\n", ' ', $settings['provision_discovery_models']));

    if ($state === []) {
        echo "last engine run: (none recorded — has the aipbx-phone-discovery cron run?)\n";
    } else {
        printf("last engine run: %s  seen=%d new=%d known=%d already provisioned=%d recorded=%d\n",
            date('Y-m-d H:i:s', (int) ($state['at'] ?? 0)),
            (int) ($state['seen'] ?? 0), (int) ($state['new'] ?? 0), (int) ($state['known'] ?? 0),
            (int) ($state['provisioned'] ?? 0), (int) ($state['written'] ?? 0));
        foreach ((array) ($state['notes'] ?? []) as $note) {
            printf("                  %s\n", $note);
        }
    }
    $counts = PhoneDiscoveryService::summary();
    printf("recorded       : %d new, %d ignored, %d provisioned (%d deleted since)\n",
        $counts['new'], $counts['ignored'], $counts['provisioned'], $counts['deleted']);
    foreach ($engine['log'] as $line) {
        printf("  %s\n", $line);
    }
    exit(0);
}

if ($command !== 'provision') {
    fwrite(STDERR, "usage: aipbx_phone_discovery.php [provision|status]\n");
    exit(2);
}

$lock = fopen('/run/aipbx-phone-discovery.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

try {
    $result = PhoneDiscoveryService::provisionFromPool();
} catch (\Throwable $e) {
    // A database that is down must not leave the next run thinking it worked.
    fwrite(STDERR, 'aipbx-phone-discovery: ' . $e->getMessage() . "\n");
    syslog(LOG_ERR, 'aipbx-phone-discovery: ' . $e->getMessage());
    exit(2);
}

foreach ($result['skipped'] as $line) {
    fwrite(STDOUT, "skipped: {$line}\n");
}
foreach ($result['created'] as $line) {
    fwrite(STDOUT, "provisioned: {$line}\n");
}
if ($result['reason'] !== '') {
    fwrite(STDOUT, "nothing to do: {$result['reason']}\n");
}
exit(0);
