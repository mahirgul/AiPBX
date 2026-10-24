<?php

declare(strict_types=1);

/**
 * Loopback API endpoint for Asterisk IVR Data Steps (Roadmap 2.3).
 * Called by Asterisk CURL() during call routing on loopback.
 * Returns: <status>|<value>
 * Status: 'found', 'not_found', 'timeout'
 */
// Asterisk on this server only; a request that came through a proxy is not it.
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)
    || !empty($_SERVER['HTTP_X_FORWARDED_FOR']) || !empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
    http_response_code(403);
    echo "timeout|forbidden\n";
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/IvrDataStepService.php';

$stepId = (int)($_REQUEST['step_id'] ?? 0);
$input = trim((string)($_REQUEST['input'] ?? ''));
$callerId = trim((string)($_REQUEST['caller_id'] ?? ''));

if ($stepId <= 0) {
    echo "not_found|\n";
    exit(0);
}

$res = IvrDataStepService::performLookup($stepId, $input, $callerId);
echo $res['status'] . '|' . $res['val'] . "\n";
exit(0);
