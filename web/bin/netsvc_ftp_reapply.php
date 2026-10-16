#!/usr/bin/env php
<?php
/**
 * install.sh --upgrade (root): writes the FTP server's configuration and
 * password file again from the portal's settings (PBX -> Network services).
 *
 * Why: up to 1.30.1 NECSDT700 and anonymous logins got in without a password.
 * The upgrade changes the PAM rules; without this the running FTP server
 * would keep its old configuration (anonymous on) until the page is saved,
 * and NECSDT700 would be missing from the password file.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/NetworkServicesService.php';

$r = NetworkServicesService::reapplyFtp();
echo trim((string) $r['output']) === '' ? '' : trim((string) $r['output']) . "\n";
exit($r['success'] ? 0 : 1);
