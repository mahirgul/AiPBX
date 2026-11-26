<?php
/** @var bool $plugin_enabled @var array $collections @var int $edit_id @var array $accounts @var array|null $ask_result @var string $csrf_token */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$csrf = '<input type="hidden" name="csrf_token" value="' . $h($csrf_token) . '">';
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-book-open', t('ai_knowledge.title', 'Knowledge Base'), 'aiKnowledgeHelpBox'); ?>
    <?php echo uiPageHeaderActions(); ?>
        <button type="button" class="btn btn-primary btn-sm" data-kb-edit="">
            <i class="fas fa-plus"></i> <?php echo $h(t('ai_knowledge.new_collection', 'New knowledge base')); ?>
        </button>
    <?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('aiKnowledgeHelpBox', t('ai_knowledge.title', 'Knowledge Base')); ?>
        <?php echo $h(t('ai_knowledge.help_body')); ?>
    <?php echo uiHelpBoxClose(); ?>

    <?php if (!$plugin_enabled): ?>
        <div class="alert alert-warning u-mb-12"><i class="fas fa-plug-circle-xmark"></i> <?php echo $h(t('ai_knowledge.plugin_disabled', 'Knowledge Base plugin (ai-knowledge) is disabled.')); ?></div>
    <?php endif; ?>

    <?php echo uiTableOpen([
        ['label' => t('common.name', 'Name')],
        ['label' => t('ai_knowledge.embedding_model', 'Embedding')],
        ['label' => t('ai_knowledge.col_size', 'Text')],
        ['label' => t('ai_models.col_actions', 'Actions'), 'class' => 'u-text-right'],
    ]); ?>
    <?php if (empty($collections)): ?>
        <?php echo uiTableEmptyRow(4, t('common.no_records', 'No records'), 'fa-folder-open'); ?>
    <?php else: foreach ($collections as $c):
        $payload = ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'description' => (string) ($c['description'] ?? ''),
            'embedding_binding' => (string) ($c['embedding_binding'] ?? ''), 'content_text' => (string) $c['content_text']]; ?>
        <tr>
            <td><strong><?php echo $h($c['name']); ?></strong><div class="u-fs-11 u-muted"><?php echo $h($c['description']); ?></div></td>
            <td class="u-fs-12"><?php echo $h($c['embedding_binding'] ? ($accounts['embed'][$c['embedding_binding']] ?? $c['embedding_binding']) : t('ai_knowledge.keywords_only', 'Keyword search')); ?></td>
            <td class="u-fs-12 u-mono"><?php echo (int) mb_strlen((string) $c['content_text']); ?> / <?php echo (int) $c['chunk_count']; ?></td>
            <td class="u-text-right u-nowrap">
                <button type="button" class="btn btn-secondary btn-xs" data-kb-edit="<?php echo $h(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)); ?>"><i class="fas fa-pen"></i></button>
                <form method="POST" class="u-inline" data-confirm="<?php echo $h(t('ai_knowledge.confirm_delete_collection', 'Delete knowledge base?')); ?>">
                    <?php echo $csrf; ?>
                    <input type="hidden" name="action" value="delete_collection">
                    <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-xs"><i class="fas fa-trash"></i></button>
                </form>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    <?php echo uiTableClose(); ?>

    <h3 class="u-title u-mt-12"><i class="fas fa-magnifying-glass u-primary"></i> <?php echo $h(t('ai_knowledge.tab_ask', 'Test')); ?></h3>
    <form method="POST">
        <?php echo $csrf; ?>
        <input type="hidden" name="action" value="ask_test">
        <div class="u-d-flex u-gap-12 u-wrap">
            <div class="form-group u-flex-1">
                <select name="collection_id" class="form-control">
                    <option value="0"><?php echo $h(t('common.all', 'All')); ?></option>
                    <?php foreach ($collections as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo $h($c['name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group u-flex-1">
                <select name="llm_binding" class="form-control" required>
                    <?php foreach ($accounts['llm'] as $val => $lbl): ?><option value="<?php echo $h($val); ?>"><?php echo $h($lbl); ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group u-mb-12"><input type="text" name="question" class="form-control" required placeholder="<?php echo $h(t('ai_knowledge.question', 'Question')); ?>"></div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-paper-plane"></i> <?php echo $h(t('ai_gateway.send', 'Ask')); ?></button>
    </form>
    <?php if ($ask_result !== null): ?>
        <div class="alert <?php echo !empty($ask_result['grounded']) ? 'alert-info' : 'alert-warning'; ?> u-mt-12"><strong><?php echo $h($ask_result['answer']); ?></strong></div>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="kbCollectionModal">
    <div class="modal-card u-maxw-720">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-book-open u-primary"></i> <?php echo $h(t('ai_knowledge.title', 'Knowledge Base')); ?></h3>
            <button type="button" class="btn btn-icon btn-secondary u-btn-pad" data-kb-close><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data" autocomplete="off">
                <?php echo $csrf; ?>
                <input type="hidden" name="action" value="save_collection">
                <input type="hidden" name="id" id="kb_col_id" value="0">
                <div class="form-group">
                    <label class="form-label" for="kb_col_name"><?php echo $h(t('common.name', 'Name')); ?></label>
                    <input type="text" name="name" id="kb_col_name" class="form-control" required maxlength="60">
                </div>
                <div class="form-group">
                    <label class="form-label" for="kb_col_description"><?php echo $h(t('common.description', 'Description')); ?></label>
                    <input type="text" name="description" id="kb_col_description" class="form-control" required maxlength="200" placeholder="<?php echo $h(t('ai_knowledge.description_hint')); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="kb_col_embedding"><?php echo $h(t('ai_knowledge.embedding_model', 'Embedding account')); ?></label>
                    <select name="embedding_binding" id="kb_col_embedding" class="form-control">
                        <option value=""><?php echo $h(t('ai_knowledge.lexical_only', 'Keyword search only (no account)')); ?></option>
                        <?php foreach ($accounts['embed'] as $val => $lbl): ?><option value="<?php echo $h($val); ?>"><?php echo $h($lbl); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <div class="u-flex-between u-mb-6 u-wrap u-gap-8">
                        <label class="form-label u-mb-0" for="kb_col_text"><?php echo $h(t('ai_knowledge.text', 'Text')); ?></label>
                        <input type="file" name="txt_file" accept=".txt,text/plain" class="form-control u-w-auto" title="<?php echo $h(t('ai_knowledge.fill_from_txt')); ?>">
                    </div>
                    <textarea name="content_text" id="kb_col_text" class="form-control u-mono" rows="16"></textarea>
                </div>
                <?php echo uiModalFooter("UIHelper.closeOverlayModal('kbCollectionModal')"); ?>
            </form>
        </div>
    </div>
</div>
<div id="kbData" data-edit="<?php echo (int) $edit_id; ?>" hidden></div>
<script src="<?php echo asset('/assets/js/ai_knowledge.js'); ?>"></script>
