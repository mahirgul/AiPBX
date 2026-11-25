#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Nightly External DB -> Phonebook sync CLI runner (D3).
 * Usage: php /opt/aipbx/web/bin/phonebook_extdb_sync.php [--force]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/PhonebookService.php';

$force = in_array('--force', $argv ?? [], true);
$cfg = PhonebookService::externalDbSettings();

if (!$force && (empty($cfg['enabled']) || $cfg['db_id'] <= 0 || trim($cfg['sql']) === '')) {
    exit(0);
}

try {
    $res = PhonebookService::syncFromExternalDb();
    echo sprintf("[phonebook_extdb_sync] added=%d updated=%d skipped=%d\n", $res['added'], $res['updated'], $res['skipped']);
} catch (\Throwable $e) {
    fwrite(STDERR, '[phonebook_extdb_sync] ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
