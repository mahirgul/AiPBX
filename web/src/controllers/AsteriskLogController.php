<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/AsteriskLogService.php';

/**
 * Troubleshooting -> Asterisk Logs (/asterisk-logs).
 * Admins only.
 */
class AsteriskLogController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $available = AsteriskLogService::availableLogs();
        $defaultKey = isset($available['messages']) ? 'messages' : (array_key_first($available) ?: 'messages');
        $logKey = trim((string) ($_GET['log'] ?? $defaultKey));
        if (!isset(AsteriskLogService::LOG_FILES[$logKey])) {
            $logKey = $defaultKey;
        }
        $lines = (int) ($_GET['lines'] ?? 200);
        if (!in_array($lines, [100, 200, 500, 1000], true)) {
            $lines = 200;
        }
        $level = trim((string) ($_GET['level'] ?? ''));
        $search = trim((string) ($_GET['q'] ?? ''));

        $result = AsteriskLogService::readTail($logKey, $lines, $level, $search);

        static::renderPage('asterisk_logs/index', [
            'available' => $available,
            'log_key' => $logKey,
            'lines_limit' => $lines,
            'level' => $level,
            'search' => $search,
            'result' => $result,
        ], ['title' => t('asterisk_logs.title')]);
    }
}
