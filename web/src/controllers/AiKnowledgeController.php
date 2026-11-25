<?php

require_once __DIR__ . '/../core/BaseController.php';
require_once __DIR__ . '/../core/PluginManager.php';
require_once __DIR__ . '/../services/ai/AiKnowledgeService.php';
require_once __DIR__ . '/../services/ai/CloudAiService.php';
require_once __DIR__ . '/../services/LocalAiService.php';

class AiKnowledgeController extends BaseController
{
    public static function index(): void
    {
        requireLogin();
        requireModulePermission('ai_knowledge', 'view');

        $message = '';
        $error = '';
        $active_tab = (string) ($_GET['tab'] ?? $_POST['_tab'] ?? 'collections');
        if (!in_array($active_tab, ['collections', 'documents', 'ask'], true)) {
            $active_tab = 'collections';
        }
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
                    $changedToolsCollection = 0;
                    if ($action === 'save_collection') {
                        requireModulePermission('ai_knowledge', 'edit');
                        $active_tab = 'collections';
                        $roles = is_array($_POST['allowed_roles'] ?? null) ? array_map('strval', $_POST['allowed_roles']) : ['*'];
                        AiKnowledgeService::saveCollection(
                            (int) ($_POST['id'] ?? 0),
                            (string) ($_POST['name'] ?? ''),
                            (string) ($_POST['description'] ?? ''),
                            $roles,
                            (string) ($_POST['embedding_binding'] ?? '')
                        );
                        $message = t('common.saved', 'Saved.');
                    } elseif ($action === 'delete_collection') {
                        requireModulePermission('ai_knowledge', 'delete');
                        $active_tab = 'collections';
                        $changedToolsCollection = (int) ($_POST['id'] ?? 0);
                        AiKnowledgeService::deleteCollection($changedToolsCollection);
                        $message = t('common.deleted', 'Deleted.');
                    } elseif ($action === 'reindex_collection') {
                        requireModulePermission('ai_knowledge', 'edit');
                        $active_tab = 'collections';
                        $cnt = AiKnowledgeService::reindexCollection((int) ($_POST['id'] ?? 0));
                        $message = sprintf(t('ai_knowledge.msg_reindexed', 'Re-indexed %d document(s).'), $cnt);
                    } elseif ($action === 'add_document') {
                        requireModulePermission('ai_knowledge', 'edit');
                        $active_tab = 'documents';
                        $colId = (int) ($_POST['collection_id'] ?? 0);
                        $title = (string) ($_POST['title'] ?? '');
                        if (!empty($_FILES['txt_file']['tmp_name']) && is_uploaded_file($_FILES['txt_file']['tmp_name'])) {
                            $filename = (string) ($_FILES['txt_file']['name'] ?? 'upload.txt');
                            $content = (string) file_get_contents($_FILES['txt_file']['tmp_name']);
                        } else {
                            $filename = trim((string) ($_POST['filename'] ?? 'note.txt')) ?: 'note.txt';
                            $content = (string) ($_POST['content_text'] ?? '');
                        }
                        $docId = (int) ($_POST['doc_id'] ?? 0);
                        $expectedVersion = $docId > 0 ? (int) ($_POST['doc_version'] ?? 0) : null;
                        require_once __DIR__ . '/../services/ai/BuiltinAiAgentService.php';
                        $previous = $docId > 0 ? AiKnowledgeService::document($docId) : null;
                        if (basename($filename) === BuiltinAiAgentService::TOOL_FILE) {
                            static::requireRole('admin');
                            BuiltinAiAgentService::parseTools($content);
                            $changedToolsCollection = $colId;
                        } elseif (($previous['filename'] ?? '') === BuiltinAiAgentService::TOOL_FILE) {
                            static::requireRole('admin');
                            $changedToolsCollection = (int) $previous['collection_id'];
                        }
                        AiKnowledgeService::addDocument($colId, $title, $filename, $content, $docId, $expectedVersion);
                        $message = t('ai_knowledge.msg_doc_indexed', 'TXT document uploaded and indexed.');
                    } elseif ($action === 'toggle_document') {
                        requireModulePermission('ai_knowledge', 'edit');
                        $active_tab = 'documents';
                        $docId = (int) ($_POST['id'] ?? 0);
                        $changedToolsCollection = self::toolDocumentCollection($docId);
                        AiKnowledgeService::setDocumentActive($docId, !empty($_POST['active']));
                        $message = t('common.saved', 'Saved.');
                    } elseif ($action === 'delete_document') {
                        requireModulePermission('ai_knowledge', 'delete');
                        $active_tab = 'documents';
                        $docId = (int) ($_POST['id'] ?? 0);
                        $changedToolsCollection = self::toolDocumentCollection($docId);
                        AiKnowledgeService::deleteDocument($docId);
                        $message = t('common.deleted', 'Deleted.');
                    } elseif ($action === 'ask_test') {
                        $active_tab = 'ask';
                        $q = trim((string) ($_POST['question'] ?? ''));
                        $colId = (int) ($_POST['collection_id'] ?? 0);
                        $llm = trim((string) ($_POST['llm_binding'] ?? ''));
                        $role = userEffectiveRole();
                        $ask_result = AiKnowledgeService::askWithContext($q, $role, $colId > 0 ? $colId : null, $llm);
                    }
                    if ($changedToolsCollection > 0) {
                        require_once __DIR__ . '/../services/ai/BuiltinAiAgentService.php';
                        BuiltinAiAgentService::markCollectionChanged($changedToolsCollection);
                    }
                } catch (\Throwable $e) {
                    $error = $e instanceof AiGatewayException ? $e->userMessage() : $e->getMessage();
                }
            }
        }

        $localModels = [];
        try {
            $localModels = LocalAiService::models();
        } catch (\Throwable) {
        }
        $cloudProviders = [];
        try {
            foreach (CloudAiService::forPage() as $info) {
                if (!empty($info['configured'])) {
                    $cloudProviders[] = ['id' => $info['id'], 'title' => $info['title'], 'caps' => $info['caps']];
                }
            }
        } catch (\Throwable) {
        }

        static::renderPage('ai_knowledge/index', [
            'plugin_enabled' => PluginManager::isEnabled('ai-knowledge'),
            'active_tab' => $active_tab,
            'collections' => AiKnowledgeService::collections(),
            'edit_collection' => isset($_GET['edit']) ? AiKnowledgeService::collection((int) $_GET['edit']) : null,
            'edit_document' => isset($_GET['edit_doc']) ? AiKnowledgeService::document((int) $_GET['edit_doc']) : null,
            'documents' => AiKnowledgeService::documents(),
            'local_models' => $localModels,
            'cloud_providers' => $cloudProviders,
            'ask_result' => $ask_result,
            'csrf_token' => getCSRFToken(),
        ], ['title' => t('ai_knowledge.title', 'Knowledge Base (TXT RAG)'), 'message' => $message, 'error' => $error]);
    }

    private static function toolDocumentCollection(int $docId): int
    {
        require_once __DIR__ . '/../services/ai/BuiltinAiAgentService.php';
        $doc = AiKnowledgeService::document($docId);
        if (($doc['filename'] ?? '') === BuiltinAiAgentService::TOOL_FILE) {
            static::requireRole('admin');
            return (int) $doc['collection_id'];
        }
        return 0;
    }
}
