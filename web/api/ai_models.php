<?php
/**
 * AI → Local models API (admin only).
 *   GET  ?action=status                 → runtime, service health and models (the page polls)
 *   POST action=runtime_install          → install the local AI runtime in the background
 *   POST action=runtime_remove           → remove the runtime and every downloaded model
 *   POST action=model_install id= accept_license=1
 *   POST action=model_remove id=
 *   POST action=benchmark id=           → speed of the model on this server
 *   POST action=model_run id= / model_stop id=   → load into memory / unload (files stay)
 *   POST action=try id= text= speed=    → audio/wav of a voice model (the page's try panel)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/LocalAiService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'status') {
    echo json_encode(['success' => true] + LocalAiService::status(), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}
if (!verifyCSRFToken($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => t('common.invalid_csrf')]);
    exit;
}
// Session lock released: a benchmark or removal can take a while.
session_write_close();

$id = (string) ($_POST['id'] ?? '');
try {
    $out = ['success' => true];
    switch ($action) {
        case 'runtime_install':
            LocalAiService::installRuntime();
            $out['message'] = t('ai_models.msg_runtime_installing');
            break;
        case 'runtime_remove':
            LocalAiService::removeRuntime();
            $out['message'] = t('ai_models.msg_runtime_removed');
            break;
        case 'model_install':
            LocalAiService::installModel($id, !empty($_POST['accept_license']));
            $out['message'] = t('ai_models.msg_downloading');
            break;
        case 'model_remove':
            LocalAiService::removeModel($id);
            $out['message'] = t('ai_models.msg_model_removed');
            break;
        case 'benchmark':
            $out['benchmark'] = LocalAiService::benchmark($id);
            break;
        case 'model_run':
            LocalAiService::runModel($id);
            $out['message'] = t('ai_models.msg_starting');
            break;
        case 'model_stop':
            LocalAiService::stopModel($id);
            $out['message'] = t('ai_models.msg_stopped');
            break;
        case 'try':
            $text = trim((string) ($_POST['text'] ?? ''));
            if ($text === '' || mb_strlen($text) > 1000) {
                throw new LocalAiException(t('ai_models.err_try_text'));
            }
            $started = microtime(true);
            $wav = LocalAiService::tts($id, $text, max(0.5, min(2.0, (float) ($_POST['speed'] ?? 1))), 24000);
            header('Content-Type: audio/wav');
            header('X-Synthesis-Ms: ' . (int) round((microtime(true) - $started) * 1000));
            echo $wav;
            exit;
        default:
            http_response_code(400);
            $out = ['success' => false, 'error' => 'Unknown action'];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (LocalAiException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
