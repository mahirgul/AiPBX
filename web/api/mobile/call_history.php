<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/../../src/core/BaseRepository.php';
require_once __DIR__ . '/../../src/repositories/MyPhoneRepository.php';

$user = requireMobileAuth();
$ext = trim($user['extension'] ?? '');

if ($ext === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Kullanıcıya ait dahili numara bulunamadı.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$filter = trim($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'in', 'out', 'missed'], true)) {
    $filter = 'all';
}

$search = trim($_GET['q'] ?? '');
$limit = min(max((int)($_GET['limit'] ?? 50), 1), 100);

$calls = MyPhoneRepository::getRecentCalls(
    $ext,
    $filter === 'all' ? null : $filter,
    $search !== '' ? $search : null,
    $limit
);

$stats = MyPhoneRepository::getCallStats($ext);

echo json_encode([
    'success' => true,
    'extension' => $ext,
    'filter' => $filter,
    'total_returned' => count($calls),
    'stats' => $stats,
    'calls' => $calls
], JSON_UNESCAPED_UNICODE);
