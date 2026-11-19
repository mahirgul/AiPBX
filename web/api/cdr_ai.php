<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../src/services/CdrAiService.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$user = getCurrentUser();
$role = userEffectiveRole();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid ID']);
    exit;
}

$cdr = CdrAiService::resolveRecording($id);
if (!$cdr) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Record not found']);
    exit;
}

$userExt = (string) ($user['extension'] ?? '');
$isOwnCall = ($userExt !== '' && ($cdr['agent_extension'] === $userExt || $cdr['caller_num'] === $userExt));
$canListen = !empty($user['can_listen_recordings']);

if ($role !== 'admin' && !$canListen && !$isOwnCall) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => t('common.invalid_csrf')]);
        exit;
    }
    session_write_close();
    $lang = (string) ($_POST['lang'] ?? '');
    if (!isset(CloudAiService::languages()[$lang])) {
        $lang = CloudAiService::language((string) getSystemSetting('default_lang', 'tr'));
    }
    $force = !empty($_POST['force']);
    require_once __DIR__ . '/../src/services/ai/AiJobService.php';
    $jobId = CdrAiService::enqueue((string) $id, ['lang' => $lang, 'force' => $force, 'source' => 'manual']);
    $job = AiJobService::claimById($jobId, 'web-cdr:' . getmypid(), 180);
    $item = null;
    if ($job !== null) {
        $leaseOwner = (string) ($job['lease_owner'] ?? '');
        try {
            $result = CdrAiService::processJob($job);
            AiJobService::complete($jobId, $result, $leaseOwner);
        } catch (\Throwable $e) {
            AiJobService::fail($jobId, $e->getMessage(), false, $leaseOwner);
        }
        $item = CdrAiService::forCdr($id, $lang, false);
    } else {
        $item = CdrAiService::forCdr($id, $lang, $force);
    }
    if ($item !== null) {
        // The words of a recorded call were read out (maybe by a cloud service): who, which call, which engine.
        writeAuditLog(null, 'cdr', $id, 'Call record: ' . $cdr['caller_num'] . ' transcribed (' . $item['engine'] . ')', 'create', $user['id'] ?? null);
    }
    echo json_encode([
        'ok' => $item !== null,
        'item' => $item,
        'error' => $item === null ? t('cdr_reports.ai_failed') : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

session_write_close();
echo json_encode(['ok' => true, 'item' => CdrAiService::find($id)], JSON_UNESCAPED_UNICODE);
