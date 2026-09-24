<?php
/**
 * AiPBX Admin — Authentication API
 */
require_once __DIR__ . '/../includes/session.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: $_POST;

    $username = trim($body['username'] ?? '');
    $password = (string)($body['password'] ?? '');

    // Rate limiting via session
    $attempts = $_SESSION['login_attempts'] ?? 0;
    $lastAttempt = $_SESSION['last_attempt_time'] ?? 0;

    if ($attempts >= MAX_LOGIN_ATTEMPTS && (time() - $lastAttempt) < LOCKOUT_DURATION) {
        $waitMin = ceil((LOCKOUT_DURATION - (time() - $lastAttempt)) / 60);
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => "Çok fazla başarısız deneme. Lütfen {$waitMin} dakika bekleyin."]);
        exit;
    }

    if ($username === ADMIN_USERNAME && password_verify($password, ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_login_time'] = time();
        $_SESSION['login_attempts'] = 0;
        echo json_encode(['success' => true, 'redirect' => '../admin/index.php']);
        exit;
    }

    $_SESSION['login_attempts'] = $attempts + 1;
    $_SESSION['last_attempt_time'] = time();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Geçersiz kullanıcı adı veya parola.']);
    exit;
}

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'check') {
    $loggedIn = !empty($_SESSION['admin_logged_in']) &&
        (time() - ($_SESSION['admin_login_time'] ?? 0)) < SESSION_LIFETIME;
    echo json_encode(['authenticated' => $loggedIn]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
