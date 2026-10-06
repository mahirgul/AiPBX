/* Sounds page → "Asterisk sound packs" tab (templates/views/sounds/index.php). */
// `var` / function declarations only: the SPA re-runs page scripts and a
// top-level let/const would throw a redeclaration error (see sounds.js).
var spPollTimer = null;

function spPage() { return window.SOUND_PACKS_PAGE || null; }

function spEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function spToast(msg, type) { if (window.showFooterToast) window.showFooterToast(msg, type); }

function spFillLanguages() {
    const P = spPage();
    const kindEl = document.getElementById('sp-kind');
    const langEl = document.getElementById('sp-lang');
    if (!P || !kindEl || !langEl) return;
    const langs = P.catalog[kindEl.value] || {};
    langEl.innerHTML = Object.keys(langs)
        .map(code => '<option value="' + spEsc(code) + '">' + spEsc(langs[code]) + ' (' + spEsc(code) + ')</option>')
        .join('');
}

function spPackLabel(kind, lang, format) {
    const P = spPage();
    return (P.text['kind_' + kind] || kind) + ' · ' + ((P.catalog[kind] || {})[lang] || lang) + ' · ' + format;
}

function spRender(d) {
    const P = spPage();
    const body = document.getElementById('sp-installed');
    if (!P || !body || !d) return;
    P.installed = d.installed || {};
    P.status = d.status || null;

    const running = !!(P.status && P.status.state === 'running');
    const keys = Object.keys(P.installed).sort();
    if (!keys.length) {
        body.innerHTML = '<tr><td colspan="7" class="u-muted u-text-center">' + spEsc(P.text.empty) + '</td></tr>';
    } else {
        body.innerHTML = keys.map(key => {
            const p = P.installed[key];
            const when = p.installed_at ? new Date(p.installed_at).toLocaleString() : '';
            const check = p.verified
                ? '<span class="badge badge-success" title="SHA1">' + spEsc(P.text.verified) + '</span>'
                : '<span class="badge badge-warning">' + spEsc(P.text.unverified) + '</span>';
            const removeBtn = P.can_edit
                ? '<button type="button" class="btn btn-secondary btn-sm" ' + (running ? 'disabled ' : '')
                    + 'onclick="spRemove(\'' + spEsc(p.kind) + '\',\'' + spEsc(p.lang) + '\',\'' + spEsc(p.format) + '\')" title="' + spEsc(P.text.remove) + '">'
                    + '<i class="fas fa-trash-alt"></i></button>'
                : '';
            return '<tr>'
                + '<td class="u-fw-700">' + spEsc(P.text['kind_' + p.kind] || p.kind) + '</td>'
                + '<td>' + spEsc((P.catalog[p.kind] || {})[p.lang] || p.lang) + ' <code>' + spEsc(p.lang) + '</code></td>'
                + '<td><span class="badge badge-info">' + spEsc(p.format) + '</span></td>'
                + '<td class="col-hide-mobile">' + spEsc(p.version) + ' ' + check + '</td>'
                + '<td class="col-hide-mobile">' + spEsc(p.files) + '</td>'
                + '<td class="col-hide-mobile u-muted u-fs-12">' + spEsc(when) + '</td>'
                + '<td class="u-text-right">' + removeBtn + '</td>'
                + '</tr>';
        }).join('');
    }

    const stateEl = document.getElementById('sp-state');
    if (stateEl) {
        const st = P.status;
        if (st && st.state) {
            stateEl.style.color = { running: 'var(--info, #0ea5e9)', done: 'var(--success)', failed: 'var(--danger)' }[st.state] || 'inherit';
            stateEl.innerHTML = (running ? '<i class="fas fa-spinner fa-spin"></i> ' : '')
                + spEsc((P.text[st.state] || st.state) + (st.step ? ' — ' + st.step : ''));
        } else {
            stateEl.textContent = '';
        }
    }
    const btn = document.getElementById('sp-install-btn');
    if (btn) btn.disabled = running;

    const logEl = document.getElementById('sp-log');
    if (logEl && d.log !== undefined) {
        logEl.textContent = d.log || '—';
        logEl.scrollTop = logEl.scrollHeight;
    }
    if (running) spPoll(); else spStopPoll();
}

function spRefresh() {
    // Left the page (SPA): stop polling.
    if (!document.getElementById('sp-installed')) { spStopPoll(); return Promise.resolve(); }
    return fetch('/api/sound_packs.php?action=status', { cache: 'no-store' })
        .then(r => r.json()).then(spRender).catch(() => {});
}
function spPoll() { if (!spPollTimer) spPollTimer = setInterval(spRefresh, 2500); }
function spStopPoll() { if (spPollTimer) { clearInterval(spPollTimer); spPollTimer = null; } }

function spPost(action, kind, lang, format) {
    const body = new URLSearchParams({ action: action, kind: kind, lang: lang, format: format, csrf_token: window.CSRF_TOKEN || '' });
    return fetch('/api/sound_packs.php', { method: 'POST', body: body })
        .then(r => r.json())
        .then(res => {
            if (!res.success) { spToast(res.error || spPage().text.request_failed, 'error'); return; }
            spPoll();
            setTimeout(spRefresh, 800);
        })
        .catch(() => spToast(spPage().text.request_failed, 'error'));
}

function spInstall() {
    const P = spPage();
    const kind = document.getElementById('sp-kind').value;
    const lang = document.getElementById('sp-lang').value;
    const format = document.getElementById('sp-format').value;
    if (!kind || !lang || !format) return;
    if (P.installed[kind + '-' + lang + '-' + format]) { spToast(P.text.installed, 'info'); return; }
    if (!confirm(P.text.confirm_install.replace('%s', spPackLabel(kind, lang, format)))) return;
    spPost('install', kind, lang, format);
}

function spRemove(kind, lang, format) {
    const P = spPage();
    if (!confirm(P.text.confirm_remove.replace('%s', spPackLabel(kind, lang, format)))) return;
    spPost('remove', kind, lang, format);
}

(function spInit() {
    const P = spPage();
    if (!P || !document.getElementById('sp-installed')) return;
    spFillLanguages();
    spRender({ installed: P.installed, status: P.status, log: P.log });
})();
