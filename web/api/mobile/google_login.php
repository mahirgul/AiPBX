<?php
/**
 * Mobile Google OAuth / ID token sign-in endpoint
 * Lets the Android and iOS apps sign in with Google without a password.
 */
require_once __DIR__ . '/auth_helper.php';
mobileApiStart('GET, POST, OPTIONS');

require_once __DIR__ . '/../../src/services/GoogleAuthService.php';

$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// This endpoint must not work while Google sign-in is off (it used to be unchecked).
if (!GoogleAuthService::isEnabled()) {
    mobileError(t('mobile_api.google_disabled'), 403);
}

$json = mobileInput();

$idToken = trim($json['id_token'] ?? $_POST['id_token'] ?? '');
$code = trim($json['code'] ?? $_POST['code'] ?? '');
$device_name = trim($json['device_name'] ?? $_POST['device_name'] ?? 'Mobile');

if (empty($idToken) && empty($code)) {
    mobileError('Google id_token veya authorization code parametresi gereklidir.', 400);
}

$userInfo = null;

// 1. Google ID token verification
if (!empty($idToken)) {
    $userInfo = GoogleAuthService::verifyIdToken($idToken);
}

// 2. Or the Google authorization code exchange
if (!$userInfo && !empty($code)) {
    $tokens = GoogleAuthService::exchangeCode($code);
    if (!empty($tokens['id_token'])) {
        $userInfo = GoogleAuthService::verifyIdToken($tokens['id_token']);
    } elseif (!empty($tokens['access_token'])) {
        $userInfo = GoogleAuthService::getUserInfo($tokens['access_token']);
    }
}

if (!$userInfo || empty($userInfo['email'])) {
    mobileError(t('mobile_api.google_verify_failed'), 401);
}

$email = $userInfo['email'];

// 3. Match the active user in the database by email address
$user = GoogleAuthService::findUserByEmail($email);

if (!$user) {
    if (function_exists('logLoginAttempt')) {
        logLoginAttempt($client_ip, $email, 'FAILED');
    }
    mobileError(sprintf(t('mobile_api.google_no_match'), $email), 404);
}

if (empty($user['is_active'])) {
    mobileError(t('mobile_api.account_disabled'), 403);
}

if (empty($user['extension'])) {
    mobileError(t('mobile_api.no_extension_assigned'), 400);
}

if (!empty($user['two_factor_enabled'])) {
    mobileJson([
        'success' => false,
        'otp_required' => true,
        'error' => t('mobile_api.google_otp_enabled')
    ], 401);
}

// Sign-in succeeded: write the log
if (function_exists('logLoginAttempt')) {
    logLoginAttempt($client_ip, $user['username'], 'SUCCESS');
}
if (function_exists('writeAuditLog')) {
    writeAuditLog($user['id'], 'sys_users', $user['id'], "User '{$user['username']}' signed in from the mobile app with Google ({$email}).", 'google_mobile_login');
}

// Return the standard mobile sign-in response
$response = buildMobileLoginResponse($user);
mobileJson($response);
