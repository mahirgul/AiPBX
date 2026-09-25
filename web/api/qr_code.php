<?php
/**
 * Web QR Code Generator & Status Check API
 * Dahilim ekranında oturumu açık olan kullanıcının mobil eşleştirme QR kodunu üretir
 * ve mobil uygulama okuduğunda durumu kontrol eder.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/QrLoginService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Oturum açmanız gerekmektedir.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim($_REQUEST['action'] ?? 'generate');

if ($action === 'generate') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!verifyCSRFToken($csrf)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Geçersiz CSRF güvenlik kodu.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $res = QrLoginService::generateQr((int)$_SESSION['user_id'], 600);
    if (!$res['success']) {
        http_response_code(400);
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
} elseif ($action === 'status') {
    $token = trim($_REQUEST['token'] ?? '');
    if (empty($token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Token gereklidir.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $status = QrLoginService::checkStatus($token);
    echo json_encode(['success' => true, 'data' => $status], JSON_UNESCAPED_UNICODE);
    exit;
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Geçersiz işlem.'], JSON_UNESCAPED_UNICODE);
    exit;
}
