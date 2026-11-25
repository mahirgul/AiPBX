<?php
/** PBX Assistant settings (admin): model binding, role permissions, history, suggestions. */
/** @var string $csrf @var array $assistant_settings @var array $assistant_roles @var array $llm_options */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$ast = $assistant_settings ?? [];
$b = $ast['binding'] ?? ['mode' => 'local_default', 'model_ref_id' => ''];
$bMode = (string) ($b['mode'] ?? 'local_default');
$bRef = (string) ($b['model_ref_id'] ?? '');
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-robot', t('ai_settings.ast_title', 'PBX Assistant Settings')); ?>
        <?php echo uiExperimentalBadge(); ?>
    <?php echo uiPageHeaderActions(); ?>
        <?php if (!empty($assistant_on)): ?>
            <a href="/assistant" class="btn btn-secondary btn-sm"><i class="fas fa-comments"></i> <?php echo $h(t('assistant.title', 'Assistant')); ?></a>
        <?php endif; ?>
    <?php echo uiPageHeaderClose(); ?>
    <div class="dpr-settings">
        <?php $onoff = [
            'label' => $h(t('ai_settings.ast_onoff', 'PBX Assistant')),
            'desc' => $h(t('ai_settings.ast_onoff_desc', 'Experimental. When it is off, the chat, its menu entry and its search shortcut are hidden.')),
            'on' => !empty($assistant_on),
            'can_edit' => true,
            'csrf' => $csrf,
            'confirm_off' => t('ai_settings.ast_onoff_confirm', 'Turn the PBX Assistant off for everyone?'),
            'fields' => ['assistant_onoff' => '1'],
        ];
        require __DIR__ . '/../../onoff_setting.php'; ?>
    </div>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
        <p class="u-muted u-fs-12 u-mt-0"><?php echo $h(t('ai_settings.ast_help', 'Choose how PBX Assistant resolves its AI model, role permissions, and welcome screen.')); ?></p>

        <div class="form-group u-mb-12">
            <label class="form-label"><?php echo $h(t('ai_settings.ast_model', 'AI Model Binding')); ?></label>
            <div class="u-d-flex u-gap-8 u-wrap u-ai-center">
                <select name="assistant_mode" id="ast-mode" class="form-control u-w-auto" onchange="document.getElementById('ast-ref').style.display=this.value==='explicit'?'':'none'">
                    <option value="local_default"<?php echo $bMode === 'local_default' ? ' selected' : ''; ?>><?php echo $h(t('ai_selector.local_default', 'Local default')); ?></option>
                    <option value="cloud_default"<?php echo $bMode === 'cloud_default' ? ' selected' : ''; ?>><?php echo $h(t('ai_selector.cloud_default', 'Cloud default')); ?></option>
                    <option value="explicit"<?php echo $bMode === 'explicit' ? ' selected' : ''; ?>><?php echo $h(t('ai_selector.explicit', 'Specific model')); ?></option>
                    <option value="off"<?php echo $bMode === 'off' ? ' selected' : ''; ?>><?php echo $h(t('ai_settings.ast_model_rules_only', 'Rules only (Off)')); ?></option>
                </select>
                <select name="assistant_model_ref" id="ast-ref" class="form-control u-w-auto" style="<?php echo $bMode === 'explicit' ? '' : 'display:none;'; ?>">
                    <option value="">--</option>
                    <?php foreach (($llm_options ?? []) as $opt): ?>
                        <option value="<?php echo $h($opt['ref']); ?>"<?php echo $bRef === $opt['ref'] ? ' selected' : ''; ?>><?php echo $h($opt['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="u-fs-12 u-muted">
                    <a href="/ai-local?tab=connections"><?php echo $h(t('ai_local.title', 'Local AI')); ?></a> ·
                    <a href="/ai-cloud?tab=models"><?php echo $h(t('ai_cloud.title', 'Cloud AI')); ?></a>
                </span>
            </div>
        </div>

        <div class="form-group u-mt-16">
            <label class="form-label"><?php echo $h(t('ai_settings.ast_roles_title', 'Role Permissions')); ?></label>
            <?php echo uiTableOpen([
                $h(t('ai_settings.ast_col_role', 'Role')),
                $h(t('ai_settings.ast_col_ask', 'Ask')),
                $h(t('ai_settings.ast_col_change', 'Apply Changes')),
                $h(t('ai_settings.ast_col_home', 'Home Page')),
                $h(t('ai_apps.field_knowledge', 'Knowledge Base')),
            ], ['search' => false]); ?>
                <?php foreach (($assistant_roles ?? []) as $r):
                    $rk = (string) ($r['role_key'] ?? '');
                    if ($rk === '') continue;
                    $isAdm = ($rk === 'admin');
                    $rp = $ast['roles'][$rk] ?? ['ask' => $isAdm, 'change' => $isAdm];
                    $hr = $ast['home_roles'][$rk] ?? ($isAdm ? 'assistant' : 'default');
                    $rkb = (int) ($ast['role_kb'][$rk] ?? 0);
                ?>
                    <tr>
                        <td><strong><?php echo $h($r['role_name'] ?? $rk); ?></strong> <span class="u-muted u-fs-11 u-mono"><?php echo $h($rk); ?></span></td>
                        <td><input type="checkbox" class="u-accent" name="role_ask[<?php echo $h($rk); ?>]" value="1" <?php echo !empty($rp['ask']) ? 'checked' : ''; ?> <?php echo $isAdm ? 'disabled' : ''; ?>></td>
                        <td><input type="checkbox" class="u-accent" name="role_change[<?php echo $h($rk); ?>]" value="1" <?php echo !empty($rp['change']) ? 'checked' : ''; ?> <?php echo $isAdm ? 'disabled' : ''; ?>></td>
                        <td>
                            <select name="home_role[<?php echo $h($rk); ?>]" class="form-control u-maxw-260">
                                <option value="default"<?php echo $hr !== 'assistant' ? ' selected' : ''; ?>><?php echo $h(t('ai_settings.ast_home_default', 'Role default')); ?></option>
                                <option value="assistant"<?php echo $hr === 'assistant' ? ' selected' : ''; ?>><?php echo $h(t('ai_settings.ast_home_assistant', 'Assistant')); ?></option>
                            </select>
                        </td>
                        <td>
                            <select name="role_kb[<?php echo $h($rk); ?>]" class="form-control u-maxw-260">
                                <option value="0"<?php echo $rkb === 0 ? ' selected' : ''; ?>><?php echo $h(t('common.all', 'All')); ?></option>
                                <option value="-1"<?php echo $rkb === -1 ? ' selected' : ''; ?>><?php echo $h(t('ai_apps.knowledge_none', 'None')); ?></option>
                                <?php foreach (($knowledge_collections ?? []) as $kc): ?>
                                    <option value="<?php echo (int) $kc['id']; ?>"<?php echo $rkb === (int) $kc['id'] ? ' selected' : ''; ?>><?php echo $h($kc['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php echo uiTableClose(); ?>
        </div>

        <?php if (!empty($knowledge_collections)): ?>
        <div class="form-group u-mt-16">
            <label class="form-label"><?php echo $h(t('ai_settings.ast_kb_title', 'Knowledge base collections')); ?></label>
            <p class="u-muted u-fs-12 u-mt-0"><?php echo $h(t('ai_settings.ast_kb_help', 'The Assistant answers "how do I" questions from the collections chosen for the user\'s language (English ones when the language has none). Documents are added on AI Settings → Knowledge Base.')); ?></p>
            <?php echo uiTableOpen([
                $h(t('common.name', 'Name')),
                $h(t('ai_knowledge.col_docs', 'Docs / Chunks')),
                $h(t('ai_settings.ast_kb_use', 'Used for language')),
            ]); ?>
                <?php foreach ($knowledge_collections as $kc): ?>
                    <tr>
                        <td><a href="/ai-knowledge?tab=collections&amp;edit=<?php echo (int) $kc['id']; ?>"><?php echo $h($kc['name']); ?></a></td>
                        <td class="u-fs-12 u-mono"><?php echo (int) $kc['doc_count']; ?> / <?php echo (int) $kc['chunk_count']; ?></td>
                        <td>
                            <select name="kb_lang[<?php echo (int) $kc['id']; ?>]" class="form-control u-maxw-260">
                                <option value=""><?php echo $h(t('ai_knowledge.assistant_none', 'Not used by the Assistant')); ?></option>
                                <?php foreach (UI_LANGUAGES as $code => $lname): ?>
                                    <option value="<?php echo $h($code); ?>"<?php echo ($kc['assistant_language'] ?? '') === $code ? ' selected' : ''; ?>><?php echo $h($lname); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php echo uiTableClose(); ?>
        </div>
        <?php endif; ?>

        <div class="u-grid-2 u-mt-16">
            <div class="form-group">
                <label class="form-label" for="ast-history-days"><?php echo $h(t('ai_settings.ast_history_days', 'History retention (days)')); ?></label>
                <input type="number" id="ast-history-days" name="history_days" class="form-control u-maxw-180" min="0" max="365" value="<?php echo (int) ($ast['history_days'] ?? 10); ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="ast-suggestions"><?php echo $h(t('ai_settings.ast_suggestions', 'Suggestions')); ?></label>
                <textarea id="ast-suggestions" name="suggestions" class="form-control" rows="4" placeholder="<?php echo $h(implode("\n", AssistantSettingsService::defaultSuggestions())); ?>"><?php echo $h(implode("\n", $ast['custom_suggestions'] ?? [])); ?></textarea>
            </div>
        </div>

        <button type="submit" name="save_assistant" value="1" class="btn btn-primary u-mt-10"><i class="fas fa-save"></i> <?php echo $h(t('common.save', 'Save')); ?></button>
    </form>
</div>
