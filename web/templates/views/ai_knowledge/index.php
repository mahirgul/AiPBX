<?php
/** @var bool $plugin_enabled @var string $active_tab @var array $collections @var array $documents @var array $local_models @var array $cloud_providers @var array|null $ask_result @var string $csrf_token */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$csrf = '<input type="hidden" name="csrf_token" value="' . $h($csrf_token) . '">';
$tabs = [
    'collections' => ['label' => t('ai_knowledge.tab_collections', 'Collections'), 'icon' => 'fa-folder-tree', 'badge' => (string) count($collections)],
    'documents'   => ['label' => t('ai_knowledge.tab_documents', 'TXT Documents'), 'icon' => 'fa-file-lines', 'badge' => (string) count($documents)],
    'ask'         => ['label' => t('ai_knowledge.tab_ask', 'Test RAG'), 'icon' => 'fa-magnifying-glass'],
];
$embedOptions = [];
foreach ($local_models as $m) {
    if (($m['kind'] ?? '') === 'embedding' && in_array($m['state'] ?? '', ['ready', 'installed'], true)) {
        $embedOptions['local:' . $m['id']] = ($m['title'] ?? $m['id']) . ' (Local)';
    }
}
foreach ($cloud_providers as $cp) {
    if (in_array('embed', $cp['caps'] ?? [], true)) {
        $embedOptions['cloud:' . $cp['id']] = ($cp['title'] ?? $cp['id']) . ' (Cloud)';
    }
}
$llmOptions = [];
foreach ($local_models as $m) {
    if (($m['kind'] ?? '') === 'llm' && in_array($m['state'] ?? '', ['ready', 'installed'], true)) {
        $llmOptions['local:' . $m['id']] = ($m['title'] ?? $m['id']) . ' (Local)';
    }
}
foreach ($cloud_providers as $cp) {
    if (in_array('llm', $cp['caps'] ?? [], true)) {
        $llmOptions['cloud:' . $cp['id']] = ($cp['title'] ?? $cp['id']) . ' (Cloud)';
    }
}
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-book-open', t('ai_knowledge.title', 'Knowledge Base (TXT RAG)'), 'aiKnowledgeHelpBox'); ?>
    <?php echo uiPageHeaderActions(); ?>
    <?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('aiKnowledgeHelpBox', t('ai_knowledge.title', 'Knowledge Base (TXT RAG)')); ?>
        <?php echo $h(t('ai_knowledge.help_body', 'Upload UTF-8 .txt documents into role-scoped collections. Chunks are indexed with AI Gateway embeddings and cited in Assistant & Voice App answers.')); ?>
    <?php echo uiHelpBoxClose(); ?>
</div>

<?php if (!$plugin_enabled): ?>
    <div class="alert alert-warning u-mb-12">
        <i class="fas fa-plug-circle-xmark"></i>
        <?php echo $h(t('ai_knowledge.plugin_disabled', 'Knowledge Base plugin (ai-knowledge) is disabled.')); ?>
    </div>
<?php endif; ?>

<?php echo uiTabs($tabs, $active_tab); ?>

