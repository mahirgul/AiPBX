#!/usr/bin/env php
<?php
/**
 * Runs a command a plugin declares under `cli`.
 *
 *   aipbx_plugin_cli.php signaling-capture report
 *   aipbx_plugin_cli.php signaling-capture apply --verbose
 *
 * This one core file replaces every `<plugin>_command.php` that would
 * otherwise be copied into `web/bin` and symlinked into `/usr/local/bin`: the
 * command, its class and its settings all live in the catalog.
 *
 * It is the same resolution as a cron job (PluginRuntime::entry), with two
 * deliberate differences: the enabled check still applies, and there is no
 * lock or timeout — a person is watching, and a command that takes ten minutes
 * is their decision, not a stuck schedule.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/core/PluginBootstrap.php';

$args = array_slice($argv ?? [], 1);
$slug = (string) ($args[0] ?? '');
$name = (string) ($args[1] ?? '');
if ($slug === '' || $name === '') {
    fwrite(STDERR, "usage: aipbx_plugin_cli.php <plugin> <command> [args...]\n");
    exit(2);
}

$runtime = PluginBootstrap::runtime();
$command = $runtime->command($slug, $name);
if ($command === null) {
    $declared = array_keys($runtime->commands($slug));
    fwrite(STDERR, sprintf(
        "no command '%s' in plugin '%s'%s\n",
        $name,
        $slug,
        $declared === [] ? '' : ' (declared: ' . implode(', ', $declared) . ')'
    ));
    exit(1);
}

$entry = $runtime->entry($slug, $command);
if ($entry === null) {
    foreach ($runtime->problems()[$slug] ?? [] as $problem) {
        fwrite(STDERR, $problem . "\n");
    }
    fwrite(STDERR, sprintf("command is not runnable: %s/%s\n", $slug, $name));
    exit(1);
}

try {
    $status = $entry($runtime->settings($slug), $slug, array_slice($args, 2));
} catch (Throwable $e) {
    fwrite(STDERR, (string) $e . "\n");
    exit(1);
}
exit(is_int($status) ? max(0, min(255, $status)) : 0);
