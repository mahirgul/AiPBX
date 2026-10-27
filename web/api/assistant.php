<?php
/**
 * AI → Assistant API (admin only):
 *   POST action=plan      — resolve a plain-language request into a previewable
 *                           plan (NEVER applies anything)
 *   POST action=apply     — apply ONE plan that the page shows correctly
 *                           (carries action+arguments as the page received
 *                           them; ANY mismatch is refused: the model cannot
 *                           smuggle a different change between preview and
 *                           apply)
 *   GET  action=changes   — last 30 applied changes (audit trail)
 *   POST action=rollback  — undo one applied change by id
 *   POST action=echo      — one Echo question (read-only)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/ai/AssistantPlanner.php';
require_once __DIR__ . '/../src/services/ai/AssistantPlanService.php';
require_once __DIR__ . '/../src/services/ai/AssistantModel.php';
require_once __DIR__ . '/../src/services/ai/EchoReporter.php';

header('Content-Type: application/json; charset=utf-8');
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    // The JSON error, like every other admin api: the page's fetch() reads a
    // body, and a bare 403 looks like a broken endpoint in its console.
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'changes') {
        echo json_encode(['success' => true, 'changes' => AssistantPlanService::recent(30)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => t('common.invalid_csrf')]);
    exit;
}
if ($action !== 'echo') {
    // Changes to the PBX are audited here too: who clicked, when.
    try {
        getDB()->prepare('INSERT INTO pbx_ai_changes (ts, user_id, request, action, arguments, summary, source, status)
                          VALUES (NOW(), ?, ?, ?, ?, ?, "api", "pending")')
            ->execute([
                (int) ($_SESSION['user_id'] ?? 0),
                'api:' . $action,
                $action,
                (string) json_encode($_POST, JSON_UNESCAPED_UNICODE),
                'API call, ' . ($_SERVER['REMOTE_ADDR'] ?? ''),
            ]);
    } catch (\Throwable $e) {
        // audit failure never blocks the answer itself; plan/echo are safe ops
    }
}

try {
    if ($action === 'plan') {
        $request = trim((string) ($_POST['request'] ?? ''));
        $context = AssistantPlanner::context(AssistantPlanContext::extensions(), AssistantPlanContext::ringGroups(), AssistantPlanContext::queues());
        $plan = AssistantPlanner::plan($request, $context, 'AssistantModel::ask');
        echo json_encode(['success' => true, 'plan' => $plan, 'request' => mb_substr($request, 0, 300)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'apply') {
        $request = trim((string) ($_POST['request'] ?? ''));
        $actionName = trim((string) ($_POST['change_action'] ?? ''));
        $argsJson = (string) ($_POST['arguments'] ?? '[]');
        $arguments = json_decode($argsJson, true);
        if (!is_array($arguments)) {
            throw new RuntimeException(t('ai_assistant.err_bad_value', ['field' => 'arguments']));
        }
        // The plan is REBUILT server-side from action + arguments and the
        // rebuilt (validated) arguments are what gets applied: what the
        // browser posts is treated as a suggestion, the server's own
        // validation is what lands on the PBX.
        $context = AssistantPlanner::context(AssistantPlanContext::extensions(), AssistantPlanContext::ringGroups(), AssistantPlanContext::queues());
        $replan = AssistantPlanner::build($actionName, $arguments, $context, 'confirmed');
        if (empty($replan['ok'])) {
            throw new RuntimeException($replan['message_key'] ?? 'ai_assistant.err_unknown_action');
        }
        $changesId = AssistantPlanService::apply($replan, $request);
        $row = AssistantPlanService::get($changesId);
        echo json_encode(['success' => true, 'id' => $changesId, 'summary' => $row['summary'] ?? '',
                          'undoable' => ($row['before_json'] ?? null) !== null], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'rollback') {
        $id = (int) ($_POST['id'] ?? 0);
        $was = AssistantPlanService::rollback($id);
        echo json_encode(['success' => true, 'id' => $id, 'undone' => $was], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'echo') {
        $question = trim((string) ($_POST['question'] ?? ''));
        $answer = EchoReporter::ask($question);
        echo json_encode(['success' => true] + $answer, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
