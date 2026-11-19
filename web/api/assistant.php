<?php
/**
 * AI → Assistant API:
 *   GET  action=changes     — last 30 applied changes (scoped to own changes for non-admins)
 *   GET  action=chats       — current user's chat list
 *   GET  action=chat        — one chat with its messages (owner only)
 *   POST action=ask         — unified classifier pipeline (rules -> Echo -> nav -> model -> unknown)
 *   POST action=delete_chat — delete one chat (owner only)
 *   POST action=plan        — resolve a plain-language request into a previewable plan
 *   POST action=apply       — apply ONE confirmed plan (server-rebuilt & scope-checked)
 *   POST action=rollback    — undo one applied change by id
 *   POST action=echo        — one Echo question (read-only, user-scoped for non-admins)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/ai/AssistantPlanner.php';
require_once __DIR__ . '/../src/services/ai/AssistantPlanService.php';
require_once __DIR__ . '/../src/services/ai/AssistantModel.php';
require_once __DIR__ . '/../src/services/ai/EchoReporter.php';
require_once __DIR__ . '/../src/services/ai/AssistantChatService.php';

header('Content-Type: application/json; charset=utf-8');

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = userEffectiveRole();
$isAdmin = $role === 'admin';

if ($userId <= 0 || !AssistantChatService::canAsk($role)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
if (!AssistantSettingsService::isPluginEnabled()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => t('ai_settings.ast_turned_off')]);
    exit;
}

$ownExt = '';
try {
    $st = getDB()->prepare('SELECT extension FROM sys_users WHERE id = ? LIMIT 1');
    $st->execute([$userId]);
    $ownExt = trim((string) ($st->fetchColumn() ?: ''));
} catch (\Throwable $e) {
    $ownExt = '';
}
$currentUser = [
    'id' => $userId,
    'role' => $role,
    'extension' => $ownExt,
];

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'changes') {
        $changes = AssistantPlanService::recent(30);
        if (!$isAdmin) {
            $changes = array_values(array_filter(
                $changes,
                static fn(array $row): bool => (int) ($row['user_id'] ?? 0) === $userId
            ));
        }
        echo json_encode(['success' => true, 'changes' => $changes], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'chats') {
        echo json_encode(['success' => true, 'chats' => AssistantChatService::listChats($userId)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'chat') {
        $chatId = (int) ($_GET['id'] ?? 0);
        $chat = AssistantChatService::getChat($chatId, $userId);
        if ($chat === null) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Not found']);
            exit;
        }
        echo json_encode(['success' => true, 'chat' => $chat], JSON_UNESCAPED_UNICODE);
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

if (!in_array($action, ['echo', 'plan', 'ask', 'delete_chat'], true)) {
    // Mutating actions to the PBX are audited here too (without CSRF secrets). An attempt by
    // someone who may not change anything is kept as "denied", never as a pending change.
    $auditPost = $_POST;
    unset($auditPost['csrf_token']);
    try {
        getDB()->prepare('INSERT INTO pbx_ai_changes (ts, user_id, request, action, arguments, summary, source, status)
                          VALUES (NOW(), ?, ?, ?, ?, ?, "api", ?)')
            ->execute([
                $userId,
                'api:' . $action,
                $action,
                (string) json_encode($auditPost, JSON_UNESCAPED_UNICODE),
                'API call, ' . ($_SERVER['REMOTE_ADDR'] ?? ''),
                AssistantChatService::canChange($role) ? 'pending' : 'denied',
            ]);
    } catch (\Throwable $e) {
        // audit failure never blocks the answer itself
    }
}

try {
    if ($action === 'ask') {
        $question = trim((string) ($_POST['question'] ?? ''));
        $chatId = (int) ($_POST['chat_id'] ?? 0);
        $res = AssistantChatService::ask($question, $chatId, $currentUser);
        echo json_encode(['success' => true] + $res, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }
    if ($action === 'delete_chat') {
        $chatId = (int) ($_POST['id'] ?? 0);
        $deleted = AssistantChatService::deleteChat($chatId, $userId);
        echo json_encode(['success' => true, 'deleted' => $deleted], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'plan') {
        if (!AssistantChatService::canChange($role)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => t('assistant.err_change_not_allowed')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $request = trim((string) ($_POST['request'] ?? ''));
        $context = AssistantPlanner::context(AssistantPlanContext::extensions(), AssistantPlanContext::ringGroups(), AssistantPlanContext::queues());
        $plan = AssistantPlanner::plan($request, $context, 'AssistantModel::ask');
        if (!empty($plan['ok'])) {
            $scopeError = AssistantChatService::validateScope($plan, $currentUser);
            if ($scopeError !== null) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => $scopeError], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        echo json_encode(['success' => true, 'plan' => $plan, 'request' => mb_substr($request, 0, 300)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'apply') {
        if (!AssistantChatService::canChange($role)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => t('assistant.err_change_not_allowed')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $request = trim((string) ($_POST['request'] ?? ''));
        $actionName = trim((string) ($_POST['change_action'] ?? ''));
        $argsJson = (string) ($_POST['arguments'] ?? '[]');
        $arguments = json_decode($argsJson, true);
        if (!is_array($arguments)) {
            throw new RuntimeException(t('ai_assistant.err_bad_value', ['field' => 'arguments']));
        }
        $context = AssistantPlanner::context(AssistantPlanContext::extensions(), AssistantPlanContext::ringGroups(), AssistantPlanContext::queues());
        $replan = AssistantPlanner::build($actionName, $arguments, $context, 'confirmed');
        if (empty($replan['ok'])) {
            throw new RuntimeException($replan['message_key'] ?? 'ai_assistant.err_unknown_action');
        }
        $scopeError = AssistantChatService::validateScope($replan, $currentUser);
        if ($scopeError !== null) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => $scopeError], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $changesId = AssistantPlanService::apply($replan, $request);
        $row = AssistantPlanService::get($changesId);

        $msgId = (int) ($_POST['message_id'] ?? 0);
        if ($msgId > 0) {
            try {
                getDB()->prepare(
                    'UPDATE assistant_messages m
                     INNER JOIN assistant_chats c ON c.id = m.chat_id
                     SET m.plan_id = ?
                     WHERE m.id = ? AND c.user_id = ?'
                )->execute([$changesId, $msgId, $userId]);
            } catch (\Throwable $e) {
                // non-fatal
            }
        }

        echo json_encode(['success' => true, 'id' => $changesId, 'summary' => $row['summary'] ?? '',
                          'undoable' => ($row['before_json'] ?? null) !== null], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'rollback') {
        if (!AssistantChatService::canChange($role)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => t('assistant.err_change_not_allowed')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $id = (int) ($_POST['id'] ?? 0);
        if (!$isAdmin) {
            $row = AssistantPlanService::get($id);
            if ($row === null || (int) ($row['user_id'] ?? 0) !== $userId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => t('assistant.err_own_extension_only')], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        $was = AssistantPlanService::rollback($id);
        echo json_encode(['success' => true, 'id' => $id, 'undone' => $was], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'echo') {
        $question = trim((string) ($_POST['question'] ?? ''));
        $answer = EchoReporter::ask($question, ['is_admin' => $isAdmin, 'extension' => $ownExt]);
        echo json_encode(['success' => true] + $answer, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
