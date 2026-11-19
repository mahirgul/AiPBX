#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Faz 4: CLI worker for `ai_jobs` (`ai-voicemail` and `ai-call-analysis`).
 *
 * Usage:
 *   php web/bin/ai_job_worker.php --once
 *   php web/bin/ai_job_worker.php [--sleep=2] [--lease=120]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$webRoot = dirname(__DIR__);
require_once $webRoot . '/config.php';
require_once $webRoot . '/src/services/ai/AiJobService.php';
require_once $webRoot . '/src/services/ai/AiGateway.php';
require_once $webRoot . '/src/services/VoicemailAiService.php';
require_once $webRoot . '/src/services/CdrAiService.php';

$cliArgs = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
$once = in_array('--once', $cliArgs, true);
$drain = in_array('--drain', $cliArgs, true);
$sleepSeconds = 2;
$leaseSeconds = 120;

foreach ($cliArgs as $arg) {
    if (str_starts_with($arg, '--sleep=')) {
        $sleepSeconds = max(1, (int) substr($arg, 8));
    } elseif (str_starts_with($arg, '--lease=')) {
        $leaseSeconds = max(10, (int) substr($arg, 8));
    }
}

$workerId = (gethostname() ?: 'worker') . ':' . getmypid();
$running = true;

if (!$once && function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $stop = static function () use (&$running): void {
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

$nonRetryableGatewayCodes = [
    'feature_disabled',
    'permission_denied',
    'policy_blocked',
    'binding_unresolved',
    'provider_auth_failed',
    'budget_exceeded',
    'configuration_changed',
];

$dispatch = static function (array $job) use ($nonRetryableGatewayCodes): void {
    $jobId = (int) $job['id'];
    $leaseOwner = isset($job['lease_owner']) ? (string) $job['lease_owner'] : null;
    $entityType = (string) ($job['entity_type'] ?? '');
    $feature = (string) ($job['feature'] ?? '');

    try {
        if ($entityType === 'voicemail' || $feature === 'ai_voicemail') {
            $result = VoicemailAiService::processJob($job);
        } elseif ($entityType === 'cdr' || $feature === 'ai_call_analysis') {
            $result = CdrAiService::processJob($job);
        } else {
            AiJobService::fail($jobId, 'Unsupported job target: ' . $feature . '/' . $entityType, false, $leaseOwner);
            return;
        }

        AiJobService::complete($jobId, $result, $leaseOwner);
    } catch (AiGatewayException $e) {
        $retryable = !in_array($e->errorCode, $nonRetryableGatewayCodes, true);
        AiJobService::fail($jobId, $e->getMessage(), $retryable, $leaseOwner);
        fwrite(STDERR, "[ai_job_worker] job #{$jobId} gateway error ({$e->errorCode}): {$e->getMessage()}\n");
    } catch (\Throwable $e) {
        $retryable = ((int) $e->getCode() !== 400);
        AiJobService::fail($jobId, $e->getMessage(), $retryable, $leaseOwner);
        fwrite(STDERR, "[ai_job_worker] job #{$jobId} error: {$e->getMessage()}\n");
    }
};

try {
    AiJobService::recoverStaleLeases();
} catch (\Throwable $e) {
    fwrite(STDERR, '[ai_job_worker] recoverStaleLeases warning: ' . $e->getMessage() . "\n");
}

if ($once) {
    $job = AiJobService::claimNext($workerId, $leaseSeconds);
    if ($job !== null) {
        $dispatch($job);
    }
    exit(0);
}

if ($drain) {
    for ($i = 0; $i < 25; $i++) {
        $job = AiJobService::claimNext($workerId, $leaseSeconds);
        if ($job === null) {
            break;
        }
        $dispatch($job);
    }
    exit(0);
}

$lastRecoveryAt = time();
while ($running) {
    if ((time() - $lastRecoveryAt) >= 30) {
        try {
            AiJobService::recoverStaleLeases();
        } catch (\Throwable $e) {
            fwrite(STDERR, '[ai_job_worker] recoverStaleLeases warning: ' . $e->getMessage() . "\n");
        }
        $lastRecoveryAt = time();
    }

    $job = AiJobService::claimNext($workerId, $leaseSeconds);
    if ($job !== null) {
        $dispatch($job);
        continue;
    }

    sleep($sleepSeconds);
}

exit(0);
