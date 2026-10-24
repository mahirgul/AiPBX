<?php

declare(strict_types=1);

/**
 * Safe Night Restart Cron Script (Roadmap 2.5).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/NightRestartService.php';

$restarted = NightRestartService::executeRestart();
if ($restarted) {
    echo "[" . date('Y-m-d H:i:s') . "] Safe night restart executed successfully.\n";
}
exit(0);
