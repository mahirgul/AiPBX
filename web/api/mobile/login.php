<?php
require_once __DIR__ . '/auth_helper.php';
mobileApiStart('GET, POST, OPTIONS');

$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

$json = mobileInput();

$username = trim($json['username'] ?? $_POST['username'] ?? '');
$password = (string) ($json['password'] ?? $_POST['password'] ?? '');
$device_name = trim($json['device_name'] ?? $_POST['device_name'] ?? 'Android');

if (empty($username) || empty($password)) {
    mobileError(t('mobile_api.credentials_required'), 400);
}

// 1. Brute-force lockout check (5 failed attempts -> 15 minute lock)
if (checkBruteForceLockout($client_ip, $username)) {
    mobileError(t('mobile_api.too_many_attempts'), 429);
}

$user = findLoginUser($username, 'id, username, password_hash, full_name, email, role, extension, extension_type, sip_password, is_active, two_factor_enabled, two_factor_secret');

if (!$user || !verifyLoginPassword($password, (string) $user['password_hash'])) {
    logLoginAttempt($client_ip, $username, 'FAILED');
    mobileError(t('mobile_api.invalid_credentials'), 401);
}

if (empty($user['is_active'])) {
    logLoginAttempt($client_ip, $username, 'FAILED');
    mobileError(t('mobile_api.account_disabled'), 403);
}

if (empty($user['extension'])) {
    mobileError(t('mobile_api.no_extension_assigned'), 400);
}

// On an account with two-step verification the password alone is not enough
// (mobile sign-in used to skip 2FA completely). Without a code the app is told
// to ask for one; a wrong code counts as a failed attempt (5 → 15 min lock).
if (!empty($user['two_factor_enabled'])) {
    require_once __DIR__ . '/../../src/services/TwoFactorService.php';
    $otp = trim((string)($json['otp'] ?? $_POST['otp'] ?? ''));
    if ($otp === '' || !TwoFactorService::verifyCode((string)$user['two_factor_secret'], $otp)) {
        if ($otp !== '') {
            logLoginAttempt($client_ip, $username, 'FAILED');
        }
        mobileJson([
            'success' => false,
            'otp_required' => true,
            'error' => $otp === ''
                ? t('mobile_api.otp_required')
                : t('mobile_api.otp_invalid')
        ], 401);
    }
}

// Sign-in succeeded: log the attempt
logLoginAttempt($client_ip, $username, 'SUCCESS');

mobileJson(buildMobileLoginResponse($user));
