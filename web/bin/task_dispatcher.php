#!/usr/bin/env php
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
    // Feature switched off: no task runs, a pending "run now" is dropped.
    ScheduledTaskRepository::takeRunRequests();
    exit(0);
}

$now = new DateTimeImmutable();
$due = [];
// "Run now" from the portal (it may not start a task itself).
foreach (ScheduledTaskRepository::takeRunRequests() as $task) {
    $due[(int) $task['id']] = [$task, 'manual'];
}
foreach (ScheduledTaskRepository::findActive() as $task) {
    if (!isset($due[(int) $task['id']]) && CronScheduleHelper::isDue((string) $task['cron_expression'], $now)) {
        $due[(int) $task['id']] = [$task, 'schedule'];
    }
}

foreach ($due as $taskId => [$task, $trigger]) {
    if (ScheduledTaskRepository::isTaskRunning($taskId)) {
        continue;   // no overlapping runs of one task
    }
    // In the background, so one long task does not hold up the others.
    exec('php ' . escapeshellarg(dirname(__DIR__) . '/bin/run_task.php') . ' ' . $taskId . ' ' . $trigger . ' >/dev/null 2>&1 &');
}

exit(0);
