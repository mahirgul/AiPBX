<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../modules/destinations/DestinationRegistry.php';

// This endpoint is only used to fill the destination dropdowns on the inbound
// route/IVR/time condition edit pages — apart from _bootstrap.php's
// requireApiLogin() call there was no module-level RBAC check at all (found in
// the 2026-08-21 audit). The data itself is low-sensitivity (just name/id
// lists, no passwords), but for consistency a user without access to at least
// one of these three modules (e.g. a pure cc_agent) can no longer call it.
if (!hasModulePermission('did_routes', 'view') && !hasModulePermission('ivrs', 'view') && !hasModulePermission('time_conditions', 'view') && !hasModulePermission('ring_groups', 'view') && !hasModulePermission('boss_secretary', 'view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => t('api.err_unauthorized')]);
    exit;
}

use PBX\Destinations\DestinationRegistry;

$action = $_GET['action'] ?? 'options';
$module = trim($_GET['module'] ?? '');

try {
    if ($action === 'modules') {
        echo json_encode([
            'success' => true,
            'modules' => DestinationRegistry::getModuleList()
        ]);
        exit;
    }

    if (!empty($module)) {
        $options = DestinationRegistry::getOptionsFor($module);
        echo json_encode([
            'success' => true,
            'module' => $module,
            'options' => $options
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => t('api.err_no_module')]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
