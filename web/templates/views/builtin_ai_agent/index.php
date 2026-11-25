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
                            <?php $mTag = (str_starts_with((string) $ref, 'cloud:') && in_array(CloudAiService::typeIfKnown(substr((string) $ref, 6)), BuiltinAiAgentService::REALTIME_PROVIDER_TYPES, true)) ? ' [Cascade + Realtime]' : ' [Cascade]'; ?>
                            <option value="<?php echo $h($ref); ?>"<?php echo $selected($ref, $current); ?>><?php echo $h($name . $mTag); ?></option>
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
            <?php $rtAccounts = BuiltinAiAgentService::realtimeAccounts(); ?>
            <div class="form-group">
                <label class="form-label" for="builtin_agent_realtime_account"><?php echo $h(t('builtin_ai_agent.realtime_account')); ?></label>
                <select class="form-control" id="builtin_agent_realtime_account" name="realtime_account">
                    <option value=""><?php echo $h(t('builtin_ai_agent.realtime_off') . ' [Cascade]'); ?></option>
                    <?php foreach ($rtAccounts as $ref => $label): ?>
                        <option value="<?php echo $h($ref); ?>"<?php echo $selected($ref, $edit['realtime_account'] ?? ''); ?>><?php echo $h($label . ' [Realtime]'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="builtin_agent_realtime_voice"><?php echo $h(t('builtin_ai_agent.realtime_voice')); ?></label>
                <select class="form-control" id="builtin_agent_realtime_voice" name="realtime_voice">
                    <?php foreach (BuiltinAiAgentService::REALTIME_VOICES as $rv): ?>
                        <option value="<?php echo $h($rv); ?>"<?php echo $selected($rv, $edit['realtime_voice'] ?? 'alloy'); ?>><?php echo $h(ucfirst($rv)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php $enabledPbx = is_array($edit['pbx_tools'] ?? null) ? $edit['pbx_tools'] : []; ?>
            <div class="form-group">
                <label class="form-label"><?php echo $h(t('builtin_ai_agent.pbx_tools')); ?></label>
                <div class="u-d-grid u-gtc-1fr-1fr u-gap-8">
                    <?php foreach (PbxToolRegistry::all() as $pt): ?>
                        <label class="u-check-label">
                            <input type="checkbox" class="u-accent" name="pbx_tools[]" value="<?php echo $h($pt['name']); ?>"<?php echo in_array($pt['name'], $enabledPbx, true) ? ' checked' : ''; ?>>
                            <span><?php echo $h($pt['name']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
            $enabledQueries = is_array($edit['db_queries'] ?? null) ? array_map('strval', $edit['db_queries']) : [];
            if ($enabledQueries === [] && !empty($edit['db_lookups']) && is_array($edit['db_lookups'])) {
                $enabledQueries = array_values(array_filter(array_map(
                    static fn ($lu): string => is_array($lu) ? (string) ($lu['id'] ?? '') : '',
                    $edit['db_lookups']
                )));
            }
            ?>
            <div class="form-group u-grid-column-1-1">
                <label class="form-label"><?php echo $h(t('builtin_ai_agent.db_queries')); ?></label>
                <?php if (empty($saved_queries)): ?>
                    <div class="u-fs-12 text-muted"><a href="/external-dbs?tab=queries"><?php echo $h(t('external_dbs.tab_queries')); ?></a></div>
                <?php else: ?>
                    <div class="u-d-grid u-gtc-1fr-1fr u-gap-8">
                        <?php foreach ($saved_queries as $sq): $qKey = (string) $sq['query_key']; ?>
                            <label class="u-check-label">
                                <input type="checkbox" class="u-accent" name="db_queries[]" value="<?php echo $h($qKey); ?>"<?php echo in_array($qKey, $enabledQueries, true) ? ' checked' : ''; ?>>
                                <span><code class="u-mono"><?php echo $h($qKey); ?></code> — <?php echo $h($sq['description']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php $instrValue = array_key_exists('instructions', $edit) ? (string) $edit['instructions'] : ($default_instructions ?? ''); ?>
        <div class="form-group u-mt-12 ai-editor-box" id="builtinAgentInstrBox">
            <div class="u-flex-between u-mb-6 u-wrap u-gap-8">
                <label class="form-label u-mb-0" for="builtin_agent_instructions"><?php echo $h(t('builtin_ai_agent.instructions')); ?></label>
                <div class="u-flex-gap">
                    <button type="button" class="btn btn-secondary btn-xs" onclick="toggleAgentInstrFullscreen()"><i class="fas fa-expand"></i> <?php echo $h(t('builtin_ai_agent.btn_fullscreen')); ?></button>
                    <button type="button" class="btn btn-secondary btn-xs" onclick="copyAgentInstructions()"><i class="fas fa-copy"></i> <?php echo $h(t('builtin_ai_agent.btn_copy')); ?></button>
                    <button type="button" class="btn btn-secondary btn-xs" onclick="resetAgentInstructions()"><i class="fas fa-rotate-left"></i> <?php echo $h(t('builtin_ai_agent.btn_reset')); ?></button>
                </div>
            </div>
            <div class="u-d-flex u-wrap u-gap-6 u-mb-6" id="agentInstrVars">
                <?php foreach (BuiltinAiAgentService::INSTRUCTION_VARS as $vChip): ?>
                    <button type="button" class="btn btn-secondary btn-xs u-mono" data-insert-var="<?php echo $h($vChip); ?>">+ <?php echo $h(trim($vChip, '{}')); ?></button>
                <?php endforeach; ?>
            </div>
            <small class="u-hint u-mb-6"><?php echo $h(t('builtin_ai_agent.instructions_help')); ?></small>
            <textarea class="form-control u-mono" id="builtin_agent_instructions" name="instructions" rows="12" maxlength="4000"><?php echo $h($instrValue); ?></textarea>
        </div>
        <div class="u-flex-gap">
            <?php echo uiSaveButton(null, 'name="save_agent" value="1"'); ?>
            <a href="/ai-agent" class="btn btn-secondary"><?php echo $h(t('common.cancel')); ?></a>
        </div>
    </form>
</div>
<script>
const AGENT_DEFAULT_INSTRUCTIONS = <?php echo json_encode((string) ($default_instructions ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
function insertAtCursor(ta, token) {
    if (!ta) return;
    const start = ta.selectionStart ?? ta.value.length;
    const end = ta.selectionEnd ?? ta.value.length;
    ta.value = ta.value.slice(0, start) + token + ta.value.slice(end);
    ta.focus();
    ta.selectionStart = ta.selectionEnd = start + token.length;
}
document.querySelectorAll('#agentInstrVars [data-insert-var]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        insertAtCursor(document.getElementById('builtin_agent_instructions'), btn.getAttribute('data-insert-var') || '');
    });
});
function toggleAgentInstrFullscreen() {
    const box = document.getElementById('builtinAgentInstrBox');
    if (box) box.classList.toggle('ai-editor-fs');
}
function copyAgentInstructions() {
    const ta = document.getElementById('builtin_agent_instructions');
    if (ta && navigator.clipboard) navigator.clipboard.writeText(ta.value);
}
function resetAgentInstructions() {
    const ta = document.getElementById('builtin_agent_instructions');
    if (ta) {
        ta.value = AGENT_DEFAULT_INSTRUCTIONS;
        ta.focus();
    }
}
</script>
<?php endif; ?>
