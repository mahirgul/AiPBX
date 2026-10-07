<?php
require_once __DIR__ . '/auth_helper.php';
mobileApiStart('GET, POST, OPTIONS');

$token = getMobileBearerToken();
$user = validateMobileToken($token);

if (!$user) {
    mobileError(t('mobile_api.session_expired'), 401);
}

if (empty($user['is_active'])) {
    mobileError(t('mobile_api.account_disabled'), 403);
}

// Exactly the same package as the sign-in response (new 30-day token, SIP, TURN, push).
// There used to be a separate copy here, missing the native_sip_username/sip_port/email
// fields.
mobileJson(buildMobileLoginResponse($user));
