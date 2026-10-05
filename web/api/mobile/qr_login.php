<?php
/**
 * Mobile QR Code Login Endpoint
 * Lets the mobile apps (Android & iOS) sign in without a password using the QR token they read with the camera.
 */
require_once __DIR__ . '/auth_helper.php';
mobileApiStart('GET, POST, OPTIONS');

require_once __DIR__ . '/../../src/services/QrLoginService.php';

$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

$json = mobileInput();

$qr_token = trim($json['qr_token'] ?? $_POST['qr_token'] ?? '');
$device_name = trim($json['device_name'] ?? $_POST['device_name'] ?? 'Mobile Device');

if (empty($qr_token)) {
    mobileError('QR kod anahtarı (qr_token) gereklidir.', 400);
}

$res = QrLoginService::authenticateMobile($qr_token, $device_name, $client_ip);

if (!$res['success']) {
    mobileError($res['error'] ?? 'Giriş yapılamadı.', (int) ($res['code'] ?? 401));
}

mobileJson($res['response']);
