<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
requireLogin();

$file = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['file'] ?? '');
if (empty($file)) {
    http_response_code(400);
    die('Invalid file');
}

$path = SOUNDS_CUSTOM_DIR . "/$file.wav";
if (!file_exists($path)) {
    http_response_code(404);
    die('Sound file not found');
}

if (isset($_GET['download']) && $_GET['download'] == 1) {
    header('Content-Disposition: attachment; filename="' . $file . '.wav"');
}

header('Content-Type: audio/wav');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
