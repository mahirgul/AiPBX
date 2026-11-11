#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/QueueCallbackService.php';
try {
    if (($argv[1] ?? '') === 'complete') {
        QueueCallbackService::complete((string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''), (int) ($argv[4] ?? 0));
        exit;
    }
    echo QueueCallbackService::request((int) ($argv[1] ?? 0), (string) ($argv[2] ?? '')) ? 'OK' : 'FAIL';
} catch (Throwable $e) {
    error_log('queue_callback: ' . $e->getMessage());
    echo 'FAIL';
}
