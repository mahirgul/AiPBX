<?php
/**
 * Saves a drag-and-drop row order: POST entity=trunks|outbound_routes, ids[]=...
 */
require_once __DIR__ . '/_bootstrap.php';

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

echo json_encode(['success' => true]);
