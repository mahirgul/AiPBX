#!/usr/bin/env php
<?php
/**
 * The one crontab line plugin jobs need.
 *
 *   * * * * * www-data /usr/local/bin/aipbx_plugin_cron.php
 *
 * It asks the loader which plugins are enabled, reads their `cron` lists and
 * runs whatever is due, each in its own process (see PluginProcessRunner). It
 * runs as the portal user on purpose: a manifest must not be able to grant
 * itself root. Work that genuinely needs root belongs in the core crons or
 * behind an `aipbx-priv` verb that a human reviewed.
 *
 * Manual use:
 *
 *   aipbx_plugin_cron.php --status
 *   aipbx_plugin_cron.php --run=signaling-capture/publish-profile
 *   aipbx_plugin_cron.php --list
 *
 * Dispatch never fails because a job failed: the failure is recorded in
 * `sys_plugin_cron_runs` and `--status` shows it. The exit code is about the
 * dispatcher itself, so cron does not mail an administrator every time a
 * plugin's collector cannot reach a database.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/core/PluginBootstrap.php';

$mode = 'dispatch';
$only = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--status' || $arg === '--list') {
        $mode = 'status';
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "usage: aipbx_plugin_cron.php [--status|--run=<slug>/<job>]\n");
        exit(0);
    } elseif (preg_match('/^--run=([a-z0-9][a-z0-9_-]{0,63})\/([a-z0-9][a-z0-9_-]{0,31})$/', $arg, $m)) {
        $mode = 'run';
        $only = [$m[1], $m[2]];
    } else {
        fwrite(STDERR, 'unknown option: ' . $arg . "\n");
        exit(2);
    }
}

$runtime = PluginBootstrap::runtime();
$cron = new PluginCron(
    $runtime,
    PluginBootstrap::store(),
    PluginBootstrap::runner(),
    PluginBootstrap::lockDir()
);

if ($mode === 'status') {
    $problems = $runtime->problems();
    foreach ($problems as $slug => $messages) {
        foreach ($messages as $message) {
            fwrite(STDERR, sprintf('problem %s: %s' . "\n", $slug, $message));
        }
    }
    $rows = $cron->status();
    if ($rows === []) {
        fwrite(STDOUT, "no plugin declares a cron job\n");
        exit(0);
    }
    fwrite(STDOUT, sprintf("%-24s %-20s %-16s %-6s %-10s %s\n",
        'PLUGIN', 'JOB', 'SCHEDULE', 'STATE', 'LAST', 'RESULT'));
    foreach ($rows as $row) {
        $last = $row['last_finished'] === null ? 'never'
            : date('Y-m-d H:i', (int) $row['last_finished']);
        fwrite(STDOUT, sprintf("%-24s %-20s %-16s %-6s %-10s %s\n",
            $row['slug'], $row['job'], $row['schedule'],
            $row['enabled'] ? 'on' : 'off', $last,
            $row['last_status'] === null ? '-' : (string) $row['last_status']));
    }
    exit(0);
}

if ($mode === 'run') {
    $result = $cron->run($only[0], $only[1], true);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES) . "\n");
    exit($result['result'] === 'ran' && $result['status'] === 0 ? 0 : 1);
}

$results = $cron->dispatch();
foreach ($results as $result) {
    if ($result['result'] === 'ran') {
        fwrite(STDOUT, sprintf("%s/%s exit=%s %dms\n", $result['slug'],
            $result['job'], (string) $result['status'], (int) $result['duration_ms']));
    }
}
exit(0);
