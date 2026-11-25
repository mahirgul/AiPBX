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
        <button type="button" class="btn btn-primary btn-sm" onclick="openKbCollectionModal(null)">
            <i class="fas fa-plus"></i> <?php echo $h(t('ai_knowledge.new_collection', 'Create Collection')); ?>
        </button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="openKbDocModal(null)">
            <i class="fas fa-upload"></i> <?php echo $h(t('ai_knowledge.upload_txt', 'Upload or Paste TXT Document')); ?>
        </button>
    <?php echo uiPageHeaderClose(); ?>
    <?php echo uiTabs($tabs, $active_tab); ?>
    <?php echo uiHelpBoxOpen('aiKnowledgeHelpBox', t('ai_knowledge.title', 'Knowledge Base (TXT RAG)')); ?>
        <?php echo $h(t('ai_knowledge.help_body', 'Upload UTF-8 .txt documents into role-scoped collections. Chunks are indexed with AI Gateway embeddings and cited in Assistant & Voice App answers.')); ?>
    <?php echo uiHelpBoxClose(); ?>

    <?php if (!$plugin_enabled): ?>
        <div class="alert alert-warning u-mb-12">
            <i class="fas fa-plug-circle-xmark"></i>
            <?php echo $h(t('ai_knowledge.plugin_disabled', 'Knowledge Base plugin (ai-knowledge) is disabled.')); ?>
        </div>
    <?php endif; ?>

    <div data-tab-pane="collections"<?php echo $active_tab === 'collections' ? '' : ' hidden'; ?>>
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
            <?php foreach ($collections as $c):
                $colPayload = [
                    'id' => (int) $c['id'],
                    'name' => (string) $c['name'],
                    'embedding_binding' => (string) ($c['embedding_binding'] ?? ''),
                    'description' => (string) ($c['description'] ?? ''),
                ];
            ?>
                <tr>
                    <td><strong><?php echo $h($c['name']); ?></strong><div class="u-fs-11 u-muted"><?php echo $h($c['description']); ?></div></td>
                    <td class="u-fs-12 u-mono"><?php echo $h($c['embedding_binding'] ?: 'lexical'); ?> <span class="badge badge-secondary u-fs-10">v<?php echo (int) $c['active_index_version']; ?></span></td>
                    <td class="u-fs-12"><a href="/pbx-assistant" title="<?php echo $h(t('ai_knowledge.assistant_language_hint', 'Chosen on the PBX Assistant settings page')); ?>"><?php echo $h(UI_LANGUAGES[$c['assistant_language'] ?? ''] ?? '-'); ?></a></td>
                    <td class="u-fs-12 u-mono"><?php echo (int) $c['doc_count']; ?> / <?php echo (int) $c['chunk_count']; ?></td>
                    <td class="u-text-right u-nowrap">
                        <button type="button" class="btn btn-secondary btn-xs" onclick='openKbCollectionModal(<?php echo $h(json_encode($colPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)); ?>)'><i class="fas fa-pen"></i> <?php echo $h(t('common.edit', 'Edit')); ?></button>
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

    <div data-tab-pane="documents"<?php echo $active_tab === 'documents' ? '' : ' hidden'; ?>>
        <?php echo uiTableOpen([
            ['label' => t('common.title', 'Title')],
            ['label' => t('ai_knowledge.collection', 'Collection')],
            ['label' => t('ai_models.col_state', 'State')],
            ['label' => t('ai_models.col_actions', 'Actions'), 'class' => 'u-text-right'],
        ]); ?>
        <?php if (empty($documents)): ?>
            <?php echo uiTableEmptyRow(4, t('common.no_records', 'No records'), 'fa-file-lines'); ?>
        <?php else: ?>
            <?php foreach ($documents as $d):
                $docPayload = [
                    'id' => (int) $d['id'],
                    'version' => (int) $d['version'],
                    'filename' => (string) $d['filename'],
                    'collection_id' => (int) $d['collection_id'],
                    'title' => (string) $d['title'],
                    'content_text' => (string) ($d['content_text'] ?? ''),
                ];
            ?>
                <tr>
                    <td><strong><?php echo $h($d['title']); ?></strong> <span class="u-fs-11 u-muted u-mono"><?php echo $h($d['filename']); ?> · v<?php echo (int) $d['version']; ?></span></td>
                    <td class="u-fs-12"><?php echo $h($d['collection_name']); ?></td>
                    <td><span class="badge <?php echo !empty($d['is_active']) ? 'badge-success' : 'badge-secondary'; ?>"><?php echo $h(!empty($d['is_active']) ? t('ai_knowledge.status_active', 'active') : t('ai_knowledge.status_inactive', 'inactive')); ?></span></td>
                    <td class="u-text-right u-nowrap">
                        <button type="button" class="btn btn-secondary btn-xs" onclick='openKbDocModal(<?php echo $h(json_encode($docPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)); ?>)'><i class="fas fa-pen"></i> <?php echo $h(t('common.edit', 'Edit')); ?></button>
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

    <div data-tab-pane="ask"<?php echo $active_tab === 'ask' ? '' : ' hidden'; ?>>
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

