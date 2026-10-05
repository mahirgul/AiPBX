<?php
/**
 * Call-center API dispatcher (DRY split structure)
 * The action logic lives in the files under api/cc_actions/; the URL stays:
 * /api/cc.php?action=<action>
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// read_only_admin may only call read-only/viewing actions — actions that
// affect the PBX such as hangup/originate/transfer/hold/pause/toggle_queue/
// pickup_call/save_call_note need admin/cc_agent/cc_manager.
// (This role was designed as a "viewer"; being able to see the queue
// monitoring/board pages must not mean those pages can START calls.)
$READ_ONLY_SAFE_ACTIONS = ['get_status', 'get_queues', 'get_live_calls', 'get_supervisor_agents', 'get_board_stats', 'my_cdrs', 'get_call_note', 'get_pending_note'];
$allowed_roles = ['admin', 'cc_agent', 'cc_manager'];
if (in_array($action, $READ_ONLY_SAFE_ACTIONS, true)) {
    $allowed_roles[] = 'read_only_admin';
}
requireRole($allowed_roles);

// Every state-changing (NOT read-only) action is accepted ONLY via POST.
// Found in the 2026-08-25 review: the CSRF check used to run only "if the
// request is already a POST" — and since $action is read from GET too
// (above), a request sent with GET skipped the CSRF check ENTIRELY.
// That was a classic GET-based CSRF hole: <img src="...cc.php?action=
// originate&to=..."> could start a real call from a signed-in user's
// browser without a CSRF token. Mutating actions now do NOT RUN AT ALL via
// GET (405); only a POST with the right CSRF token is accepted.
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
// A user without an extension stays empty — the actions reject on
// empty($user_ext). '101' used to be assumed: an admin without an extension
// could start calls and pause as 101 and see 101's call records.
$user_ext = preg_replace('/[^0-9]/', '', (string)($user['extension'] ?? ''));
$user_name = $user['full_name'] ?? ('Temsilci ' . $user_ext);

define('CC_DISPATCH_ACTIVE', true);
require_once __DIR__ . '/cc_actions/cc_lib.php';

// Action → file map (exact whitelist)
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
    'spy_call'              => 'queues.php',
];

if (!isset($ACTION_FILES[$action])) {
    echo json_encode(['success' => false, 'error' => 'Geçersiz işlem']);
    exit;
}

require __DIR__ . '/cc_actions/' . $ACTION_FILES[$action];
