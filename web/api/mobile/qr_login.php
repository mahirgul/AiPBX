<?php
/**
 * Mobile QR Code Login Endpoint
 * Mobil uygulamaların (Android & iOS) kameradan okudukları QR token ile şifresiz giriş yapmasını sağlar.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/../../src/services/QrLoginService.php';

$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

$input_raw = file_get_contents('php://input');
$json = json_decode($input_raw, true) ?: [];

$qr_token = trim($json['qr_token'] ?? $_POST['qr_token'] ?? '');
$device_name = trim($json['device_name'] ?? $_POST['device_name'] ?? 'Mobile Device');

if (empty($qr_token)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'QR kod anahtarı (qr_token) gereklidir.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$res = QrLoginService::authenticateMobile($qr_token, $device_name, $client_ip);

if (!$res['success']) {
    http_response_code($res['code'] ?? 401);
    echo json_encode([
        'success' => false,
        'error' => $res['error'] ?? 'Giriş yapılamadı.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(200);
echo json_encode($res['response'], JSON_UNESCAPED_UNICODE);
