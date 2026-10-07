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

// The ownership check below already exists (no IDOR), but it did not catch
// the case where the module was removed from the role entirely — there was no
// module-level RBAC check apart from requireLogin() (found in the 2026-08-21 audit).
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
        die(t('api.err_fax_not_found'));
    }
    
    if ($user_role !== 'admin' && $fax['user_id'] != $user_id) {
        http_response_code(403);
        die(t('api.err_unauthorized'));
    }
} else {
    $stmt = $db->prepare('SELECT id, did_extension, pdf_path FROM fax_received WHERE id = ?');
    $stmt->execute([$fax_id]);
    $fax = $stmt->fetch();
    
    if (!$fax) {
        http_response_code(404);
        die(t('api.err_fax_not_found'));
    }
    
    if ($user_role !== 'admin') {
        require_once __DIR__ . '/../src/repositories/FaxInboxRepository.php';
        $allowed_dids = FaxInboxRepository::getAllowedDIDs($user_id, $user_ext);
        if (!in_array($fax['did_extension'], $allowed_dids, true)) {
            http_response_code(403);
            die(t('api.err_unauthorized'));
        }
    }

    $db->prepare('UPDATE fax_received SET is_read = 1 WHERE id = ?')->execute([$fax_id]);
}

$file_path = realpath($fax['pdf_path']);
$allowed_dir = realpath(FAX_STORAGE_PATH);

// Trailing '/': a plain prefix comparison would also accept sibling directories such as "/var/www/faxes2/...".
if (!$file_path || !$allowed_dir || strpos($file_path, rtrim($allowed_dir, '/') . '/') !== 0 || !is_file($file_path)) {
    http_response_code(404);
    die(t('api.err_fax_file'));
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
