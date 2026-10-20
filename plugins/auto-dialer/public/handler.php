<?php
/**
 * Loopback Public Endpoint Handler (`/api/plugins/auto-dialer/callback`)
 * Invoked by Asterisk `CURL(...)` during outbound campaign calls to report
 * dial failures, AMD results (`HUMAN`/`MACHINE`/`NOTSURE`), and call duration.
 */
require_once dirname(__DIR__) . '/AutoDialerService.php';

return static function (string $subPath, array $settings): void {
    header('Content-Type: application/json; charset=utf-8');

    $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!in_array($remoteAddr, ['127.0.0.1', '::1', 'localhost'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Loopback access only']);
        return;
    }

    $params = array_merge($_GET, $_POST);
    $res = AutoDialerService::handleCallback($params);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
};
