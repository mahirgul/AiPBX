<?php

declare(strict_types=1);

/**
 * Loopback-only internal endpoint for PBX AI Tools (PbxToolRegistry).
 * GET  -> list JSON Schema tool catalog
 * POST -> execute a tool call {tool, args, caller}
 */
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)
    || !empty($_SERVER['HTTP_X_FORWARDED_FOR']) || !empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'text' => 'Forbidden']);
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/ai/AiAppService.php';
require_once dirname(__DIR__) . '/src/services/ai/PbxToolRegistry.php';

header('Content-Type: application/json; charset=utf-8');

$expectedKey = AiAppService::dialplanKey();
$givenKey = strtolower(trim((string) ($_GET['key'] ?? $_SERVER['HTTP_X_AIPBX_KEY'] ?? '')));
if ($expectedKey === '' || !hash_equals($expectedKey, $givenKey)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'text' => 'Unauthorized']);
    exit(1);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    echo json_encode(['ok' => true, 'tools' => PbxToolRegistry::all()], JSON_UNESCAPED_UNICODE);
    exit(0);
}

$raw = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body) || empty($body['tool'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'text' => 'Invalid request']);
    exit(1);
}

$res = PbxToolRegistry::execute(
    (string) $body['tool'],
    is_array($body['args'] ?? null) ? $body['args'] : [],
    is_array($body['caller'] ?? null) ? $body['caller'] : []
);
echo json_encode($res, JSON_UNESCAPED_UNICODE);
exit(0);
