<?php
/**
 * Pending Sync API Endpoint — Header'dan veya JS'ten doğrudan Asterisk'e uygulama
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/asterisk_sync.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    if (!hasModulePermission('pending_sync', 'view') && ($_SESSION['user_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Bu işlem için yetkiniz bulunmuyor.']);
        return;
    }
    $action = $_GET['action'] ?? 'count';
    if ($action === 'count') {
        $count = getPendingSyncCount();
        echo json_encode(['success' => true, 'count' => $count]);
        return;
    }
    if ($action === 'list') {
        $pending = getPendingSyncList();
        $count = getPendingSyncCount();
        echo json_encode(['success' => true, 'count' => $count, 'pending' => $pending]);
        return;
    }
    echo json_encode(['success' => false, 'error' => 'Geçersiz aksiyon.']);
    return;
}

if ($method === 'POST') {
    if (!hasModulePermission('pending_sync', 'edit') && ($_SESSION['user_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Bu işlem için yetkiniz bulunmuyor.']);
        return;
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: [];

    $csrf = $input['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCSRFToken($csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => t('pending_sync.msg_csrf_error')]);
        return;
    }

    $applied_results = applyPendingSync($_SESSION['user_id'] ?? null);
    $ok_count = count(array_filter($applied_results, fn($r) => $r['success']));
    $fail_count = count($applied_results) - $ok_count;

    if ($fail_count > 0) {
        $fail_details = [];
        foreach ($applied_results as $domain => $r) {
            if (!$r['success']) {
                $domain_label = t('pending_sync.domain_' . $domain, $domain);
                $fail_details[] = "{$domain_label}: " . ($r['error'] ?? '?');
            }
        }
        $errorMsg = sprintf(t('pending_sync.msg_apply_failed'), $fail_count) . ' ' . implode(' | ', $fail_details);
        echo json_encode([
            'success' => false,
            'ok_count' => $ok_count,
            'fail_count' => $fail_count,
            'error' => $errorMsg,
            'remaining_count' => getPendingSyncCount()
        ]);
        return;
    }

    if ($ok_count > 0) {
        $msg = sprintf(t('pending_sync.msg_applied'), $ok_count);
    } else {
        $msg = t('pending_sync.msg_nothing_pending');
    }

    echo json_encode([
        'success' => true,
        'ok_count' => $ok_count,
        'fail_count' => 0,
        'message' => $msg,
        'remaining_count' => 0
    ]);
    return;
}