<div data-tab-pane="collections"<?php echo $active_tab === 'collections' ? '' : ' hidden'; ?>>
    <?php
    $ec = $edit_collection ?? null;
    $ecRoles = $ec ? (json_decode((string) ($ec['allowed_roles_json'] ?? ''), true) ?: []) : [];
    $sel = fn(bool $on) => $on ? ' selected' : '';
    ?>
    <div class="card u-mb-16">
        <h3 class="card-title u-mb-12"><?php echo $h($ec ? t('ai_knowledge.edit_collection', 'Edit Collection') : t('ai_knowledge.new_collection', 'Create Collection')); ?></h3>
        <form method="POST" action="/ai-knowledge?tab=collections">
            <?php echo $csrf; ?>
            <input type="hidden" name="action" value="save_collection">
            <input type="hidden" name="_tab" value="collections">
            <input type="hidden" name="id" value="<?php echo (int) ($ec['id'] ?? 0); ?>">
            <div class="u-d-flex u-gap-12 u-wrap">
                <div class="form-group u-flex-1">
                    <label class="form-label"><?php echo $h(t('common.name', 'Name')); ?></label>
                    <input type="text" name="name" class="form-control" required maxlength="120" placeholder="e.g. Support FAQ" value="<?php echo $h($ec['name'] ?? ''); ?>">
                </div>
                <div class="form-group u-flex-1">
                    <label class="form-label"><?php echo $h(t('ai_knowledge.embedding_model', 'Embedding Model')); ?></label>
                    <select name="embedding_binding" class="form-control">
                        <option value=""><?php echo $h(t('ai_knowledge.lexical_only', 'Keyword matching only (no embedding)')); ?></option>
                        <?php foreach ($embedOptions as $val => $lbl): ?>
                            <option value="<?php echo $h($val); ?>"<?php echo $sel(($ec['embedding_binding'] ?? '') === $val); ?>><?php echo $h($lbl); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group u-flex-1">
                    <label class="form-label"><?php echo $h(t('ai_knowledge.allowed_roles', 'Allowed Roles (empty = admin only)')); ?></label>
                    <select name="allowed_roles[]" class="form-control" multiple size="3">
                        <option value="*"<?php echo $sel(in_array('*', $ecRoles, true)); ?>><?php echo $h(t('ai_knowledge.role_everyone', 'Everyone signed in')); ?></option>
                        <option value="cc_agent"<?php echo $sel(in_array('cc_agent', $ecRoles, true)); ?>>cc_agent</option>
                        <option value="user"<?php echo $sel(in_array('user', $ecRoles, true)); ?>>user</option>
                    </select>
                </div>
            </div>
            <div class="form-group u-mb-12">
                <label class="form-label"><?php echo $h(t('common.description', 'Description')); ?></label>
                <input type="text" name="description" class="form-control" maxlength="255" value="<?php echo $h($ec['description'] ?? ''); ?>">
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas <?php echo $ec ? 'fa-save' : 'fa-plus'; ?>"></i> <?php echo $h(t('common.save', 'Save')); ?></button>
            <?php if ($ec): ?>
                <a href="/ai-knowledge?tab=collections" class="btn btn-secondary btn-sm"><?php echo $h(t('common.cancel', 'Cancel')); ?></a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <?php echo uiTableOpen([
            ['label' => t('common.name', 'Name')],
            ['label' => t('ai_knowledge.embedding_model', 'Embedding')],
            ['label' => t('ai_knowledge.assistant_language', 'PBX Assistant language')],
            ['label' => t('ai_knowledge.col_docs', 'Docs / Chunks')],
            ['label' => t('ai_models.col_actions', 'Actions'), 'class' => 'u-text-right'],
        ]); ?>
        <?php if (empty($collections)): ?>
            <?php echo uiTableEmptyRow(5, t('common.no_records', 'No records'), 'fa-folder-open'); ?>
        <?php else: ?>
            <?php foreach ($collections as $c): ?>
                <tr>
                    <td><strong><?php echo $h($c['name']); ?></strong><div class="u-fs-11 u-muted"><?php echo $h($c['description']); ?></div></td>
                    <td class="u-fs-12 u-mono"><?php echo $h($c['embedding_binding'] ?: 'lexical'); ?> <span class="badge badge-secondary u-fs-10">v<?php echo (int) $c['active_index_version']; ?></span></td>
                    <td class="u-fs-12"><a href="/ai-assistant" title="<?php echo $h(t('ai_knowledge.assistant_language_hint', 'Chosen on the PBX Assistant settings page')); ?>"><?php echo $h(UI_LANGUAGES[$c['assistant_language'] ?? ''] ?? '-'); ?></a></td>
                    <td class="u-fs-12 u-mono"><?php echo (int) $c['doc_count']; ?> / <?php echo (int) $c['chunk_count']; ?></td>
                    <td class="u-text-right u-nowrap">
                        <a href="/ai-knowledge?tab=collections&amp;edit=<?php echo (int) $c['id']; ?>" class="btn btn-secondary btn-xs"><i class="fas fa-pen"></i> <?php echo $h(t('common.edit', 'Edit')); ?></a>
                        <form method="POST" class="u-inline">
                            <?php echo $csrf; ?>
                            <input type="hidden" name="action" value="reindex_collection">
                            <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
                            <button type="submit" class="btn btn-secondary btn-xs"><i class="fas fa-sync-alt"></i> <?php echo $h(t('ai_knowledge.btn_reindex', 'Reindex')); ?></button>
                        </form>
                        <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo $h(json_encode(t('ai_knowledge.confirm_delete_collection', 'Delete collection?'))); ?>);">
                            <?php echo $csrf; ?>
                            <input type="hidden" name="action" value="delete_collection">
                            <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-xs"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php echo uiTableClose(); ?>
    </div>
