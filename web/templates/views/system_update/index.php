<?php
$latest = $check['latest'] ?? '';
$available = !empty($check['available']);
$state = $status['state'] ?? '';
$state_labels = [
    'running' => t('system_update.state_running'),
    'done' => t('system_update.state_done'),
    'failed' => t('system_update.state_failed'),
    'rolled_back' => t('system_update.state_rolled_back'),
];
$state_colors = ['running' => 'var(--info, #0ea5e9)', 'done' => 'var(--success)', 'failed' => 'var(--danger)', 'rolled_back' => 'var(--warning)'];
?>
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-cloud-arrow-down u-primary"></i> <?php echo t('system_update.page_title'); ?>
        </div>
    </div>

    <div class="module-help-box" style="display: block; margin-bottom: 20px;">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('system_update.help_title'); ?></h4>
        <?php echo t('system_update.help_body'); ?>
    </div>

    <div class="u-grid-2" style="margin-bottom: 20px;">
        <div class="card" style="padding: 16px;">
            <div class="u-muted u-fs-12"><?php echo t('system_update.current'); ?></div>
            <div style="font-size: 24px; font-weight: 800;" id="su-current">v<?php echo htmlspecialchars($current); ?></div>
        </div>
        <div class="card" style="padding: 16px;">
            <div class="u-muted u-fs-12"><?php echo t('system_update.latest'); ?></div>
            <div style="font-size: 24px; font-weight: 800;" id="su-latest"><?php echo htmlspecialchars($latest ?: '—'); ?></div>
            <div class="u-muted u-fs-11" id="su-checked">
                <?php if (!empty($check['checked_at'])): ?>
                    <?php echo t('system_update.checked_at'); ?>: <?php echo htmlspecialchars(date('d.m.Y H:i', strtotime($check['checked_at']))); ?>
                <?php else: ?>
                    <?php echo t('system_update.never_checked'); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="u-flex-gap" style="flex-wrap: wrap; align-items: center; margin-bottom: 16px;">
        <button type="button" class="btn btn-secondary" id="su-check-btn" onclick="suCheck()">
            <i class="fas fa-rotate"></i> <?php echo t('system_update.btn_check'); ?>
        </button>
        <button type="button" class="btn btn-primary" id="su-start-btn" onclick="suStart()" <?php echo ($available && $state !== 'running') ? '' : 'disabled'; ?>>
            <i class="fas fa-download"></i> <?php echo t('system_update.btn_update'); ?>
        </button>
        <label class="u-check-label u-fs-12">
            <input type="checkbox" id="su-allow-calls" class="u-accent"> <?php echo t('system_update.allow_calls'); ?>
        </label>
    </div>

    <div id="su-state" class="u-fw-600" style="margin-bottom: 10px; color: <?php echo $state_colors[$state] ?? 'inherit'; ?>;">
        <?php if ($state !== ''): ?>
            <?php echo htmlspecialchars(($state_labels[$state] ?? $state) . (!empty($status['step']) ? ' — ' . $status['step'] : '') . (!empty($status['updated_at']) ? ' (' . date('d.m.Y H:i', strtotime($status['updated_at'])) . ')' : '')); ?>
        <?php endif; ?>
    </div>

    <div id="su-notes-box" style="<?php echo $available ? '' : 'display: none;'; ?> margin-bottom: 16px;">
        <div class="u-strong" style="margin-bottom: 6px;"><?php echo t('system_update.notes'); ?></div>
        <pre id="su-notes" style="white-space: pre-wrap; background: var(--bg-main); border: 1px solid var(--border-color); border-radius: 8px; padding: 12px; font-size: 12px; max-height: 260px; overflow: auto;"><?php echo htmlspecialchars($check['notes'] ?? ''); ?></pre>
    </div>

    <div>
        <div class="u-strong" style="margin-bottom: 6px;"><?php echo t('system_update.log'); ?></div>
        <pre id="su-log" style="white-space: pre-wrap; background: #0f172a; color: #e2e8f0; border-radius: 8px; padding: 12px; font-size: 11px; max-height: 360px; overflow: auto;"><?php echo htmlspecialchars($log_tail ?: '—'); ?></pre>
    </div>
