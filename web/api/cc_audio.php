<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
requireLogin();

$user = getCurrentUser();
$role = $_SESSION['user_role'] ?? 'fax_user';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('Invalid ID');
}

$db = getDB();
$stmt = $db->prepare('SELECT recording_path, agent_extension, caller_num FROM cdrs WHERE id = ?');
$stmt->execute([$id]);
$cdr = $stmt->fetch();

if (!$cdr) {
    http_response_code(404);
    die('Record not found');
}

$user_ext = $user['extension'] ?? '';
// agent_extension artık kuyruk-yönlendirmesiz aramalarda ARANAN tarafı da
// gösterebiliyor (bkz. cdrs view güncellemesi) — "kendi araması" kontrolü hem
// arayan hem aranan tarafı kapsasın diye caller_num de ayrıca kontrol ediliyor.
$is_own_call = (!empty($user_ext) && ($cdr['agent_extension'] === $user_ext || $cdr['caller_num'] === $user_ext));
$can_listen = !empty($user['can_listen_recordings']);

if ($role !== 'admin' && !$can_listen && !$is_own_call) {
    http_response_code(403);
    die('Access denied');
}

$file = realpath($cdr['recording_path']);
$allowed_dir = realpath(MONITOR_STORAGE_PATH);

if (!$file || !$allowed_dir || strpos($file, $allowed_dir) !== 0 || !file_exists($file)) {
    http_response_code(404);
    die('Audio file not found');
}

header('Content-Type: audio/wav');
header('Content-Length: ' . filesize($file));
if (!empty($_GET['download'])) {
    header('Content-Disposition: attachment; filename="cagri_kaydi_' . $id . '.wav"');
}
readfile($file);
exit;
