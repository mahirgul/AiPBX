#!/usr/bin/env php
<?php
/**
 * One plugin job, in its own process. Started by PluginProcessRunner; not
 * meant to be run by an administrator directly (`aipbx_plugin_cron.php --run`
 * is the supported manual path).
 *
 * The child boots the portal and the loader from scratch, resolves the entry
 * again and calls it with the plugin's settings and slug. Resolving here rather
 * than in the parent is what makes the isolation real: nothing the job does —
 * exit, fatal, a leaked static, a changed include path — reaches the dispatcher.
 *
 * Exit code: 0 for success, the job's own non-zero code if it returns one, 1
 * for "nothing to run". stdout/stderr go back to the dispatcher, which records
 * the tail of them.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/core/PluginBootstrap.php';

$slug = (string) ($argv[1] ?? '');
$job = (string) ($argv[2] ?? '');
if ($slug === '' || $job === '') {
    fwrite(STDERR, "usage: plugin_job.php <slug> <job>\n");
    exit(1);
}

$runtime = PluginBootstrap::runtime();
$definition = $runtime->job($slug, $job);
if ($definition === null) {
    fwrite(STDERR, sprintf("plugin job is not declared or is invalid: %s/%s\n", $slug, $job));
    exit(1);
}
$entry = $runtime->entry($slug, $definition);
if ($entry === null) {
    foreach ($runtime->problems()[$slug] ?? [] as $problem) {
        fwrite(STDERR, $problem . "\n");
    }
    fwrite(STDERR, sprintf("plugin job cannot be called: %s/%s\n", $slug, $job));
    exit(1);
}

try {
    $status = $entry($runtime->settings($slug), $slug);
} catch (Throwable $e) {
    // The dispatcher records stderr; a stack trace is more useful than a
    // one-line message when the job is somebody else's code.
    fwrite(STDERR, (string) $e . "\n");
    exit(1);
}
exit(is_int($status) ? max(0, min(255, $status)) : 0);
