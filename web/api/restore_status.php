<?php
/**
 * Progress of a restore, for the page that started it. It works without a session and
 * without the database, because a restore replaces both. The token is the one the
 * restore request returned: only its hash is on the server, and nothing else is here.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$file = '/var/lib/aipbx/restore-status.json';
$raw = is_readable($file) ? file_get_contents($file) : false;
$data = is_string($raw) ? json_decode($raw, true) : null;
$token = (string) ($_SERVER['HTTP_X_RESTORE_TOKEN'] ?? '');
if (!is_array($data) || !preg_match('/^[0-9a-f]{64}$/D', $token)
    || !hash_equals((string) ($data['token_hash'] ?? ''), hash('sha256', $token))) {
    http_response_code(404);
    echo json_encode(['success' => false]);
    exit;
}
echo json_encode(['success' => true, 'state' => $data['state'] ?? '', 'step' => $data['step'] ?? ''], JSON_UNESCAPED_UNICODE);