</div>

<div data-tab-pane="documents"<?php echo $active_tab === 'documents' ? '' : ' hidden'; ?>>
    <?php $ed = $edit_document ?? null; ?>
    <div class="card u-mb-16">
        <h3 class="card-title u-mb-12"><?php echo $h($ed ? t('ai_knowledge.edit_document', 'Edit Document') : t('ai_knowledge.upload_txt', 'Upload or Paste TXT Document')); ?></h3>
        <form method="POST" action="/ai-knowledge?tab=documents" enctype="multipart/form-data">
            <?php echo $csrf; ?>
            <input type="hidden" name="action" value="add_document">
            <input type="hidden" name="_tab" value="documents">
            <?php if ($ed): ?>
                <input type="hidden" name="doc_id" value="<?php echo (int) $ed['id']; ?>">
                <input type="hidden" name="doc_version" value="<?php echo (int) $ed['version']; ?>">
                <input type="hidden" name="filename" value="<?php echo $h($ed['filename']); ?>">
                <input type="hidden" name="collection_id" value="<?php echo (int) $ed['collection_id']; ?>">
            <?php endif; ?>
            <div class="u-d-flex u-gap-12 u-wrap">
                <div class="form-group u-flex-1">
                    <label class="form-label"><?php echo $h(t('ai_knowledge.collection', 'Collection')); ?></label>
                    <select name="collection_id" class="form-control" required<?php echo $ed ? ' disabled' : ''; ?>>
                        <?php foreach ($collections as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>"<?php echo $ed && (int) $ed['collection_id'] === (int) $c['id'] ? ' selected' : ''; ?>><?php echo $h($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group u-flex-1">
                    <label class="form-label"><?php echo $h(t('common.title', 'Title')); ?></label>
                    <input type="text" name="title" class="form-control" maxlength="160" placeholder="Warranty Policy" value="<?php echo $h($ed['title'] ?? ''); ?>">
                </div>
                <div class="form-group u-flex-1">
                    <label class="form-label"><?php echo $h(t('ai_knowledge.txt_file', '.txt File (max 2 MB)')); ?></label>
                    <input type="file" name="txt_file" accept=".txt,text/plain" class="form-control">
                </div>
            </div>
            <div class="form-group u-mb-12">
                <label class="form-label"><?php echo $h(t('ai_knowledge.or_paste_txt', 'Or paste plain UTF-8 text')); ?></label>
                <textarea name="content_text" class="form-control" rows="<?php echo $ed ? 18 : 4; ?>" placeholder="Paste plain text..."><?php echo $h($ed['content_text'] ?? ''); ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas <?php echo $ed ? 'fa-save' : 'fa-upload'; ?>"></i> <?php echo $h($ed ? t('ai_knowledge.btn_save_doc', 'Save & Reindex') : t('ai_knowledge.btn_index_doc', 'Upload & Index')); ?></button>
            <?php if ($ed): ?>
                <a href="/ai-knowledge?tab=documents" class="btn btn-secondary btn-sm"><?php echo $h(t('common.cancel', 'Cancel')); ?></a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <?php echo uiTableOpen([
            ['label' => t('common.title', 'Title')],
            ['label' => t('ai_knowledge.collection', 'Collection')],
            ['label' => t('ai_models.col_state', 'State')],
            ['label' => t('ai_models.col_actions', 'Actions'), 'class' => 'u-text-right'],
        ]); ?>
        <?php if (empty($documents)): ?>
            <?php echo uiTableEmptyRow(4, t('common.no_records', 'No records'), 'fa-file-lines'); ?>
        <?php else: ?>
            <?php foreach ($documents as $d): ?>
                <tr>
                    <td><strong><?php echo $h($d['title']); ?></strong> <span class="u-fs-11 u-muted u-mono"><?php echo $h($d['filename']); ?> · v<?php echo (int) $d['version']; ?></span></td>
                    <td class="u-fs-12"><?php echo $h($d['collection_name']); ?></td>
                    <td><span class="badge <?php echo !empty($d['is_active']) ? 'badge-success' : 'badge-secondary'; ?>"><?php echo $h(!empty($d['is_active']) ? t('ai_knowledge.status_active', 'active') : t('ai_knowledge.status_inactive', 'inactive')); ?></span></td>
                    <td class="u-text-right u-nowrap">
                        <a href="/ai-knowledge?tab=documents&amp;edit_doc=<?php echo (int) $d['id']; ?>" class="btn btn-secondary btn-xs"><i class="fas fa-pen"></i> <?php echo $h(t('common.edit', 'Edit')); ?></a>
                        <form method="POST" class="u-inline">
                            <?php echo $csrf; ?>
                            <input type="hidden" name="action" value="toggle_document">
                            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                            <input type="hidden" name="active" value="<?php echo !empty($d['is_active']) ? '0' : '1'; ?>">
                            <button type="submit" class="btn btn-secondary btn-xs"><?php echo !empty($d['is_active']) ? $h(t('common.disable', 'Disable')) : $h(t('common.enable', 'Enable')); ?></button>
                        </form>
                        <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo $h(json_encode(t('ai_knowledge.confirm_delete_doc', 'Delete document?'))); ?>);">
                            <?php echo $csrf; ?>
                            <input type="hidden" name="action" value="delete_document">
                            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-xs"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php echo uiTableClose(); ?>
    </div>
