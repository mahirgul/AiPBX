<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
requireLogin();

$fax_id = intval($_GET['id'] ?? 0);
$type = $_GET['type'] ?? 'received';
$is_view = isset($_GET['view']) && $_GET['view'] == 1;

if ($fax_id <= 0) {
    http_response_code(400);
    die('Invalid Fax ID');
}

// Sahiplik kontrolü aşağıda zaten var (IDOR yok) ama bu, o modülün rolden
// tamamen kaldırılmış olması durumunu yakalamıyordu — requireLogin() dışında
// hiçbir modül-seviyesi RBAC kontrolü yoktu (2026-08-21 denetiminde bulundu).
$fax_module_key = ($type === 'sent') ? 'fax_sent' : 'fax_inbox';
requireModulePermission($fax_module_key, 'view');

$db = getDB();
$user_role = $_SESSION['user_role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;
$user_ext = $_SESSION['extension'] ?? '';

if ($type === 'sent') {
    $stmt = $db->prepare('SELECT id, user_id, sender_extension as did_extension, pdf_path FROM fax_sent WHERE id = ?');
    $stmt->execute([$fax_id]);
    $fax = $stmt->fetch();
    
    if (!$fax) {
        http_response_code(404);
        die('Faks kaydı bulunamadı');
    }
    
    if ($user_role !== 'admin' && $fax['user_id'] != $user_id) {
        http_response_code(403);
        die('Yetkisiz Erişim');
    }
} else {
    $stmt = $db->prepare('SELECT id, did_extension, pdf_path FROM fax_received WHERE id = ?');
    $stmt->execute([$fax_id]);
    $fax = $stmt->fetch();
    
    if (!$fax) {
        http_response_code(404);
        die('Faks kaydı bulunamadı');
    }
    
    if ($user_role !== 'admin') {
        require_once __DIR__ . '/../src/repositories/FaxInboxRepository.php';
        $allowed_dids = FaxInboxRepository::getAllowedDIDs($user_id, $user_ext);
        if (!in_array($fax['did_extension'], $allowed_dids, true)) {
            http_response_code(403);
            die('Yetkisiz Erişim');
        }
    }

    $db->prepare('UPDATE fax_received SET is_read = 1 WHERE id = ?')->execute([$fax_id]);
}

$file_path = realpath($fax['pdf_path']);
$allowed_dir = realpath(FAX_STORAGE_PATH);

if (!$file_path || !$allowed_dir || strpos($file_path, $allowed_dir) !== 0 || !file_exists($file_path)) {
    http_response_code(404);
    die('Faks dosyası sunucuda bulunamadı veya erişim engellendi');
}

header('Content-Type: application/pdf');

if ($is_view) {
    header('Content-Disposition: inline; filename="fax_' . $fax_id . '.pdf"');
} else {
    header('Content-Disposition: attachment; filename="fax_' . $fax_id . '.pdf"');
}

header('Content-Length: ' . filesize($file_path));
readfile($file_path);
exit;
