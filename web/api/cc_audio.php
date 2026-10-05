<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
requireLogin();

$user = getCurrentUser();
$role = $_SESSION['user_role'] ?? 'user';

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
// agent_extension can now also show the CALLED side on calls not routed
// through a queue (see the cdrs view update) — caller_num is checked
// separately too, so the "own call" check covers both caller and callee.
$is_own_call = (!empty($user_ext) && ($cdr['agent_extension'] === $user_ext || $cdr['caller_num'] === $user_ext));
$can_listen = !empty($user['can_listen_recordings']);

if ($role !== 'admin' && !$can_listen && !$is_own_call) {
    http_response_code(403);
    die('Access denied');
}

$file = realpath($cdr['recording_path']);
$allowed_dir = realpath(MONITOR_STORAGE_PATH);

// Trailing '/' — a plain prefix comparison would also accept sibling
// directories such as "/monitor2/...".
if (!$file || !$allowed_dir || strpos($file, rtrim($allowed_dir, '/') . '/') !== 0 || !is_file($file)) {
    http_response_code(404);
    die('Audio file not found');
}

// The queue recording format can be something other than wav (record_format).
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime = ['wav' => 'audio/wav', 'mp3' => 'audio/mpeg', 'gsm' => 'audio/x-gsm', 'ogg' => 'audio/ogg'][$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
if (!empty($_GET['download'])) {
    header('Content-Disposition: attachment; filename="cagri_kaydi_' . $id . '.' . ($ext !== '' ? $ext : 'wav') . '"');
}
readfile($file);
exit;
