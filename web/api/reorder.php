<?php
/**
 * Saves a drag-and-drop row order: POST entity=trunks|outbound_routes, ids[]=...
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/asterisk_sync.php';

header('Content-Type: application/json; charset=utf-8');

$entities = [
    'trunks' => ['table' => 'pbx_trunks', 'module' => 'trunks'],
    'outbound_routes' => ['table' => 'pbx_outbound_routes', 'module' => 'outbound_routes'],
];

$entity = $entities[$_POST['entity'] ?? ''] ?? null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$entity) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}
if (!verifyCSRFToken($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}
if (($_SESSION['user_role'] ?? '') !== 'admin' || !hasModulePermission($entity['module'], 'edit')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($id) => $id > 0));
if (empty($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No rows']);
    exit;
}

$db = getDB();
$db->beginTransaction();
$stmt = $db->prepare("UPDATE `{$entity['table']}` SET sort_order = ? WHERE id = ?");
foreach ($ids as $pos => $id) {
    $stmt->execute([$pos + 1, $id]);
}
$db->commit();

// The order of outbound routes is the order the dialplan tries them
// (SyncOutboundDialplan, ORDER BY sort_order): it must wait for Apply like any
// other change, or Asterisk keeps the old order without the admin noticing.
// The order of trunks is only how the list is shown; it is logged.
if ($_POST['entity'] === 'outbound_routes') {
    markPendingSync('outbound_dialplan', 'outbound_route', 'order', 'Outbound routes reordered', 'update', $_SESSION['user_id'] ?? null);
} else {
    writeAuditLog('trunks', 'trunk', 'order', 'Trunks reordered', 'update', $_SESSION['user_id'] ?? null);
}

echo json_encode(['success' => true]);