</div>

<div data-tab-pane="ask"<?php echo $active_tab === 'ask' ? '' : ' hidden'; ?>>
    <div class="card">
        <form method="POST">
            <?php echo $csrf; ?>
            <input type="hidden" name="action" value="ask_test">
            <input type="hidden" name="_tab" value="ask">
            <div class="u-d-flex u-gap-12 u-wrap">
                <div class="form-group u-flex-1">
                    <label class="form-label"><?php echo $h(t('ai_knowledge.collection', 'Collection')); ?></label>
                    <select name="collection_id" class="form-control">
                        <option value="0"><?php echo $h(t('common.all', 'All')); ?></option>
                        <?php foreach ($collections as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>"><?php echo $h($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group u-flex-1">
                    <label class="form-label"><?php echo $h(t('ai_gateway.model', 'Language Model')); ?></label>
                    <select name="llm_binding" class="form-control" required>
                        <?php foreach ($llmOptions as $val => $lbl): ?>
                            <option value="<?php echo $h($val); ?>"><?php echo $h($lbl); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group u-mb-12">
                <label class="form-label"><?php echo $h(t('ai_knowledge.question', 'Question')); ?></label>
                <input type="text" name="question" class="form-control" required placeholder="Ask something covered by your TXT documents...">
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-paper-plane"></i> <?php echo $h(t('ai_gateway.send', 'Ask')); ?></button>
        </form>
        <?php if ($ask_result !== null): ?>
            <div class="alert <?php echo !empty($ask_result['grounded']) ? 'alert-info' : 'alert-warning'; ?> u-mt-12">
                <div><strong><?php echo $h($ask_result['answer']); ?></strong></div>
                <?php if (!empty($ask_result['sources'])): ?>
                    <ul class="u-mt-6 u-mb-0 u-fs-12">
                        <?php foreach ($ask_result['sources'] as $i => $src): ?>
                            <li>[<?php echo $i + 1; ?>] <strong><?php echo $h($src['doc_title']); ?></strong> (<?php echo $h(t('ai_knowledge.score_label', 'score:')); ?> <?php echo $h((string) $src['score']); ?>)</li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
