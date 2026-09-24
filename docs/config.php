<?php
/**
 * AiPBX — Central Configuration
 * Security & session parameters for data management.
 */
if (defined('AIPBX_CONFIG_LOADED')) return;
define('AIPBX_CONFIG_LOADED', true);

define('ADMIN_USERNAME', 'admin');

$_creds_file = __DIR__ . '/admin/.credentials.php';
if (file_exists($_creds_file)) {
    $__c = require $_creds_file;
    define('ADMIN_PASSWORD_HASH', $__c['hash'] ?? '');
    define('ADMIN_SETUP_DONE', !empty($__c['hash']));
    unset($__c);
} else {
    define('ADMIN_PASSWORD_HASH', '');
    define('ADMIN_SETUP_DONE', false);
}
unset($_creds_file);

define('SESSION_NAME',     'aipbx_admin_sess');
define('SESSION_LIFETIME', 7200);   // 2 hours
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION',   900);  // 15 minutes
