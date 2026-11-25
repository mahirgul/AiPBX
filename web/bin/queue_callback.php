#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/QueueCallbackService.php';
try {
    if (($argv[1] ?? '') === 'dispatch') {
        require_once dirname(__DIR__) . '/src/services/QueueCallbackDispatcher.php';
        define('CC_DISPATCH_ACTIVE', true);
        require_once dirname(__DIR__) . '/api/cc_actions/cc_lib.php';
        QueueCallbackDispatcher::dispatch(
            static function (): array {
                exec('asterisk -rx "queue show" 2>/dev/null', $lines, $status);
                return $status === 0 ? parseAsteriskQueuesOutput($lines) : [];
            },
            static function (array $agent, string $number, int $requestId, int $attempt): bool {
                [$context, $permission] = agentDialScope($agent, getDB());
                $response = sendAMICommand(buildOriginateCommand((string) $agent['extension'], $number, $context, $permission, $requestId, $attempt));
                return is_string($response) && str_contains($response, 'Response: Success');
            }
        );
        exit;
    }
    if (($argv[1] ?? '') === 'result') {
        QueueCallbackService::recordAutomaticResult((int) ($argv[2] ?? 0), (int) ($argv[3] ?? 0), (string) ($argv[4] ?? ''));
        exit;
    }
    if (($argv[1] ?? '') === 'complete') {
        QueueCallbackService::complete((string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''), (int) ($argv[4] ?? 0));
        exit;
    }
    echo QueueCallbackService::request((int) ($argv[1] ?? 0), (string) ($argv[2] ?? '')) ? 'OK' : 'FAIL';
} catch (Throwable $e) {
    error_log('queue_callback: ' . $e->getMessage());
    echo 'FAIL';
}
