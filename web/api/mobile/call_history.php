<?php
require_once __DIR__ . '/auth_helper.php';
mobileApiStart('GET, OPTIONS');

require_once __DIR__ . '/../../src/core/BaseRepository.php';
require_once __DIR__ . '/../../src/repositories/MyPhoneRepository.php';

$user = requireMobileAuth();
$ext = trim($user['extension'] ?? '');

if ($ext === '') {
    mobileError(t('mobile_api.no_extension_for_user'), 400);
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

mobileJson([
    'success' => true,
    'extension' => $ext,
    'filter' => $filter,
    'total_returned' => count($calls),
    'stats' => $stats,
    'calls' => $calls
]);
