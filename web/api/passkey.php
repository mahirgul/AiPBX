<?php
/**
 * WebAuthn Passkey API Uç Noktası
 * Kayıt ve giriş WebAuthn seremonisi isteklerini yönetir.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../src/services/PasskeyService.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// JSON gövdesini oku (varsa)
$rawInput = file_get_contents('php://input');
$inputData = json_decode($rawInput, true) ?: $_POST;

if (empty($action) && isset($inputData['action'])) {
    $action = $inputData['action'];
}

switch ($action) {
    // -------------------------------------------------------------
    // GİRİŞ: Auth seçeneklerini al (Challenge üretir)
    // -------------------------------------------------------------
    case 'auth-options':
        $username = trim($inputData['username'] ?? $_GET['username'] ?? '');
        try {
            $args = PasskeyService::getLoginArgs(!empty($username) ? $username : null);
            echo json_encode(['success' => true, 'options' => $args]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;

    // -------------------------------------------------------------
    // GİRİŞ: Tarayıcı yanıtını doğrula ve oturum aç
    // -------------------------------------------------------------
    case 'auth-verify':
        $clientDataJSON = $inputData['clientDataJSON'] ?? '';
        $authenticatorData = $inputData['authenticatorData'] ?? '';
        $signature = $inputData['signature'] ?? '';
        $credentialId = $inputData['id'] ?? '';

        if (empty($clientDataJSON) || empty($authenticatorData) || empty($signature) || empty($credentialId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Eksik kimlik doğrulama parametreleri.']);
            exit;
        }

        $res = PasskeyService::processLogin($clientDataJSON, $authenticatorData, $signature, $credentialId, $clientIp);
        if ($res['success']) {
            echo json_encode($res);
        } else {
            http_response_code(401);
            echo json_encode($res);
        }
        exit;

    // -------------------------------------------------------------
    // KAYIT: Register seçeneklerini al (Giriş yapılmış olmalıdır)
    // -------------------------------------------------------------
    case 'register-options':
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Oturum açılmalıdır.']);
            exit;
        }

        $user = getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Kullanıcı bulunamadı.']);
            exit;
        }

        try {
            $args = PasskeyService::getRegisterArgs((int)$user['id'], $user['username'], $user['full_name']);
            echo json_encode(['success' => true, 'options' => $args]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;

    // -------------------------------------------------------------
    // KAYIT: Tarayıcı passkey oluşturma yanıtını doğrula ve kaydet
    // -------------------------------------------------------------
    case 'register-verify':
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Oturum açılmalıdır.']);
            exit;
        }

        $clientDataJSON = $inputData['clientDataJSON'] ?? '';
        $attestationObject = $inputData['attestationObject'] ?? '';
        $deviceName = $inputData['deviceName'] ?? 'Passkey';

        if (empty($clientDataJSON) || empty($attestationObject)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Eksik kayıt parametreleri.']);
            exit;
        }

        $res = PasskeyService::processRegister((int)$_SESSION['user_id'], $clientDataJSON, $attestationObject, $deviceName);
        if ($res['success']) {
            echo json_encode($res);
        } else {
            http_response_code(400);
            echo json_encode($res);
        }
        exit;

    // -------------------------------------------------------------
    // SİLME: Kayıtlı passkey'i sil
    // -------------------------------------------------------------
    case 'delete':
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Oturum açılmalıdır.']);
            exit;
        }

        $csrf = $inputData['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!verifyCSRFToken($csrf)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Güvenlik doğrulaması (CSRF) geçersiz.']);
            exit;
        }

        $passkeyId = (int)($inputData['passkey_id'] ?? 0);
        if ($passkeyId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Geçersiz Passkey ID.']);
            exit;
        }

        $deleted = PasskeyService::deletePasskey((int)$_SESSION['user_id'], $passkeyId);
        echo json_encode(['success' => $deleted, 'message' => $deleted ? 'Passkey silindi.' : 'Passkey silinemedi.']);
        exit;

    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Geçersiz işlem.']);
        exit;
}
