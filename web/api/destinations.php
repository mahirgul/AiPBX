<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../modules/destinations/DestinationRegistry.php';

// Bu endpoint yalnızca Gelen Rota/IVR/Zaman Koşulu düzenleme sayfalarındaki
// hedef seçim dropdown'larını doldurmak için kullanılıyor — _bootstrap.php'nin
// requireApiLogin() çağrısı dışında hiçbir modül-seviyesi RBAC kontrolü yoktu
// (2026-08-21 denetiminde bulundu). Veri kendi başına düşük hassasiyette (sadece
// isim/id listeleri, şifre yok) ama tutarlılık için, bu üç modülden en az birine
// erişimi olmayan bir kullanıcı (ör. sadece cc_agent) artık bu endpoint'i
// çağıramıyor.
if (!hasModulePermission('did_routes', 'view') && !hasModulePermission('ivrs', 'view') && !hasModulePermission('time_conditions', 'view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Yetkisiz erişim']);
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

    echo json_encode(['success' => false, 'error' => 'Modül anahtarı belirtilmedi!']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
