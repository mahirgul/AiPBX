<?php
/**
 * Çağrı Merkezi API Dispatcher (DRY bölünmüş yapı)
 * Aksiyon mantığı api/cc_actions/ altındaki dosyalarda tutulur; URL değişmez:
 * /api/cc.php?action=<aksiyon>
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// read_only_admin sadece salt-okunur/görüntüleme aksiyonlarını çağırabilir —
// hangup/originate/transfer/hold/pause/toggle_queue/pickup_call/save_call_note
// gibi PBX'i etkileyen aksiyonlar için admin/cc_agent/cc_manager gerekli.
// (Bu rol "İzleyici" olarak tasarlandı; sadece Kuyruk İzleme/Pano sayfalarını
// görebilmesi, bu sayfaların çağrı BAŞLATABİLMESİ anlamına gelmemeli.)
$READ_ONLY_SAFE_ACTIONS = ['get_status', 'get_queues', 'get_live_calls', 'get_supervisor_agents', 'get_board_stats', 'my_cdrs', 'get_call_note', 'get_pending_note'];
$allowed_roles = ['admin', 'cc_agent', 'cc_manager'];
if (in_array($action, $READ_ONLY_SAFE_ACTIONS, true)) {
    $allowed_roles[] = 'read_only_admin';
}
requireRole($allowed_roles);

// Durum değiştiren (salt-okunur OLMAYAN) her aksiyon SADECE POST ile kabul
// edilir. 2026-08-25 incelemesinde bulundu: önceden CSRF kontrolü sadece
// "istek zaten POST'sa" çalışıyordu — $action GET'ten de okunduğu için
// (yukarıda) bir istek GET ile gönderilirse CSRF kontrolü HİÇ ÇALIŞMIYORDU.
// Bu klasik bir GET-tabanlı CSRF açığıydı: <img src="...cc.php?action=
// originate&to=...">, giriş yapmış bir kullanıcının tarayıcısından CSRF
// token'sız gerçek bir arama başlatabiliyordu. Artık mutasyon aksiyonları
// GET ile hiç ÇALIŞMIYOR (405), sadece doğru CSRF token'lı POST kabul edilir.
if (!in_array($action, $READ_ONLY_SAFE_ACTIONS, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Bu işlem sadece POST isteğiyle yapılabilir']);
        exit;
    }
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCSRFToken($csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Geçersiz CSRF doğrulama kodu']);
        exit;
    }
}

$db = getDB();
$user = getCurrentUser();
$user_ext = preg_replace('/[^0-9]/', '', $user['extension'] ?? '101');
$user_name = $user['full_name'] ?? ('Temsilci ' . $user_ext);

define('CC_DISPATCH_ACTIVE', true);
require_once __DIR__ . '/cc_actions/cc_lib.php';

// Aksiyon → dosya eşlemesi (exact whitelist)
$ACTION_FILES = [
    'originate'             => 'calls.php',
    'hangup'                => 'calls.php',
    'transfer'              => 'calls.php',
    'hold'                  => 'calls.php',
    'pickup_call'           => 'calls.php',
    'login'                 => 'agent.php',
    'logout'                => 'agent.php',
    'pause'                 => 'agent.php',
    'unpause'               => 'agent.php',
    'get_status'            => 'agent.php',
    'auto_login'            => 'agent.php',
    'get_queues'            => 'queues.php',
    'toggle_queue'          => 'queues.php',
    'get_supervisor_agents' => 'queues.php',
    'get_live_calls'        => 'queues.php',
    'get_board_stats'       => 'board.php',
    'my_cdrs'               => 'cdrs.php',
    'get_call_note'         => 'notes.php',
    'get_pending_note'      => 'notes.php',
    'save_call_note'        => 'notes.php',
];

if (!isset($ACTION_FILES[$action])) {
    echo json_encode(['success' => false, 'error' => 'Geçersiz işlem']);
    exit;
}

require __DIR__ . '/cc_actions/' . $ACTION_FILES[$action];
