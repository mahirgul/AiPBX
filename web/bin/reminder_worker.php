#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI worker for due AI Reminders & Wake-Up Calls (ai_reminders).
 * Usage: php /opt/aipbx/web/bin/reminder_worker.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/ai/ReminderService.php';

$due = ReminderService::dueReminders(20);
$ok = 0;
foreach ($due as $rem) {
    if (ReminderService::dispatchOne($rem)) {
        $ok++;
    }
}
if ($due !== []) {
    echo sprintf("[reminder_worker] processed=%d dispatched=%d\n", count($due), $ok);
}
