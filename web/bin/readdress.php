#!/usr/bin/env php
<?php
/**
 * aipbx-readdress OLD NEW [--yes] (root): moves the server's address from OLD
 * to NEW everywhere AiPBX keeps it (roadmap 12), the same as Network →
 * Network settings → "Apply the new address". Without --yes it only shows
 * what would change. For servers whose portal cannot be reached any more
 * after an IP change.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/NetworkSettingsService.php';

$argv = $_SERVER['argv'] ?? [];
$args = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--yes'));
$yes = in_array('--yes', $argv, true);
if (count($args) !== 2) {
    fwrite(STDERR, "usage: aipbx-readdress OLD_IP NEW_IP [--yes]\n");
    fwrite(STDERR, "this server's addresses: " . implode(' ', NetworkSettingsService::localIps()) . "\n");
    exit(64);
}
[$old, $new] = $args;
try {
    $changes = NetworkSettingsService::readdressPreview($old, $new);
} catch (\Exception $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
if ($changes === []) {
    echo "Nothing holds {$old}.\n";
    exit(0);
}
foreach ($changes as $c) {
    printf("  %-28s %s  ->  %s\n", $c['where'], $c['from'] === '' ? '-' : $c['from'], $c['to']);
}
if (!$yes) {
    echo "\nNothing changed yet. Run again with --yes to apply.\n";
    exit(0);
}
$r = NetworkSettingsService::readdress($old, $new);
echo "\nApplied " . count($r['changes']) . " changes.\n";
if ($r['files'] !== '') {
    echo $r['files'] . "\n";
}
if (!$r['synced']) {
    echo "Asterisk could not be reloaded: press Apply on the portal (Pending changes).\n";
}
exit($r['files_ok'] && $r['synced'] ? 0 : 1);
