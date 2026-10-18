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
    <div class="card-header">
        <div class="card-title"><i class="fas fa-wand-magic-sparkles u-primary"></i> <?php echo t('assistant.title'); ?></div>
    </div>
    <p class="u-muted" style="padding: 0 20px;"><?php echo t('assistant.intro'); ?></p>

    <div class="assistant-chat" style="padding: 0 20px 20px;">
        <div class="u-flex-gap" style="display:flex; gap:8px;">
            <input type="text" id="asst-request" class="form-control" style="flex:1"
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
    <div class="assistant-chat" style="padding: 0 20px 20px;">
        <div class="u-flex-gap" style="display:flex; gap:8px;">
            <input type="text" id="echo-q" class="form-control" style="flex:1"
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
    <div style="padding: 0 20px 20px;">
        <table class="data-table assistant-log" id="asst-changes">
            <thead><tr>
                <th><?php echo t('assistant.col_when'); ?></th>
                <th><?php echo t('assistant.col_request'); ?></th>
                <th><?php echo t('assistant.col_change'); ?></th>
                <th><?php echo t('assistant.col_status'); ?></th>
                <th><?php echo t('assistant.col_source'); ?></th>
                <th></th>
            </tr></thead>
            <tbody>
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
            </tbody>
        </table>
    </div>
</div>

<script>
const ASST = <?php echo json_encode($texts, JSON_UNESCAPED_UNICODE); ?>;
const CSRF = <?php echo json_encode($csrf); ?>;

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

function assistantShowPreview(r) {
  const p = r.plan;
  const args = Object.entries(p.arguments || {})
    .map(([k, v]) => k + ': ' + (Array.isArray(v) ? v.join(', ') : String(v)))
    .join(' · ');
  const risk = p.risk === 'medium' ? ' (' + ASST.risk_medium + ')' : (p.risk === 'low' ? '' : ' (' + p.risk + ')');
  const box = document.getElementById('asst-preview');
  box.innerHTML =
    '<div class="assistant-preview">' +
      '<div class="u-strong">' + (window.t ? window.t(p.title_key) : p.title_key) + risk + '</div>' +
      '<div class="u-fs-12 u-muted u-mt-6">' + args + '</div>' +
      '<div class="u-flex-gap u-mt-10" style="display:flex; gap:8px;">' +
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
    box.innerHTML = '<div class="assistant-preview u-strong">' + (res.summary || ASST.applied) +
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
    if (r.askable) { out.innerHTML = '<div class="assistant-preview">' + r.askable + '</div>'; return; }
    let html = '<div class="assistant-preview"><div class="u-strong">' + r.title + '</div>';
    if (r.rows && r.rows.length) {
      html += '<table class="data-table" style="margin-top:8px;"><tbody>';
      for (const row of r.rows) {
        html += '<tr>' + Object.values(row).map(v => '<td>' + (typeof v === "number" ? (Math.round(v * 10) / 10) : v) + '</td>').join('') + '</tr>';
      }
      html += '</tbody></table>';
    }
    if (r.narration) {
      html += '<p class="u-mt-10">' + r.narration + '</p>';
    }
    out.innerHTML = html + '</div>';
  });
}
</script>
