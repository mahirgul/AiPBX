#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Nightly KVKK / GDPR Data Retention Auto-Purge CLI runner.
 * Usage: php /opt/aipbx/web/bin/privacy_purge.php [--force]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/PrivacyService.php';

$force = in_array('--force', $argv ?? [], true);
$settings = PrivacyService::settings();

if (!$force && empty($settings['retention_enabled'])) {
    exit(0);
}

$res = PrivacyService::runRetentionPurge();
echo "[privacy_purge] " . $res['summary'] . "\n";