<div class="modal-overlay" id="kbCollectionModal">
    <div class="modal-card u-maxw-520">
        <div class="modal-header">
            <h3 class="u-title" id="kbCollectionModalTitle"><i class="fas fa-folder-tree u-primary"></i> <?php echo $h(t('ai_knowledge.new_collection', 'Create Collection')); ?></h3>
            <button type="button" class="btn btn-icon btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('kbCollectionModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" action="/ai-knowledge?tab=collections" autocomplete="off">
                <?php echo $csrf; ?>
                <input type="hidden" name="action" value="save_collection">
                <input type="hidden" name="_tab" value="collections">
                <input type="hidden" name="id" id="kb_col_id" value="0">
                <div class="form-group">
                    <label class="form-label" for="kb_col_name"><?php echo $h(t('common.name', 'Name')); ?></label>
                    <input type="text" name="name" id="kb_col_name" class="form-control" required maxlength="120" placeholder="e.g. Support FAQ">
                </div>
                <div class="form-group">
                    <label class="form-label" for="kb_col_embedding"><?php echo $h(t('ai_knowledge.embedding_model', 'Embedding Model')); ?></label>
                    <select name="embedding_binding" id="kb_col_embedding" class="form-control">
                        <option value=""><?php echo $h(t('ai_knowledge.lexical_only', 'Keyword matching only (no embedding)')); ?></option>
                        <?php foreach ($embedOptions as $val => $lbl): ?>
                            <option value="<?php echo $h($val); ?>"><?php echo $h($lbl); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="kb_col_description"><?php echo $h(t('common.description', 'Description')); ?></label>
                    <input type="text" name="description" id="kb_col_description" class="form-control" maxlength="255">
                </div>
                <?php echo uiModalFooter("UIHelper.closeOverlayModal('kbCollectionModal')"); ?>
            </form>
        </div>
    </div>
</div>

