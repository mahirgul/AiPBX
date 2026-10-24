<?php

declare(strict_types=1);

/**
 * Scheduled Tasks Dispatcher (Roadmap 2.1).
 * Run every minute by system cron or systemd timer.
 * Checks due tasks and triggers sandboxed execution.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/ScheduledTaskService.php';
require_once dirname(__DIR__) . '/src/services/ScheduledTaskRunner.php';
require_once dirname(__DIR__) . '/src/services/CronScheduleHelper.php';
require_once dirname(__DIR__) . '/src/services/AsteriskSettingsService.php';
require_once dirname(__DIR__) . '/src/services/NightRestartService.php';
require_once dirname(__DIR__) . '/src/repositories/ScheduledTaskRepository.php';

// PBX Maintenance checks: SIP debug expiry and safe night restart
try {
    AsteriskSettingsService::checkSipDebugExpiry();
    NightRestartService::executeRestart();
} catch (\Throwable $e) {
    // Non-fatal
}

if (!ScheduledTaskService::isEnabled()) {
    // Feature is disabled globally
    exit(0);
}

$activeTasks = ScheduledTaskRepository::findActive();
if (empty($activeTasks)) {
    exit(0);
}

$now = new DateTimeImmutable();
$dispatched = 0;

foreach ($activeTasks as $task) {
    $taskId = (int)$task['id'];
    $cronExpr = (string)$task['cron_expression'];

    if (!CronScheduleHelper::isDue($cronExpr, $now)) {
        continue;
    }

    if (ScheduledTaskRepository::isTaskRunning($taskId)) {
        echo "[" . date('Y-m-d H:i:s') . "] Task #{$taskId} ('{$task['name']}') is already running, skipping overlapping run.\n";
        continue;
    }

    echo "[" . date('Y-m-d H:i:s') . "] Dispatching Task #{$taskId} ('{$task['name']}')\n";

    // Run task asynchronously in a background subprocess so multiple due tasks don't block each other
    $cmd = 'php ' . escapeshellarg(dirname(__DIR__) . '/bin/run_task.php') . ' ' . $taskId . ' schedule >/dev/null 2>&1 &';
    exec($cmd);
    $dispatched++;
}

if ($dispatched > 0) {
    echo "[" . date('Y-m-d H:i:s') . "] Dispatched {$dispatched} task(s).\n";
}

exit(0);
