<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/ConferenceService.php';

requireLogin();

if (!hasModulePermission('conferences', 'view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Yetkisiz erişim']);
    exit;
}

$action = $_GET['action'] ?? '';
$room = trim($_GET['room'] ?? '');

if ($action === 'members') {
    $members = ConferenceService::getLiveMembers($room);
    echo json_encode(['success' => true, 'members' => $members]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Geçersiz işlem']);
