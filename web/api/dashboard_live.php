<?php
/**
 * Live call figures for the dashboard (admin only), polled every few seconds.
 */
require_once __DIR__ . '/_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

echo json_encode(['success' => true] + DashboardRepository::getLiveStats(), JSON_UNESCAPED_UNICODE);
