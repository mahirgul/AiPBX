<?php

declare(strict_types=1);

/**
 * Loopback endpoint of Dynamic Routing (database and URL sources).
 * Called by Asterisk CURL() during a call.
 * Returns: <status>|<value>|NAME=value|... (status: found, not_found, timeout)
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

$stepId = (int) ($_GET['step_id'] ?? 0);
if ($stepId <= 0) {
    echo "not_found|\n";
    exit(0);
}

$hit = (string) ($_GET['hit'] ?? '');
if ($hit !== '') {
    IvrDataStepService::recordBranchHit($stepId, $hit);
    echo "OK\n";
    exit(0);
}

// The variables the step's query or URL uses (SyncIvrDataSteps sends only those).
$values = [];
foreach ($_GET as $k => $v) {
    if (is_string($k) && is_string($v) && $k !== 'step_id') {
        $values[$k] = mb_substr(trim($v), 0, 256);
    }
}
echo DataSourceService::encodeAnswer(IvrDataStepService::performLookup($stepId, $values));
exit(0);
