#!/usr/bin/env php
<?php
/**
 * Health checks (Admin → Health checks, docs/health.md).
 *
 *   aipbx_health.php           run the checks that are due, send alarm e-mails and
 *                              the daily summary (cron, every minute, as root)
 *   aipbx_health.php --all     run every check now and print the results; exit 1
 *                              when one fails (aipbx-selftest, CI, after installing)
 *   aipbx_health.php --all --no-mail   the same without sending e-mails
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/helpers.php';
require_once dirname(__DIR__) . '/src/audit_log.php';
require_once dirname(__DIR__) . '/src/services/HealthService.php';

$args = $_SERVER['argv'] ?? [];
$all = in_array('--all', $args, true);
$mail = !in_array('--no-mail', $args, true) && !$all;

$lock = fopen('/run/aipbx-health.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

try {
    $results = HealthService::runDue($all, $mail);
} catch (\Throwable $e) {
    // The database itself may be down: no results to store, but say so.
    fwrite(STDERR, 'aipbx-health: ' . $e->getMessage() . "\n");
    syslog(LOG_ERR, 'aipbx-health: ' . $e->getMessage());
    exit(2);
}

if (!$all) {
    exit(0);
}
$failed = 0;
foreach ($results as $key => [$status, $message]) {
    printf("%-4s %-18s %s\n", strtoupper($status), $key, $message);
    $failed += $status === 'fail' ? 1 : 0;
}
exit($failed > 0 ? 1 : 0);
