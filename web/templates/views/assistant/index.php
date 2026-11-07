<?php
/** @var array $changes  recent applied changes (AssistantPlanService::recent) */
/** @var string $echo_askable what Echo can answer */
/** @var string $csrf */
$texts = [];
foreach ([
    'title', 'intro', 'placeholder', 'ask', 'working', 'preview_title', 'preview_apply', 'preview_discard',
    'applied', 'undo', 'undo_done', 'echo_title', 'echo_placeholder', 'echo_ask', 'echo_askable',
    'changes_title', 'no_changes', 'col_when', 'col_request', 'col_change', 'col_status', 'col_source',
    'status_applied', 'status_rolled_back', 'err_generic', 'risk_low', 'risk_medium', 'narration',
] as $k) {
    $texts[$k] = t('assistant.' . $k);
}
?>
<style>
  .assistant-chat { max-width: 860px; }
  .assistant-preview { border-left: 3px solid var(--primary); background: rgba(0, 0, 0, 0.03); border-radius: 0 8px 8px 0; padding: 12px 16px; margin: 10px 0; }
  .assistant-log td { vertical-align: top; }
</style>
<div class="card">
    <?php echo uiPageHeader('fa-wand-magic-sparkles', t('assistant.title'), 'assistantHelpBox'); ?>
