<?php
/**
 * AiPBX Admin — data.json Save API
 * Securely updates the central data.json file after admin authentication.
 */
require_once __DIR__ . '/../includes/session.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

// Session check
if (empty($_SESSION['admin_logged_in']) ||
    (time() - ($_SESSION['admin_login_time'] ?? 0)) >= SESSION_LIFETIME) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Oturum süresi doldu. Lütfen tekrar giriş yapın.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

// CSRF check
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Güvenlik doğrulaması (CSRF) başarısız.']);
    exit;
}

// Read raw JSON
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Geçersiz JSON formatı: ' . json_last_error_msg()]);
    exit;
}

// Schema check
$requiredKeys = ['company', 'stats', 'hero', 'flagshipFeatures', 'tables', 'technicalDeepDive', 'faq'];
foreach ($requiredKeys as $rk) {
    if (!array_key_exists($rk, $data) || !is_array($data[$rk])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Eksik veya geçersiz alan: {$rk}"]);
        exit;
    }
}

// Size limit check (max 5MB)
if (strlen($raw) > 5 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Veri boyutu çok büyük (>5MB).']);
    exit;
}

$file = __DIR__ . '/../data.json';
$backup = __DIR__ . '/../data.json.bak';

// Backup existing data.json
if (file_exists($file)) {
    @copy($file, $backup);
}

$formatted = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$written = @file_put_contents($file, $formatted, LOCK_EX);

if ($written !== false) {
    echo json_encode(['success' => true, 'message' => 'data.json başarıyla güncellendi.']);
} else {
    if (file_exists($backup)) {
        @copy($backup, $file);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Dosya yazılamadı. Lütfen sunucu dosya izinlerini kontrol edin.']);
}
