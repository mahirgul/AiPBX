<?php
require_once __DIR__ . '/auth_helper.php';
mobileApiStart('GET, POST, OPTIONS');

require_once __DIR__ . '/../../src/core/BaseRepository.php';
require_once __DIR__ . '/../../src/repositories/MyPhoneRepository.php';

$user = requireMobileAuth();
$ext = trim($user['extension'] ?? '');

if ($ext === '') {
    mobileError(t('mobile_api.no_extension_for_user'), 400);
}

$userId = (int)$user['id'];

// Remove calls from this user's history (#10). The CDR stays untouched.
//   {"action": "hide", "call_keys": ["1728...", ...]}   or   {"action": "clear"}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = mobileInput();
    $action = (string)($json['action'] ?? '');
    if ($action === 'clear') {
        MyPhoneRepository::clearCallHistory($userId);
        mobileJson(['success' => true, 'cleared' => true]);
    }
    if ($action === 'hide' && is_array($json['call_keys'] ?? null)) {
        mobileJson(['success' => true, 'hidden' => MyPhoneRepository::hideCalls($userId, $json['call_keys'])]);
    }
    mobileError(t('mobile_api.invalid_request'), 400);
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
    $limit,
    $userId
);

$stats = MyPhoneRepository::getCallStats($ext, $userId);

mobileJson([
    'success' => true,
    'extension' => $ext,
    'filter' => $filter,
    'total_returned' => count($calls),
    'stats' => $stats,
    'calls' => $calls
]);