</div>

<script>
const SU_TEXT = {
    running: <?php echo json_encode(t('system_update.state_running')); ?>,
    done: <?php echo json_encode(t('system_update.state_done')); ?>,
    failed: <?php echo json_encode(t('system_update.state_failed')); ?>,
    rolled_back: <?php echo json_encode(t('system_update.state_rolled_back')); ?>,
    confirm: <?php echo json_encode(t('system_update.confirm')); ?>,
    up_to_date: <?php echo json_encode(t('system_update.up_to_date')); ?>,
    unreachable: <?php echo json_encode(t('system_update.unreachable')); ?>
};
let suPollTimer = null;

function suToast(msg, type) { if (window.showFooterToast) window.showFooterToast(msg, type); }

function suPost(action, extra) {
    const body = new URLSearchParams(Object.assign({ action: action, csrf_token: window.CSRF_TOKEN || '' }, extra || {}));
    return fetch('/api/system_update.php', { method: 'POST', body: body }).then(r => r.json());
}

function suRender(d) {
    if (!d) return;
    const st = d.status || {};
    const ck = d.check || {};
    document.getElementById('su-current').textContent = 'v' + d.current;
    document.getElementById('su-latest').textContent = ck.latest || '—';
    const stateEl = document.getElementById('su-state');
    if (st.state) {
        const color = { running: 'var(--info, #0ea5e9)', done: 'var(--success)', failed: 'var(--danger)', rolled_back: 'var(--warning)' }[st.state] || 'inherit';
        stateEl.style.color = color;
        stateEl.textContent = (SU_TEXT[st.state] || st.state) + (st.step ? ' — ' + st.step : '');
    }
    const running = st.state === 'running';
    document.getElementById('su-start-btn').disabled = running || !ck.available;
    document.getElementById('su-check-btn').disabled = running;
    if (ck.notes) {
        document.getElementById('su-notes').textContent = ck.notes;
        document.getElementById('su-notes-box').style.display = ck.available ? '' : 'none';
    }
    if (d.log) {
        const logEl = document.getElementById('su-log');
        logEl.textContent = d.log;
        logEl.scrollTop = logEl.scrollHeight;
    }
    if (running) suPoll(); else suStopPoll();
}

function suRefresh() {
    // The update restarts Apache: requests may fail meanwhile, polling just continues.
    return fetch('/api/system_update.php?action=status', { cache: 'no-store' })
        .then(r => r.json()).then(suRender).catch(() => {});
}
function suPoll() { if (!suPollTimer) suPollTimer = setInterval(suRefresh, 3000); }
function suStopPoll() { if (suPollTimer) { clearInterval(suPollTimer); suPollTimer = null; } }

function suCheck() {
    const btn = document.getElementById('su-check-btn');
    btn.disabled = true;
    suPost('check').then(res => {
        btn.disabled = false;
        if (!res.success) { suToast(res.error || SU_TEXT.unreachable, 'error'); return; }
        if (!res.check.available) suToast(SU_TEXT.up_to_date, 'success');
        suRefresh();
    }).catch(() => { btn.disabled = false; suToast(SU_TEXT.unreachable, 'error'); });
}

function suStart() {
    const target = document.getElementById('su-latest').textContent;
    if (!confirm(SU_TEXT.confirm.replace('%s', target))) return;
    const allow = document.getElementById('su-allow-calls').checked ? '1' : '';
    suPost('start', allow ? { allow_calls: '1' } : {}).then(res => {
        if (!res.success) { suToast(res.error, 'error'); return; }
        suPoll();
        setTimeout(suRefresh, 1500);
    });
}

(function () { const l = document.getElementById('su-log'); if (l) l.scrollTop = l.scrollHeight; })();
<?php if ($state === 'running'): ?>suPoll();<?php endif; ?>
</script>
