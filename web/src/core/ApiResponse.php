<?php

declare(strict_types=1);

/**
 * Standardized JSON Response Envelope for /api/v1/* endpoints.
 */
final class ApiResponse
{
    private static ?string $requestId = null;

    public static function requestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = 'req_' . bin2hex(random_bytes(8));
        }
        return self::$requestId;
    }

    /**
     * Emit a successful JSON response and terminate the request.
     *
     * @param mixed $data
     * @param array<string, mixed> $meta
     */
    public static function success(mixed $data = null, array $meta = [], int $httpCode = 200): never
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . self::requestId());

        $pendingCount = 0;
        if (function_exists('getPendingSyncCount')) {
            $pendingCount = getPendingSyncCount();
        } else {
            $syncFile = dirname(__DIR__) . '/asterisk_sync.php';
            if (is_file($syncFile)) {
                require_once $syncFile;
                if (function_exists('getPendingSyncCount')) {
                    $pendingCount = getPendingSyncCount();
                }
            }
        }

        $envelope = [
            'success' => true,
            'data'    => $data,
            'error'   => null,
            'meta'    => array_merge([
                'pending_sync_count' => $pendingCount,
                'request_id'         => self::requestId(),
            ], $meta),
        ];

        echo json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Emit a structured error JSON response and terminate the request.
     *
     * @param array<string, mixed> $details
     */
    public static function error(string $code, string $message, int $httpCode = 400, array $details = []): never
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . self::requestId());

        $envelope = [
            'success' => false,
            'data'    => null,
            'error'   => [
                'code'    => $code,
                'message' => $message,
                'details' => (object)$details,
            ],
            'meta'    => [
                'request_id' => self::requestId(),
            ],
        ];

        echo json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Convert a legacy service result array (`['success' => bool, 'message'|'error' => string]`)
     * into a standardized API response.
     *
     * @param array<string, mixed> $serviceResult
     * @param mixed $resourceData Optional updated/created entity record to return in `data`
     */
    public static function fromServiceResult(array $serviceResult, mixed $resourceData = null, int $successHttpCode = 200): never
    {
        if (empty($serviceResult['success'])) {
            $errMsg = (string)($serviceResult['error'] ?? 'Operation failed');
            $httpCode = str_contains(strtolower($errMsg), 'not found') ? 404 : 422;
            self::error('VALIDATION_ERROR', $errMsg, $httpCode);
        }

        $payload = $resourceData !== null
            ? $resourceData
            : ['message' => (string)($serviceResult['message'] ?? 'OK')];

        $meta = [];
        if (isset($serviceResult['message'])) {
            $meta['message'] = (string)$serviceResult['message'];
        }
        if (isset($serviceResult['generated_password'])) {
            $meta['generated_password'] = (string)$serviceResult['generated_password'];
        }

        self::success($payload, $meta, $successHttpCode);
    }
}