<?php echo uiHelpBoxOpen('assistantHelpBox', t('common.module_guide')); ?><?php echo t('assistant.intro'); ?><?php echo uiHelpBoxClose(); ?>
    

    <div class="assistant-chat u-card-pad">
        <div class="u-flex-gap u-d-flex u-gap-8">
            <input type="text" id="asst-request" class="form-control u-flex-1"
                   placeholder="<?php echo t('assistant.placeholder'); ?>"
                   onkeydown="if(event.key==='Enter') assistantPlan();">
            <button type="button" class="btn btn-primary" id="asst-ask-btn" onclick="assistantPlan()">
                <?php echo t('assistant.ask'); ?>
            </button>
        </div>
        <div id="asst-state" class="u-fs-12 u-muted u-mt-6"></div>
        <div id="asst-preview"></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-database u-primary"></i> <?php echo t('assistant.echo_title'); ?></div>
    </div>
    <div class="assistant-chat u-card-pad">
        <div class="u-flex-gap u-d-flex u-gap-8">
            <input type="text" id="echo-q" class="form-control u-flex-1"
                   placeholder="<?php echo t('assistant.echo_placeholder'); ?>"
                   onkeydown="if(event.key==='Enter') echoAsk();">
            <button type="button" class="btn btn-secondary" onclick="echoAsk()"><?php echo t('assistant.echo_ask'); ?></button>
        </div>
        <div class="u-fs-12 u-muted u-mt-6"><?php echo t('assistant.echo_askable'); ?>: <?php echo htmlspecialchars($echo_askable); ?></div>
        <div id="echo-out" class="u-mt-10"></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-list-check u-primary"></i> <?php echo t('assistant.changes_title'); ?></div>
    </div>
    <div class="u-card-pad">
        <?php echo uiTableOpen([
            t('assistant.col_when'),
            t('assistant.col_request'),
            t('assistant.col_change'),
            t('assistant.col_status'),
            t('assistant.col_source'),
            '',
        ], ['id' => 'asst-changes', 'class' => 'assistant-log']); ?>
            <?php if (!$changes): ?>
                <tr><td colspan="6" class="u-muted"><?php echo t('assistant.no_changes'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($changes as $c): ?>
                <tr>
                    <td class="u-fs-12"><?php echo htmlspecialchars((string) $c['ts']); ?></td>
                    <td class="u-fs-12"><?php echo htmlspecialchars(mb_substr((string) $c['request'], 0, 60)); ?></td>
                    <td><?php echo htmlspecialchars((string) $c['summary']); ?></td>
                    <td class="u-fs-12">
                        <?php if (!empty($c['rolled_back']) || $c['status'] === 'rolled_back'): ?>
                            <?php echo t('assistant.status_rolled_back'); ?>
                        <?php else: ?>
                            <?php echo t('assistant.status_applied'); ?>
                        <?php endif; ?>
                    </td>
                    <td class="u-fs-12 u-muted"><?php echo htmlspecialchars((string) $c['source']); ?></td>
                    <td class="text-right">
                        <?php if (empty($c['rolled_back']) && $c['status'] !== 'rolled_back'): ?>
                            <button type="button" class="btn btn-secondary u-fs-12" onclick="assistantUndo(<?php echo (int) $c['id']; ?>, this)">
                                <?php echo t('assistant.undo'); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php echo uiTableClose(); ?>
    </div>
</div>

<script>
var ASST = <?php echo json_encode($texts, JSON_UNESCAPED_UNICODE); ?>;
var CSRF = <?php echo json_encode($csrf); ?>;

function asstPost(action, data, onDone) {
  data.append('action', action);
  data.append('csrf_token', CSRF);
  fetch('/api/assistant.php', { method: 'POST', body: data, credentials: 'same-origin' })
    .then(r => r.json())
    .then(onDone)
    .catch(() => { document.getElementById('asst-state').textContent = ASST.err_generic; });
}

function assistantPlan() {
  const input = document.getElementById('asst-request');
  const request = input.value.trim();
  if (!request) return;
  const state = document.getElementById('asst-state');
  state.textContent = ASST.working;
  document.getElementById('asst-preview').innerHTML = '';
  const fd = new FormData();
  fd.append('request', request);
  asstPost('plan', fd, (r) => {
    if (!r.success || !r.plan || !r.plan.ok) {
      state.textContent = (r.plan && r.plan.message_key && window.t) ? window.t(r.plan.message_key, r.plan.message_params) : (r.error || ASST.err_generic);
      return;
    }
    state.textContent = '';
    assistantShowPreview(r);
  });
}

function asstEsc(v){return String(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function assistantShowPreview(r) {
  const p = r.plan;
  const args = Object.entries(p.arguments || {})
    .map(([k, v]) => k + ': ' + (Array.isArray(v) ? v.join(', ') : String(v)))
    .join(' · ');
  const risk = p.risk === 'medium' ? ' (' + ASST.risk_medium + ')' : (p.risk === 'low' ? '' : ' (' + p.risk + ')');
  const box = document.getElementById('asst-preview');
  box.innerHTML =
    '<div class="assistant-preview">' +
      '<div class="u-strong">' + asstEsc(window.t ? window.t(p.title_key) : p.title_key) + asstEsc(risk) + '</div>' +
      '<div class="u-fs-12 u-muted u-mt-6">' + asstEsc(args) + '</div>' +
      '<div class="u-flex-gap u-mt-10 u-d-flex u-gap-8">' +
        '<button type="button" class="btn btn-primary" id="asst-apply">' + ASST.preview_apply + '</button>' +
        '<button type="button" class="btn btn-secondary" id="asst-discard">' + ASST.preview_discard + '</button>' +
      '</div>' +
    '</div>';
  document.getElementById('asst-apply').onclick = () => assistantApply(r);
  document.getElementById('asst-discard').onclick = () => { box.innerHTML = ''; };
}

function assistantApply(r) {
  const state = document.getElementById('asst-state');
  state.textContent = ASST.working;
  const fd = new FormData();
  fd.append('request', r.request || '');
  fd.append('change_action', r.plan.action);
  fd.append('arguments', JSON.stringify(r.plan.arguments));
  asstPost('apply', fd, (res) => {
    if (!res.success) {
      state.textContent = res.error || ASST.err_generic;
      return;
    }
    state.textContent = '';
    const box = document.getElementById('asst-preview');
    box.innerHTML = '<div class="assistant-preview u-strong">' + asstEsc(res.summary || ASST.applied) +
      (res.undoable ? ' <button type="button" class="btn btn-secondary u-fs-12" onclick="assistantUndo(' + res.id + ', this)">' + ASST.undo + '</button>' : '') +
      '</div>';
    setTimeout(() => location.reload(), 1200);
  });
}

function assistantUndo(id, btn) {
  if (btn) btn.disabled = true;
  const fd = new FormData();
  fd.append('id', String(id));
  asstPost('rollback', fd, (r) => {
    if (!r.success) {
      if (btn) btn.disabled = false;
      document.getElementById('asst-state').textContent = r.error || ASST.err_generic;
      return;
    }
    if (btn) btn.parentElement.innerHTML = ASST.undo_done;
  });
}

function echoAsk() {
  const q = document.getElementById('echo-q').value.trim();
  if (!q) return;
  const out = document.getElementById('echo-out');
  out.innerHTML = '<span class="u-muted">' + ASST.working + '</span>';
  const fd = new FormData();
  fd.append('question', q);
  asstPost('echo', fd, (r) => {
    if (!r.success) { out.innerHTML = '<span class="u-muted">' + (r.error || ASST.err_generic) + '</span>'; return; }
    if (r.askable) { out.innerHTML = '<div class="assistant-preview">' + asstEsc(r.askable) + '</div>'; return; }
    let html = '<div class="assistant-preview"><div class="u-strong">' + asstEsc(r.title) + '</div>';
    if (r.rows && r.rows.length) {
      html += '<?php echo uiTableOpen([]); ?>';
      for (const row of r.rows) {
        html += '<tr>' + Object.values(row).map(v => '<td>' + asstEsc(typeof v === "number" ? (Math.round(v * 10) / 10) : v) + '</td>').join('') + '</tr>';
      }
      html += '<?php echo uiTableClose(); ?>';
    }
    if (r.narration) {
      html += '<p class="u-mt-10">' + asstEsc(r.narration) + '</p>';
    }
    out.innerHTML = html + '</div>';
  });
}
</script>
