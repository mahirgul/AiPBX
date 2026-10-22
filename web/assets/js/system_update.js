/* Page script of templates/views/system_update/index.php */

const SU_TEXT = window.SYSTEM_UPDATE_PAGE.text;
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
    // Older updaters do not say: then the update restarts Asterisk.
    const restart = ck.restart !== false;
    const rEl = document.getElementById('su-restart');
    if (rEl) {
        rEl.style.display = ck.available ? '' : 'none';
        rEl.style.color = restart ? 'var(--warning)' : 'var(--success)';
        rEl.textContent = restart ? SU_TEXT.restart_yes.replace('%s', ck.restart_reason || '') : SU_TEXT.restart_no;
    }
    const allowLabel = document.getElementById('su-allow-calls-label');
    if (allowLabel) allowLabel.style.display = restart ? '' : 'none';
    window.SYSTEM_UPDATE_PAGE.restart = restart;
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
    const restart = window.SYSTEM_UPDATE_PAGE.restart !== false;
    if (!confirm((restart ? SU_TEXT.confirm : SU_TEXT.confirm_no_restart).replace('%s', target))) return;
    const allow = document.getElementById('su-allow-calls').checked ? '1' : '';
    suPost('start', allow ? { allow_calls: '1' } : {}).then(res => {
        if (!res.success) { suToast(res.error, 'error'); return; }
        suPoll();
        setTimeout(suRefresh, 1500);
    });
}

(function () { const l = document.getElementById('su-log'); if (l) l.scrollTop = l.scrollHeight; })();
if (window.SYSTEM_UPDATE_PAGE.running) suPoll();
