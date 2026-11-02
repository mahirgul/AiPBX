<?php
// When sign-in only goes through the identity provider and the provider is down:
//
//   sudo php /opt/aipbx/web/bin/aipbx_sso_recovery.php          prints a one-time link for an administrator
//   sudo php /opt/aipbx/web/bin/aipbx_sso_recovery.php --off    allows local sign-in again
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost';
require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/helpers.php';
require_once dirname(__DIR__) . '/src/audit_log.php';
require_once dirname(__DIR__) . '/src/services/SsoOnlyService.php';

if (in_array('--off', $_SERVER['argv'] ?? [], true)) {
    getDB()->prepare("UPDATE sys_settings SET setting_value = '0' WHERE setting_key = 'auth_sso_only'")->execute();
    writeAuditLog(null, 'auth_sso_only', 'recovery', 'Local sign-in allowed again from the server', 'update', null);
    echo "Local sign-in is allowed again.\n";
    exit(0);
}
echo "Open within 15 minutes, once, as an administrator:\n\n  https://<this server>/login?recovery=" . SsoOnlyService::createRecoveryToken() . "\n\n";
