// Local AI page (/ai-local): models, Ollama and Hugging Face lists. Data: window.AI_LOCAL (the view).
(function () {
    const D = window.AI_LOCAL || {};
    const CSRF = D.csrf;
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const safeHttpUrl = u => /^https?:\/\//i.test(String(u || '').trim()) ? String(u).trim() : '';
    const toast = (m, t) => { if (window.showFooterToast) window.showFooterToast(m, t || 'info'); };
    let localModels = D.models;
    let pendingAfterRuntime = null;
    let runtimePollTimer = null;

    function startRuntimePolling() {
        if (runtimePollTimer) return;
        runtimePollTimer = setInterval(() => { ailRefreshStatus(); }, 3000);
    }

    function stopRuntimePolling() {
        if (!runtimePollTimer) return;
        clearInterval(runtimePollTimer);
        runtimePollTimer = null;
    }

    function emptyRowHtml(colspan, msg) {
        return `<tr><td colspan="${colspan}"><div class="empty-table-box"><i class="fas fa-inbox"></i><span>${esc(msg)}</span></div></td></tr>`;
    }

    function ailReenhanceTable(tableId) {
        const tbl = document.getElementById(tableId);
        if (!tbl) return;
        const parent = tbl.parentNode;
        if (parent) {
            parent.querySelectorAll(':scope > .dt-controls-bar, :scope > .dt-pagination-bar').forEach(el => el.remove());
        }
        if (tbl.dataset.dtEnhanced === 'true') {
            const thead = tbl.querySelector('thead');
            if (thead) {
                const clone = thead.cloneNode(true);
                clone.querySelectorAll('th').forEach(th => {
                    th.classList.remove('sortable', 'sort-asc', 'sort-desc');
                    th.style.cursor = '';
                    th.style.userSelect = '';
                    th.querySelectorAll('.dt-sort-icon').forEach(ic => ic.remove());
                });
                thead.replaceWith(clone);
            }
            delete tbl.dataset.dtEnhanced;
        }
        delete tbl.dataset.noDt;
        if (window.enhanceAllDataTables) window.enhanceAllDataTables();
    }

    window.ailApi = function (action, extra) {
        const fd = new URLSearchParams(Object.assign({ action: action, csrf_token: CSRF }, extra || {}));
        return fetch('/api/ai_models.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(res => {
                if (!res || !res.success) {
                    toast((res && res.error) || 'Error', 'danger');
                } else {
                    if (res.message) toast(res.message, 'success');
                    if (res.runtime_installing && ['model_install', 'add_piper', 'model_run'].includes(action)) {
                        pendingAfterRuntime = { action: action, extra: extra || {}, at: Date.now() / 1000 };
                        startRuntimePolling();
                    } else if (action === 'runtime_install') {
                        startRuntimePolling();
                    }
                    ailRefreshStatus();
                }
                return res;
            })
            .catch(() => toast('Request failed', 'danger'));
    };

    window.ailRefreshStatus = function () {
        fetch('/api/ai_models.php?action=status', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(s => {
                if (!s || !s.success) return;
                if (Array.isArray(s.models)) { localModels = s.models; ailRender(); }
                const rt = document.getElementById('ail-runtime-badge');
                if (s.runtime) {
                    const rst = s.runtime.state || 'absent';
                    if (rt) {
                        const rcls = { installed: 'badge-success', installing: 'badge-warning', failed: 'badge-danger' }[rst] || 'badge-secondary';
                        rt.textContent = rst;
                        rt.className = 'badge ' + rcls;
                    }
                    // Right after the request the background setup may not have written its state yet: an
                    // "absent", or a "failed" from an earlier attempt, is stale until the new attempt shows up.
                    const pend = pendingAfterRuntime;
                    const fresh = pend && Number(s.runtime.started || 0) >= pend.at - 5;
                    if (rst === 'installing' || (pend && rst !== 'installed' && !fresh && Date.now() / 1000 - pend.at < 90)) {
                        startRuntimePolling();
                    } else {
                        stopRuntimePolling();
                        if (rst === 'installed' && pend) {
                            pendingAfterRuntime = null;
                            ailApi(pend.action, pend.extra);
                        } else if (pend) {
                            pendingAfterRuntime = null;
                            toast(s.runtime.error || 'Runtime installation failed', 'danger');
                        }
                    }
                }
            });
    };

    let piperVoices = null;
    const HF_TXT = {
        recommended: D.t_badge_recommended, download: D.t_btn_download, cancel: D.t_btn_cancel_download, run: D.t_btn_run, stop: D.t_btn_stop,
        remove: D.t_confirm_model_remove, license: D.t_license,
        tts: D.t_tts_settings, speed: D.t_tts_speed, speaker: D.t_tts_speaker, pause: D.t_tts_pause, start: D.t_tts_start,
        pauses: { short: D.t_tts_pause_short, normal: D.t_tts_pause_normal, long: D.t_tts_pause_long },
        save: D.t_btn_save, noncommercial: D.t_noncommercial, none: D.t_no_records
    };

    window.ailLoad = function (refresh) {
        fetch('/api/ai_models.php?action=piper_catalog', { credentials: 'same-origin' }).then(r => r.json()).catch(() => null)
            .then(res => { piperVoices = (res && res.success) ? (res.voices || []) : []; ailRender(); });
    };

    // Speed, speaker (voices with several) and pause between sentences: folded away until wanted.
    function ailTtsSettings(m) {
        if (m.kind !== 'tts' || !m.tts || !m.tts_options || !['installed', 'ready', 'loading'].includes(m.state)) return '';
        const speakers = m.speakers || [];
        const cur = m.tts.speaker === null || m.tts.speaker === undefined ? '' : String(m.tts.speaker);
        const speakerHtml = speakers.length
            ? `<label class="u-fs-11">${esc(HF_TXT.speaker)} <select class="form-control form-control-sm" data-tts="speaker"><option value=""></option>`
                + speakers.map(s => `<option value="${esc(String(s.id))}"${String(s.id) === cur ? ' selected' : ''}>${esc(s.name)}</option>`).join('') + '</select></label>'
            : '';
        return `<details class="u-mt-6"><summary class="u-fs-11 u-muted">${esc(HF_TXT.tts)}</summary>
            <div class="u-d-flex u-gap-8 u-wrap u-ai-center u-mt-6" data-tts-box data-id="${esc(m.id)}" data-speaker="${esc(cur)}">
                <label class="u-fs-11">${esc(HF_TXT.speed)} <input type="number" class="form-control form-control-sm u-maxw-100" data-tts="speed" min="0.5" max="2" step="0.05" value="${esc(String(m.tts.speed))}"></label>
                ${speakerHtml}
                <label class="u-fs-11">${esc(HF_TXT.pause)} <select class="form-control form-control-sm" data-tts="pause">`
                + ['short', 'normal', 'long'].map(p => `<option value="${p}"${m.tts.pause === p ? ' selected' : ''}>${esc(HF_TXT.pauses[p])}</option>`).join('')
                + `</select></label>
                <button type="button" class="btn btn-primary btn-xs" data-ail-action="tts_settings"><i class="fas fa-save"></i> ${esc(HF_TXT.save)}</button>
            </div></details>`;
    }

    function ailModelActions(m) {
        const id = esc(m.id), st = m.state || 'absent';
        if (st === 'absent' || st === 'error') {
            return `<button type="button" class="btn btn-primary btn-xs" data-ail-action="model_install" data-id="${id}"><i class="fas fa-download"></i> ${esc(HF_TXT.download)}</button>`
                + (st === 'error' ? ` <button type="button" class="btn btn-danger btn-xs" data-ail-action="model_remove" data-id="${id}"><i class="fas fa-trash"></i></button>` : '');
        }
        if (st === 'downloading') {
            return `<button type="button" class="btn btn-warning btn-xs" data-ail-action="model_cancel" data-id="${id}"><i class="fas fa-ban"></i> ${esc(HF_TXT.cancel)}</button>`;
        }
        if (st === 'loading') return '';
        return (st === 'ready'
            ? `<button type="button" class="btn btn-warning btn-xs" data-ail-action="model_stop" data-id="${id}"><i class="fas fa-stop"></i> ${esc(HF_TXT.stop)}</button> `
            : `<button type="button" class="btn btn-success btn-xs" data-ail-action="model_run" data-id="${id}"><i class="fas fa-play"></i> ${esc(HF_TXT.run)}</button> `)
            + `<button type="button" class="btn btn-danger btn-xs" data-ail-action="model_remove" data-id="${id}"><i class="fas fa-trash"></i></button>`;
    }

    function ailModelActions(m) {
        const id = esc(m.id), st = m.state || 'absent';
        if (st === 'absent' || st === 'error') {
            return `<button type="button" class="btn btn-primary btn-xs" data-ail-action="model_install" data-id="${id}"><i class="fas fa-download"></i> ${esc(HF_TXT.download)}</button>`
                + (st === 'error' ? ` <button type="button" class="btn btn-danger btn-xs" data-ail-action="model_remove" data-id="${id}"><i class="fas fa-trash"></i></button>` : '');
        }
        if (st === 'downloading') {
            return `<button type="button" class="btn btn-warning btn-xs" data-ail-action="model_cancel" data-id="${id}"><i class="fas fa-ban"></i> ${esc(HF_TXT.cancel)}</button>`;
        }
        if (st === 'loading') return '';
        return (st === 'ready'
            ? `<button type="button" class="btn btn-warning btn-xs" data-ail-action="model_stop" data-id="${id}"><i class="fas fa-stop"></i> ${esc(HF_TXT.stop)}</button> `
            : `<button type="button" class="btn btn-success btn-xs" data-ail-action="model_run" data-id="${id}"><i class="fas fa-play"></i> ${esc(HF_TXT.run)}</button> `)
            + `<button type="button" class="btn btn-danger btn-xs" data-ail-action="model_remove" data-id="${id}"><i class="fas fa-trash"></i></button>`;
    }

    // One table: the voices and detectors of the service plus the whole Piper catalogue (not added yet).
    window.ailRender = function () {
        const tbody = document.getElementById('ail-tbody');
        if (!tbody) return;
        const stateBadge = (st) => {
            const cls = { ready: 'badge-success', installed: 'badge-success', downloading: 'badge-info', loading: 'badge-info', error: 'badge-danger' }[st] || 'badge-secondary';
            return `<span class="badge ${cls} u-fs-10">${esc(st)}</span>`;
        };
        const rows = [];
        for (const m of localModels) {
            if (!['tts', 'vad', 'turn'].includes(m.kind)) continue;
            const st = m.state || 'absent';
                        const licUrl = safeHttpUrl(m.license_url);
            const sub = esc(HF_TXT.license) + ': ' + (licUrl ? `<a href="${esc(licUrl)}" target="_blank" rel="noopener">${esc(m.license || '?')}</a>` : esc(m.license || '?'))
                + (m.commercial === false ? ' <span class="badge badge-warning u-fs-10">' + esc(HF_TXT.noncommercial) + '</span>' : '')
                + (m.kind !== 'tts' ? `<div class="u-fs-11 u-muted">${esc(m.note || '')}</div>` : '');
            const order = st === 'ready' || st === 'loading' ? 0 : (st === 'installed' || st === 'downloading' ? 1 : (st === 'error' ? 2 : 3));
            rows.push({ order, html: `<tr>
                <td><strong>${esc(m.title || m.id)}</strong><div class="u-fs-11 u-muted">${sub}</div>${ailTtsSettings(m)}</td>
                <td class="u-fs-12">${esc(String(m.kind).toUpperCase())}</td>
                <td class="u-fs-12">${esc((m.languages || []).join(', '))}</td>
                <td class="u-fs-12 u-mono">${esc((st === 'absent' ? m.download_mb : (m.disk_mb || m.download_mb)) + ' MB')}</td>
                <td>${stateBadge(st)}${st === 'downloading' && m.progress ? ' ' + esc(m.progress + '%') : ''}</td>
                <td class="u-text-right u-nowrap">${ailModelActions(m)}</td></tr>` });
        }
        for (const v of piperVoices || []) {
            if (v.registered) continue;
            const cardUrl = safeHttpUrl(v.card_url);
            rows.push({ order: 4, html: `<tr>
                <td><strong>Piper ${esc(v.name)}</strong> <span class="u-fs-11 u-muted">${esc(v.quality)}</span>${cardUrl ? `<div class="u-fs-11"><a href="${esc(cardUrl)}" target="_blank" rel="noopener">${esc(HF_TXT.license)}</a></div>` : ''}</td>
                <td class="u-fs-12">TTS</td>
                <td class="u-fs-12">${esc(v.language_name)}${v.country ? ' (' + esc(v.country) + ')' : ''}</td>
                <td class="u-fs-12 u-mono">${esc(v.size_mb + ' MB')}</td>
                <td>${stateBadge('absent')}</td>
                <td class="u-text-right"><button type="button" class="btn btn-primary btn-xs" data-ail-action="add_piper" data-key="${esc(v.key)}"><i class="fas fa-download"></i> ${esc(HF_TXT.download)}</button></td></tr>` });
        }
        rows.sort((x, y) => x.order - y.order);
        tbody.innerHTML = rows.length ? rows.map(r => r.html).join('') : emptyRowHtml(6, HF_TXT.none);
        ailReenhanceTable('ail-table');
    };

    const tbody = document.getElementById('ail-tbody');
    if (tbody) {
        tbody.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-ail-action]');
            if (!btn) return;
            const act = btn.dataset.ailAction;
            if (act === 'model_install') ailApi('model_install', { id: btn.dataset.id || '', accept_license: 1 });
            else if (act === 'model_cancel') ailApi('model_cancel', { id: btn.dataset.id || '' });
            else if (act === 'model_run') ailApi('model_run', { id: btn.dataset.id || '' });
            else if (act === 'model_stop') ailApi('model_stop', { id: btn.dataset.id || '' });
            else if (act === 'model_remove' && confirm(HF_TXT.remove)) ailApi('model_remove', { id: btn.dataset.id || '' });
            else if (act === 'tts_settings') {
                const box = btn.closest('[data-tts-box]');
                const val = (k) => (box.querySelector(`[data-tts="${k}"]`)?.value ?? '');
                const sel = box.querySelector('[data-tts="speaker"]');
                ailApi('tts_settings', { id: box.dataset.id || '', speed: val('speed'), speaker: sel ? sel.value : (box.dataset.speaker || ''), pause: val('pause') });
            }
            else if (act === 'add_piper') ailApi('add_piper', { key: btn.dataset.key || '', accept_license: 1 });
        });
    }
    ailLoad(false);
})();
