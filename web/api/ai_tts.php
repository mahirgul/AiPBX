<?php
/**
 * AI → Cloud TTS API (module ai_tts).
 *   GET  ?action=voices&provider=ID[&refresh=1]   voices of a configured provider
 *   GET  ?action=audio&id=N[&download=1]          the MP3 of a history entry
 *   POST action=synthesize  provider, voice, language, text, speed
 *   POST action=save_announcement  id, sound_name, title
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/AiTtsService.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function aiTtsJson(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!hasModulePermission('ai_tts', 'view')) {
    aiTtsJson(['success' => false, 'error' => 'Unauthorized'], 403);
}

if ($action === 'audio') {
    $id = (int) ($_GET['id'] ?? 0);
    $path = AiTtsService::audioPath($id);
    if ($id <= 0 || !AiTtsService::get($id) || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: audio/mpeg');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=3600');
    if (!empty($_GET['download'])) {
        header('Content-Disposition: attachment; filename="tts-' . $id . '.mp3"');
    }
    readfile($path);
    exit;
}

if ($action === 'voices') {
    try {
        aiTtsJson(['success' => true, 'voices' => AiTtsService::voices((string) ($_GET['provider'] ?? ''), !empty($_GET['refresh']))]);
    } catch (\Throwable $e) {
        aiTtsJson(['success' => false, 'error' => $e->getMessage()]);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aiTtsJson(['success' => false, 'error' => 'POST required'], 405);
}
if (!verifyCSRFToken($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    aiTtsJson(['success' => false, 'error' => t('common.invalid_csrf')], 403);
}
if (!hasModulePermission('ai_tts', 'edit')) {
    aiTtsJson(['success' => false, 'error' => 'Unauthorized'], 403);
}

try {
    if ($action === 'synthesize') {
        // Long texts take several provider calls.
        @set_time_limit(300);
        $row = AiTtsService::synthesize(
            (string) ($_POST['provider'] ?? ''),
            (string) ($_POST['voice'] ?? ''),
            (string) ($_POST['language'] ?? ''),
            (string) ($_POST['text'] ?? ''),
            (float) ($_POST['speed'] ?? 1)
        );
        aiTtsJson(['success' => true, 'item' => $row]);
    }
    if ($action === 'save_announcement') {
        $file = AiTtsService::saveAsAnnouncement((int) ($_POST['id'] ?? 0), (string) ($_POST['sound_name'] ?? ''), (string) ($_POST['title'] ?? ''));
        aiTtsJson(['success' => true, 'announcement' => $file, 'message' => sprintf(t('ai_tts.msg_saved_announcement'), $file)]);
    }
} catch (\Throwable $e) {
    aiTtsJson(['success' => false, 'error' => $e->getMessage()]);
}
aiTtsJson(['success' => false, 'error' => 'Invalid action'], 400);
