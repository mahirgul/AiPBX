<?php

require_once __DIR__ . '/../core/BaseController.php';
require_once __DIR__ . '/../core/PluginManager.php';
require_once __DIR__ . '/../services/ai/AiKnowledgeService.php';
require_once __DIR__ . '/../services/ai/CloudAiService.php';

class AiKnowledgeController extends BaseController
{
    public static function index(): void
    {
        requireLogin();
        requireModulePermission('ai_knowledge', 'view');

        $message = '';
        $error = '';
        $ask_result = null;
        try {
            AiKnowledgeService::ensureBuiltIn();   // the PBX Assistant's guides (en, tr)
        } catch (\Throwable) {
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
                $error = t('common.invalid_csrf');
            } else {
                $action = (string) ($_POST['action'] ?? '');
                try {
                    $changed = 0;
                    if ($action === 'save_collection') {
                        requireModulePermission('ai_knowledge', 'edit');
                        $text = (string) ($_POST['content_text'] ?? '');
                        if (!empty($_FILES['txt_file']['tmp_name']) && is_uploaded_file($_FILES['txt_file']['tmp_name'])) {
                            $text = (string) file_get_contents($_FILES['txt_file']['tmp_name']);     // "fill from a TXT file"
                        }
                        $roles = is_array($_POST['allowed_roles'] ?? null) ? array_map('strval', $_POST['allowed_roles']) : ['*'];
                        $changed = AiKnowledgeService::saveWithText((int) ($_POST['id'] ?? 0), (string) ($_POST['name'] ?? ''),
                            (string) ($_POST['description'] ?? ''), $text, (string) ($_POST['embedding_binding'] ?? ''), $roles);
                        $message = t('common.saved', 'Saved.');
                    } elseif ($action === 'delete_collection') {
                        requireModulePermission('ai_knowledge', 'delete');
                        $changed = (int) ($_POST['id'] ?? 0);
                        AiKnowledgeService::deleteCollection($changed);
                        $message = t('common.deleted', 'Deleted.');
                    } elseif ($action === 'reindex_collection') {
                        requireModulePermission('ai_knowledge', 'edit');
                        AiKnowledgeService::reindexCollection((int) ($_POST['id'] ?? 0));
                        $message = t('common.saved', 'Saved.');
                    } elseif ($action === 'ask_test') {
                        requireModulePermission('ai_knowledge', 'view');
                        $colId = (int) ($_POST['collection_id'] ?? 0);
                        $ask_result = AiKnowledgeService::askWithContext(trim((string) ($_POST['question'] ?? '')), userEffectiveRole(),
                            $colId > 0 ? $colId : null, trim((string) ($_POST['llm_binding'] ?? '')));
                    }
                    if ($changed > 0) {
                        require_once __DIR__ . '/../services/ai/BuiltinAiAgentService.php';
                        BuiltinAiAgentService::markCollectionChanged($changed);
                    }
                } catch (\Throwable $e) {
                    $error = $e instanceof AiGatewayException ? $e->userMessage() : $e->getMessage();
                }
            }
        }

        $accounts = ['embed' => [], 'llm' => []];
        try {
            foreach (CloudAiService::forPage() as $info) {
                foreach (array_keys($accounts) as $cap) {
                    if (!empty($info['configured']) && in_array($cap, $info['caps'] ?? [], true)) {
                        $accounts[$cap]['cloud:' . $info['id']] = (string) $info['title'];
                    }
                }
            }
        } catch (\Throwable) {
        }
        $collections = AiKnowledgeService::collections();
        foreach ($collections as &$c) {
            $c['content_text'] = AiKnowledgeService::text((int) $c['id']);
        }
        unset($c);

        static::renderPage('ai_knowledge/index', [
            'plugin_enabled' => PluginManager::isEnabled('ai-knowledge'),
            'collections' => $collections,
            'edit_id' => (int) ($_GET['edit'] ?? 0),
            'accounts' => $accounts,
            'ask_result' => $ask_result,
            'csrf_token' => getCSRFToken(),
        ], ['title' => t('ai_knowledge.title', 'Knowledge Base'), 'message' => $message, 'error' => $error]);
    }
}
