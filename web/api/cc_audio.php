<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../src/services/RecordingStorage.php';
requireLogin();

$user = getCurrentUser();
$role = userEffectiveRole();

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

session_write_close();

// From the bucket (#69) or the monitor folder; RecordingStorage refuses paths outside it.
if (!RecordingStorage::send($cdr['recording_path'], !empty($_GET['download']) ? 'cagri_kaydi_' . $id : '')) {
    http_response_code(404);
    die('Audio file not found');
}
exit;
