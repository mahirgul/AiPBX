<?php
/**
 * Sistem Güncelleme API'si (yalnızca admin).
 *   GET  ?action=status  → durum + günlük sonu (sayfa ilerlemeyi yoklar)
 *   POST action=check    → GitHub'da yeni sürüm var mı
 *   POST action=start    → güncellemeyi arka planda başlat (allow_calls=1 isteğe bağlı)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/SystemUpdateService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Yetkisiz']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'status') {
    echo json_encode([
        'success' => true,
        'current' => SystemUpdateService::currentVersion(),
        'status' => SystemUpdateService::status(),
        'check' => SystemUpdateService::lastCheck(),
        'log' => SystemUpdateService::logTail(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST gerekli']);
    exit;
}
if (!verifyCSRFToken($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Geçersiz CSRF doğrulama kodu']);
    exit;
}

if ($action === 'check') {
    echo json_encode(SystemUpdateService::check(), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($action === 'start') {
    echo json_encode(SystemUpdateService::start(!empty($_POST['allow_calls'])), JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Geçersiz işlem']);
