<?php
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES);
$selected = static fn ($value, $current): string => (string) $value === (string) $current ? ' selected' : '';
$collectionNames = array_column($collections, 'name', 'id');
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-headset', t('builtin_ai_agent.title'), 'builtinAgentHelp'); ?>
    <?php echo uiPageHeaderActions(); ?>
    <button type="button" class="btn btn-secondary btn-sm" id="builtinAgentClearCache" title="<?php echo $h(t('builtin_ai_agent.clear_cache_help')); ?>"><i class="fas fa-eraser"></i> <?php echo $h(t('builtin_ai_agent.clear_cache')); ?></button>
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

<script>
document.getElementById('builtinAgentClearCache')?.addEventListener('click', function () {
    if (!confirm(<?php echo json_encode(t('builtin_ai_agent.clear_cache_confirm'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>)) return;
    const body = new URLSearchParams({ action: 'tts_cache_clear', csrf_token: <?php echo json_encode($csrf_token); ?> });
    fetch('/api/ai_models.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            const ok = res && res.success;
            const msg = ok ? res.message : ((res && res.error) || 'Error');
            if (window.showFooterToast) window.showFooterToast(msg, ok ? 'success' : 'danger'); else alert(msg);
        });
});
</script>
<?php if (!empty($ai_calls)): ?>
<?php
$appTitles = array_column(array_merge($agents, AiAppService::all(false)), 'title', 'id');
// Normal endings are green or grey; a call that failed, timed out or was not understood is red.
$resultClass = static fn (string $r, bool $notes): string => match (true) {
    in_array($r, ['expired', 'error', 'not_understood', 'timeout'], true) => 'badge-danger',
    $notes => 'badge-warning',
    in_array($r, ['matched', 'played', 'done', 'saved'], true) => 'badge-success',
    default => 'badge-secondary',
};
?>
<div class="card u-mt-12">
    <h3 class="card-title u-mb-8"><i class="fas fa-clock-rotate-left u-primary"></i> <?php echo $h(t('builtin_ai_agent.calls_title')); ?></h3>
    <p class="u-muted u-fs-12 u-mt-0"><?php echo $h(t('builtin_ai_agent.calls_help')); ?></p>
    <?php echo uiTableOpen([
        t('builtin_ai_agent.calls_time'), t('ai_apps.col_title'), t('builtin_ai_agent.calls_caller'), t('builtin_ai_agent.calls_result'),
        t('builtin_ai_agent.calls_timing'), t('builtin_ai_agent.calls_said'), t('builtin_ai_agent.calls_problems'),
    ], ['frame' => 'u-card-pad', 'attrs' => 'data-no-dt="true"']); ?>
    <?php foreach ($ai_calls as $c): ?>
        <?php $late = (int) ($c['late_frames'] ?? 0); $notes = (array) ($c['notes'] ?? []); ?>
        <tr>
            <td class="u-nowrap u-fs-12"><?php echo $h(date('d.m H:i:s', (int) ($c['started'] ?? 0))); ?></td>
            <td><?php echo $h($appTitles[$c['app'] ?? 0] ?? ('#' . (int) ($c['app'] ?? 0))); ?></td>
            <td class="u-fs-12"><?php echo $h($c['caller'] ?? '—'); ?></td>
            <td><span class="badge <?php echo $resultClass((string) ($c['result'] ?? ''), $notes !== []); ?>"><?php echo $h($c['result'] ?? '—'); ?></span></td>
            <td class="u-fs-11 u-mono">
                <?php echo $h(t('builtin_ai_agent.calls_prepared')); ?> <?php echo (int) ($c['prepare_ms'] ?? 0); ?> ms ·
                <?php echo $h(t('builtin_ai_agent.calls_played')); ?> <?php echo $h($c['played_seconds'] ?? 0); ?>/<?php echo $h($c['audio_seconds'] ?? 0); ?> s
                <?php if ($late > 0): ?><br><span class="u-danger"><?php echo $h(sprintf(t('builtin_ai_agent.calls_late'), $late, (int) ($c['max_late_ms'] ?? 0), (int) ($c['resyncs'] ?? 0))); ?></span><?php endif; ?>
            </td>
            <td class="u-fs-12"><?php echo $h(implode(' · ', array_map('strval', (array) ($c['transcripts'] ?? []))) ?: '—'); ?></td>
            <td class="u-fs-12"><?php foreach ($notes as $n): ?><div class="u-danger"><?php echo $h(($n[0] ?? 0) . ' s: ' . ($n[1] ?? '')); ?></div><?php endforeach; ?><?php echo $notes ? '' : '—'; ?></td>
        </tr>
    <?php endforeach; ?>
    <?php echo uiTableClose(); ?>
</div>
<?php endif; ?>

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
            <small class="u-hint u-grid-column-1-1"><i class="fas fa-circle-info"></i> <?php echo $h(t('builtin_ai_agent.backup_hint')); ?></small>
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
        </div>
        <div class="u-mt-12">
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
        <details class="u-mt-12" id="builtinAgentAdvanced">
            <summary class="u-fw-600"><i class="fas fa-sliders u-primary"></i> <?php echo $h(t('builtin_ai_agent.advanced')); ?></summary>
            <?php $listen = BuiltinAiAgentService::listening(is_array($edit) ? $edit : []);
            $seconds = static fn (int $n): string => sprintf(t('builtin_ai_agent.seconds'), $n); ?>
            <div class="u-d-grid u-gtc-1fr-1fr u-gap-8 u-mt-6">
                <div class="form-group">
                    <label class="form-label" for="builtin_agent_line_profile"><?php echo $h(t('builtin_ai_agent.line_profile')); ?></label>
                    <select class="form-control" id="builtin_agent_line_profile" name="line_profile">
                        <?php foreach (BuiltinAiAgentService::LINE_PROFILES as $lp): ?>
                            <option value="<?php echo $h($lp); ?>"<?php echo $selected($lp, $listen['line_profile']); ?>><?php echo $h(t('builtin_ai_agent.line_' . $lp)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="builtin_agent_retries"><?php echo $h(t('builtin_ai_agent.retries')); ?></label>
                    <select class="form-control" id="builtin_agent_retries" name="retries">
                        <?php foreach ([0 => 'retries_0', 1 => 'retries_1', 2 => 'retries_2'] as $n => $key): ?>
                            <option value="<?php echo $n; ?>"<?php echo $selected($n, $listen['retries']); ?>><?php echo $h(t('builtin_ai_agent.' . $key)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="builtin_agent_silence_seconds"><?php echo $h(t('builtin_ai_agent.silence_seconds')); ?></label>
                    <select class="form-control" id="builtin_agent_silence_seconds" name="silence_seconds">
                        <?php foreach ([3, 4, 6, 8, 10] as $n): ?>
                            <option value="<?php echo $n; ?>"<?php echo $selected($n, $listen['silence_seconds']); ?>><?php echo $h($seconds($n)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="builtin_agent_listen_seconds"><?php echo $h(t('builtin_ai_agent.listen_seconds')); ?></label>
                    <select class="form-control" id="builtin_agent_listen_seconds" name="listen_seconds">
                        <?php foreach ([5, 8, 10, 12, 15] as $n): ?>
                            <option value="<?php echo $n; ?>"<?php echo $selected($n, $listen['listen_seconds']); ?>><?php echo $h($seconds($n)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <small class="u-hint"><?php echo $h(t('builtin_ai_agent.listening_help')); ?></small>
            <p class="u-muted u-fs-12 u-mt-6"><?php echo $h(t('builtin_ai_agent.advanced_help')); ?></p>
            <div class="u-mt-6">
            <?php $allPbxTools = array_column(PbxToolRegistry::all(), 'name');
            $enabledPbx = is_array($edit['pbx_tools'] ?? null) ? $edit['pbx_tools'] : ($edit === [] ? $allPbxTools : []); ?>
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
            $newAgent = $edit === [];
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
                                <input type="checkbox" class="u-accent" name="db_queries[]" value="<?php echo $h($qKey); ?>"<?php echo ($newAgent || in_array($qKey, $enabledQueries, true)) ? ' checked' : ''; ?>>
                                <span><code class="u-mono"><?php echo $h($qKey); ?></code> — <?php echo $h($sq['description']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            </div>
        </details>
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
                    <span role="button" tabindex="0" draggable="true" class="btn btn-secondary btn-xs u-mono" data-insert-var="<?php echo $h($vChip); ?>" title="<?php echo $h(t('builtin_ai_agent.var_hint')); ?>">+ <?php echo $h(trim($vChip, '{}')); ?></span>
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
// A variable goes where the cursor was (remembered when the text loses the focus), or at the end when
// the text was never clicked into; a chip can also be dragged into the text. The text scrolls to the
// new variable, so the insertion is always seen.
function insertAtCursor(ta, token) {
    if (!ta) return;
    const had = document.activeElement === ta || ta.dataset.caret !== undefined;
    const start = document.activeElement === ta ? ta.selectionStart : (had ? Number(ta.dataset.caret) : ta.value.length);
    const end = document.activeElement === ta ? ta.selectionEnd : start;
    ta.value = ta.value.slice(0, start) + token + ta.value.slice(end);
    const pos = start + token.length;
    ta.focus();
    ta.setSelectionRange(pos, pos);
    ta.dataset.caret = String(pos);
    ta.blur(); ta.focus();                 // scrolls the cursor into view
    ta.setSelectionRange(pos, pos);
}
(function () {
    const ta = document.getElementById('builtin_agent_instructions');
    if (ta) ta.addEventListener('blur', function () { ta.dataset.caret = String(ta.selectionStart); });
    document.querySelectorAll('#agentInstrVars [data-insert-var]').forEach(function (chip) {
        const token = chip.getAttribute('data-insert-var') || '';
        chip.addEventListener('click', function () { insertAtCursor(ta, token); });
        chip.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); insertAtCursor(ta, token); }
        });
        chip.addEventListener('dragstart', function (e) {
            e.dataTransfer.setData('text/plain', token);
            e.dataTransfer.effectAllowed = 'copy';
        });
    });
})();
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
