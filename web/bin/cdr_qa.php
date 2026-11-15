#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Automatic call quality review (CdrAiService::autoReview), from /etc/cron.d/aipbx every
 * 10 minutes. Does nothing unless an administrator wrote review criteria and turned the
 * automatic review on; at most the daily maximum of calls.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/CdrAiService.php';

$fp = fopen(sys_get_temp_dir() . '/aipbx-cdr-qa.lock', 'c');
if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
    exit(0);                     // the previous run is still busy
}
$n = CdrAiService::autoReview();
if ($n > 0) {
    echo "[cdr_qa] reviewed {$n} call(s)\n";
}
