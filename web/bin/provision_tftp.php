#!/usr/bin/env php
<?php
/**
 * Every 5 minutes (cron, www-data): keeps the phones' files in the TFTP/FTP
 * folder current when "write phone files into the TFTP/FTP folder" is on
 * (PBX -> Phones -> settings), e.g. after a SIP password changed elsewhere.
 * Rewrites only files whose content changed; does nothing when switched off
 * and no files of it are left.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/PhoneProvisionService.php';

$r = PhoneProvisionService::exportTftp();
if ($r['written'] || $r['removed']) {
    openlog('aipbx-provision', LOG_PID, LOG_USER);
    syslog(LOG_INFO, sprintf('TFTP/FTP folder: %d file(s) written, %d removed, %d in total', $r['written'], $r['removed'], $r['files']));
}
