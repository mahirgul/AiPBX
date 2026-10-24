#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Runner entrypoint for a single scheduled task (Roadmap 2.1).
 * Usage: php bin/run_task.php <task_id> [triggered_by]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$argv = $_SERVER['argv'] ?? [];
$argc = count($argv);

if ($argc < 2) {
    echo "Usage: php bin/run_task.php <task_id> [triggered_by]\n";
    exit(1);
}

$taskId = (int)($argv[1] ?? 0);
$triggeredBy = (string)($argv[2] ?? 'manual');

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/ScheduledTaskRunner.php';

$res = ScheduledTaskRunner::run($taskId, $triggeredBy);
echo "Task #{$taskId} finished with exit code {$res['exit_code']}.\n";
exit($res['ok'] ? 0 : 1);