<div class="modal-overlay" id="kbDocModal">
    <div class="modal-card u-maxw-720" id="kbDocModalCard">
        <div class="modal-header">
            <h3 class="u-title" id="kbDocModalTitle"><i class="fas fa-file-lines u-primary"></i> <?php echo $h(t('ai_knowledge.upload_txt', 'Upload or Paste TXT Document')); ?></h3>
            <button type="button" class="btn btn-icon btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('kbDocModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" action="/ai-knowledge?tab=documents" enctype="multipart/form-data" autocomplete="off">
                <?php echo $csrf; ?>
                <input type="hidden" name="action" value="add_document">
                <input type="hidden" name="_tab" value="documents">
                <input type="hidden" name="doc_id" id="kb_doc_id" value="0">
                <input type="hidden" name="doc_version" id="kb_doc_version" value="0">
                <input type="hidden" name="filename" id="kb_doc_filename" value="">
                <input type="hidden" name="collection_id" id="kb_doc_collection_hidden" value="" disabled>
                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="kb_doc_collection"><?php echo $h(t('ai_knowledge.collection', 'Collection')); ?></label>
                        <select name="collection_id" id="kb_doc_collection" class="form-control" required>
                            <?php foreach ($collections as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>"><?php echo $h($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="kb_doc_title"><?php echo $h(t('common.title', 'Title')); ?></label>
                        <input type="text" name="title" id="kb_doc_title" class="form-control" maxlength="160" placeholder="Warranty Policy">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="kb_doc_file"><?php echo $h(t('ai_knowledge.txt_file', '.txt File (max 2 MB)')); ?></label>
                    <input type="file" name="txt_file" id="kb_doc_file" accept=".txt,text/plain" class="form-control">
                </div>
                <div class="form-group">
                    <div class="u-flex-between u-mb-6 u-wrap u-gap-8">
                        <label class="form-label u-mb-0" for="kb_doc_content"><?php echo $h(t('ai_knowledge.or_paste_txt', 'Or paste plain UTF-8 text')); ?></label>
                        <div class="u-flex-gap">
                            <button type="button" class="btn btn-secondary btn-xs" onclick="toggleKbDocFullscreen()"><i class="fas fa-expand"></i> <?php echo $h(t('builtin_ai_agent.btn_fullscreen')); ?></button>
                            <button type="button" class="btn btn-secondary btn-xs" onclick="copyKbDocContent()"><i class="fas fa-copy"></i> <?php echo $h(t('builtin_ai_agent.btn_copy')); ?></button>
                            <button type="button" class="btn btn-secondary btn-xs" onclick="resetKbDocTemplate()"><i class="fas fa-rotate-left"></i> <?php echo $h(t('builtin_ai_agent.btn_reset')); ?></button>
                        </div>
                    </div>
                    <textarea name="content_text" id="kb_doc_content" class="form-control u-mono" rows="12" placeholder="Paste plain text..."></textarea>
                </div>
                <?php echo uiModalFooter("UIHelper.closeOverlayModal('kbDocModal')"); ?>
            </form>
        </div>
    </div>
</div>

<script>
const KB_TITLES = {
    colNew: <?php echo json_encode(t('ai_knowledge.new_collection', 'Create Collection'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>,
    colEdit: <?php echo json_encode(t('ai_knowledge.edit_collection', 'Edit Collection'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>,
    docNew: <?php echo json_encode(t('ai_knowledge.upload_txt', 'Upload or Paste TXT Document'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>,
    docEdit: <?php echo json_encode(t('ai_knowledge.edit_document', 'Edit Document'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>
};

function openKbCollectionModal(c) {
    document.getElementById('kb_col_id').value = c ? String(c.id) : '0';
    document.getElementById('kb_col_name').value = c ? (c.name || '') : '';
    document.getElementById('kb_col_embedding').value = c ? (c.embedding_binding || '') : '';
    document.getElementById('kb_col_description').value = c ? (c.description || '') : '';
    document.getElementById('kbCollectionModalTitle').lastChild.textContent = ' ' + (c ? KB_TITLES.colEdit : KB_TITLES.colNew);
    UIHelper.openOverlayModal('kbCollectionModal');
}

function openKbDocModal(d) {
    const sel = document.getElementById('kb_doc_collection');
    const hid = document.getElementById('kb_doc_collection_hidden');
    document.getElementById('kb_doc_id').value = d ? String(d.id) : '0';
    document.getElementById('kb_doc_version').value = d ? String(d.version || 0) : '0';
    document.getElementById('kb_doc_filename').value = d ? (d.filename || '') : '';
    document.getElementById('kb_doc_title').value = d ? (d.title || '') : '';
    document.getElementById('kb_doc_file').value = '';
    document.getElementById('kb_doc_content').value = d ? (d.content_text || '') : '';
    if (d && d.collection_id) {
        sel.value = String(d.collection_id);
        sel.disabled = true;
        hid.disabled = false;
        hid.value = String(d.collection_id);
    } else {
        sel.disabled = false;
        hid.disabled = true;
        hid.value = '';
        if (sel.options.length > 0 && !sel.value) sel.selectedIndex = 0;
    }
    document.getElementById('kbDocModalTitle').lastChild.textContent = ' ' + (d ? KB_TITLES.docEdit : KB_TITLES.docNew);
    document.getElementById('kbDocModalCard')?.classList.remove('ai-modal-fs');
    UIHelper.openOverlayModal('kbDocModal');
}

function toggleKbDocFullscreen() {
    document.getElementById('kbDocModalCard')?.classList.toggle('ai-modal-fs');
}

function copyKbDocContent() {
    const ta = document.getElementById('kb_doc_content');
    if (ta && navigator.clipboard) navigator.clipboard.writeText(ta.value);
}

function resetKbDocTemplate() {
    const ta = document.getElementById('kb_doc_content');
    const titleEl = document.getElementById('kb_doc_title');
    if (!ta) return;
    const isTools = (titleEl && titleEl.value.trim() === 'agent-tools.txt');
    ta.value = isTools
        ? "# agent-tools.txt\ntone | Resmi, nazik ve kisa\ngreeting | Merhaba {caller_name}, size nasil yardimci olabilirim?\nwaiting | Bir saniye kontrol ediyorum.\ncall_transfer | destek | extension | 200 | Teknik destek talepleri\n"
        : "# Konu Basligi\n\n## Soru\nCevap metnini buraya yazin.\n";
    ta.focus();
}

<?php if (!empty($edit_collection)): ?>
openKbCollectionModal(<?php echo json_encode([
    'id' => (int) $edit_collection['id'],
    'name' => (string) $edit_collection['name'],
    'embedding_binding' => (string) ($edit_collection['embedding_binding'] ?? ''),
    'description' => (string) ($edit_collection['description'] ?? ''),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>);
<?php elseif (!empty($edit_document)): ?>
openKbDocModal(<?php echo json_encode([
    'id' => (int) $edit_document['id'],
    'version' => (int) $edit_document['version'],
    'filename' => (string) $edit_document['filename'],
    'collection_id' => (int) $edit_document['collection_id'],
    'title' => (string) $edit_document['title'],
    'content_text' => (string) ($edit_document['content_text'] ?? ''),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>);
<?php endif; ?>
</script>
