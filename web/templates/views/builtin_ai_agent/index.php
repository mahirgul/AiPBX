<?php
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES);
$selected = static fn ($value, $current): string => (string) $value === (string) $current ? ' selected' : '';
$collectionNames = array_column($collections, 'name', 'id');
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-headset', t('builtin_ai_agent.title'), 'builtinAgentHelp'); ?>
    <?php echo uiPageHeaderActions(); ?>
    <a href="/ai-agent?new=1" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> <?php echo $h(t('ai_agents.btn_new')); ?></a>
    <?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('builtinAgentHelp', t('builtin_ai_agent.title')); ?>
    <p><?php echo $h(t('builtin_ai_agent.help')); ?> <a href="/ai-knowledge"><?php echo $h(t('ai_apps.field_knowledge')); ?></a></p>
    <code><?php echo $h(t('builtin_ai_agent.tool_example')); ?></code>
    <?php echo uiHelpBoxClose(); ?>
    <?php echo uiTableOpen([
        t('ai_apps.col_title'), t('ai_apps.col_number'), t('ai_apps.field_knowledge'), t('ai_agents.col_fallback'), '',
    ], ['frame' => 'u-card-pad']); ?>
    <?php if (!$agents): ?><?php echo uiTableEmptyRow(5, t('common.no_records')); ?><?php endif; ?>
    <?php foreach ($agents as $agent): ?>
        <?php $cfg = AiAppService::config($agent); ?>
        <tr>
            <td><?php echo $h($agent['title']); ?></td>
            <td><?php echo $h($agent['internal_number'] ?? '—'); ?></td>
            <td><?php echo $h($collectionNames[$cfg['knowledge_collection'] ?? 0] ?? '—'); ?></td>
            <td><?php echo $h($targets[$agent['dest_type']][$agent['dest_id']] ?? $agent['dest_id']); ?></td>
            <td class="u-ta-right u-nowrap">
                <a href="/ai-agent?edit=<?php echo (int) $agent['id']; ?>" class="btn btn-icon btn-secondary btn-sm" title="<?php echo $h(t('common.edit')); ?>"><i class="fas fa-pen"></i></a>
                <?php echo uiDeleteForm((int) $agent['id'], 'agent_id', 'delete_agent', t('ai_agents.confirm_delete')); ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php echo uiTableClose(); ?>
</div>

<?php if ($edit !== null): ?>
<div class="card u-card-pad">
    <form method="post" action="/ai-agent">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
        <input type="hidden" name="id" value="<?php echo (int) ($edit['id'] ?? 0); ?>">
        <div class="u-grid-2">
            <div class="form-group">
                <label class="form-label" for="builtin_agent_title"><?php echo $h(t('ai_apps.field_title')); ?></label>
                <input class="form-control" id="builtin_agent_title" name="title" maxlength="100" required value="<?php echo $h($edit['title'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="builtin_agent_number"><?php echo $h(t('ai_apps.field_number')); ?></label>
                <input class="form-control" id="builtin_agent_number" name="internal_number" inputmode="numeric" pattern="[0-9]{2,6}" maxlength="6" value="<?php echo $h($edit['internal_number'] ?? ''); ?>">
            </div>
            <?php foreach (['understand' => ['llm', t('builtin_ai_agent.model')], 'stt_model' => ['stt', t('builtin_ai_agent.stt')], 'model_id' => ['tts', t('builtin_ai_agent.tts')]] as $field => [$kind, $label]): ?>
                <div class="form-group">
                    <label class="form-label" for="builtin_agent_<?php echo $h($field); ?>"><?php echo $h($label); ?></label>
                    <select class="form-control" id="builtin_agent_<?php echo $h($field); ?>" name="<?php echo $h($field); ?>" required>
                        <option value=""><?php echo $h(t('builtin_ai_agent.select')); ?></option>
                        <?php $current = (string) ($edit[$field] ?? ''); ?>
                        <?php if ($current !== '' && !isset($models[$kind][$current])): ?>
                            <option value="" selected><?php echo $h($current); ?></option>
                        <?php endif; ?>
                        <?php foreach ($models[$kind] as $ref => $name): ?>
                            <option value="<?php echo $h($ref); ?>"<?php echo $selected($ref, $current); ?>><?php echo $h($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
            <div class="form-group">
                <label class="form-label" for="builtin_agent_knowledge"><?php echo $h(t('ai_apps.field_knowledge')); ?></label>
                <select class="form-control" name="knowledge_collection" id="builtin_agent_knowledge" required>
                    <option value=""><?php echo $h(t('builtin_ai_agent.select')); ?></option>
                    <?php foreach ($collectionNames as $id => $name): ?>
                        <option value="<?php echo (int) $id; ?>"<?php echo $selected($id, $edit['knowledge_collection'] ?? ''); ?>><?php echo $h($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="builtin_agent_fallback"><?php echo $h(t('builtin_ai_agent.fallback')); ?></label>
                <select class="form-control" name="fallback" id="builtin_agent_fallback" required>
                    <option value=""><?php echo $h(t('builtin_ai_agent.select')); ?></option>
                    <?php foreach ($targets as $type => $options): ?>
                        <?php foreach ($options as $id => $name): ?>
                            <?php $value = $type . ':' . $id; ?>
                            <option value="<?php echo $h($value); ?>"<?php echo $selected($value, $edit['fallback'] ?? ''); ?>><?php echo $h($name); ?></option>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="u-check-label">
                    <input type="checkbox" class="u-accent" name="barge_in" value="1"<?php echo !empty($edit['barge_in']) || $edit === [] ? ' checked' : ''; ?>>
                    <?php echo $h(t('ai_apps.field_barge_in')); ?>
                </label>
            </div>
            <div class="form-group">
                <label class="u-check-label">
                    <input type="checkbox" class="u-accent" name="automatic_announcements" value="1" aria-describedby="builtinAgentAnnouncementsHelp"<?php echo !empty($edit['automatic_announcements']) ? ' checked' : ''; ?>>
                    <?php echo $h(t('builtin_ai_agent.automatic_announcements')); ?>
                </label>
                <small class="u-hint" id="builtinAgentAnnouncementsHelp"><?php echo $h(t('builtin_ai_agent.automatic_announcements_help')); ?></small>
            </div>
        </div>
        <div class="u-flex-gap">
            <?php echo uiSaveButton(null, 'name="save_agent" value="1"'); ?>
            <a href="/ai-agent" class="btn btn-secondary"><?php echo $h(t('common.cancel')); ?></a>
        </div>
    </form>
</div>
<?php endif; ?>
