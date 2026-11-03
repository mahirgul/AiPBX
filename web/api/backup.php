<?php
/**
 * Backup API (admin only).
 *   GET  ?action=status              list, whether a backup is running, last restore, log
 *   GET  ?action=download&name=N&csrf_token=T   the backup as .tar.gz
 *   POST action=create|delete|keep|upload_start|upload_chunk|upload_finish|restore
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/BackupService.php';

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'download') {
    // A link, not a form, so the token comes in the URL: it is one secret request of the
    // administrator's own browser, and without the check another site could start one.
    if (!verifyCSRFToken($_GET['csrf_token'] ?? '')) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
    BackupService::download((string) ($_GET['name'] ?? ''));
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($action === 'status') {
    echo json_encode([
        'success' => true,
        'backups' => array_map(static function (array $b): array {
            $b['size'] = BackupService::humanSize($b['bytes']);
            $b['date'] = date('d.m.Y H:i', $b['time']);
            return $b;
        }, BackupService::all()),
        'creating' => BackupService::creating(),
        'status' => BackupService::status(),
        'log' => BackupService::logTail(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}
if (!verifyCSRFToken($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$result = match ($action) {
    'create' => BackupService::create(),
    'delete' => BackupService::delete((string) ($_POST['name'] ?? '')),
    'keep' => BackupService::setKeepDays((int) ($_POST['days'] ?? 0)),
    'upload_start' => BackupService::uploadStart(),
    'upload_chunk' => BackupService::uploadChunk((string) ($_POST['token'] ?? ''), (int) ($_POST['offset'] ?? -1), $_FILES['chunk'] ?? []),
    'upload_finish' => BackupService::uploadFinish((string) ($_POST['token'] ?? '')),
    'restore' => BackupService::restore($_POST, (int) ($_SESSION['user_id'] ?? 0)),
    default => null,
};
if ($result === null) {
    http_response_code(400);
    $result = ['success' => false, 'error' => 'Invalid action'];
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
