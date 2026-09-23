<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/VoicemailService.php';

requireLogin();

$user = getCurrentUser();
$role = $_SESSION['user_role'] ?? 'fax_user';
$userExt = trim($user['extension'] ?? '');

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');
$targetExt = trim($_GET['ext'] ?? ($_POST['ext'] ?? $userExt));

// Sadece kendi dahilisini dinleyebilir/yönetebilir veya admin rolünde olmalıdır
if ($role !== 'admin' && ($targetExt === '' || $targetExt !== $userExt)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Bu sesli postaya erişim yetkiniz yok.']);
    exit;
}

if ($action === 'play') {
    $folder = preg_replace('/[^a-zA-Z]/', '', $_GET['folder'] ?? 'INBOX');
    $msg = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['msg'] ?? '');

    if (empty($targetExt) || empty($msg)) {
        http_response_code(400);
        die('Geçersiz parametre');
    }

    $basePath = "/var/spool/asterisk/voicemail/default/{$targetExt}/{$folder}/{$msg}";
    $filePath = null;

    if (file_exists("{$basePath}.wav")) {
        $filePath = "{$basePath}.wav";
    } elseif (file_exists("{$basePath}.WAV")) {
        $filePath = "{$basePath}.WAV";
    }

    if (!$filePath || !file_exists($filePath)) {
        http_response_code(404);
        die('Ses dosyası bulunamadı');
    }

    header('Content-Type: audio/wav');
    header('Content-Length: ' . filesize($filePath));
    if (isset($_GET['download']) && $_GET['download'] == 1) {
        header('Content-Disposition: attachment; filename="' . $msg . '.wav"');
    }
    readfile($filePath);
    exit;
}

if ($action === 'delete') {
    $csrf = $_POST['csrf_token'] ?? '';
    $msgId = $_POST['msg_id'] ?? '';
    $res = VoicemailService::deleteMessage($targetExt, $msgId, $csrf);
    echo json_encode($res);
    exit;
}

if ($action === 'list') {
    $messages = VoicemailService::getVoicemailMessages($targetExt);
    echo json_encode(['success' => true, 'messages' => $messages]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Geçersiz işlem']);
