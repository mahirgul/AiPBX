// Local AI page (/ai-local): models, Ollama and Hugging Face lists. Data: window.AI_LOCAL (the view).
(function () {
    const D = window.AI_LOCAL || {};
    const CSRF = D.csrf;
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const safeHttpUrl = u => /^https?:\/\//i.test(String(u || '').trim()) ? String(u).trim() : '';
    const toast = (m, t) => { if (window.showFooterToast) window.showFooterToast(m, t || 'info'); };
    let localModels = D.models;
    let ollamaItems = [];
    let ollamaTagSizes = {};
    let hfItems = [];

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
                if (Array.isArray(s.models)) { localModels = s.models; ailRenderHf(); }
                const rt = document.getElementById('ail-runtime-badge');
                if (rt && s.runtime) rt.textContent = s.runtime.state || 'absent';
                const ol = document.getElementById('ail-ollama-badge');
                if (ol && s.ollama) {
                    const ost = s.ollama.state || 'absent';
                    const ok = ['installed', 'ready', 'running'].includes(ost);
                    ol.textContent = ost;
                    ol.className = 'badge ' + (ok ? 'badge-success' : 'badge-secondary');
                    const ib = document.getElementById('ail-ollama-install-btn');
                    if (ib) ib.style.display = ok ? 'none' : '';
                }
            });
    };

    window.ailLoadOllama = function (refresh) {
        fetch('/api/ai_models.php?action=ollama_catalog' + (refresh ? '&refresh=1' : ''), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(res => {
                if (res && res.success) {
                    ollamaItems = res.models || [];
                    ailRenderOllama();
                } else {
                    ollamaItems = [];
                    const tbody = document.getElementById('ail-ol-tbody');
                    if (tbody) {
                        tbody.innerHTML = emptyRowHtml(6, (res && res.error) || HF_TXT.none);
                        ailReenhanceTable('ail-ol-table');
                    }
                }
            })
            .catch(() => {
                ollamaItems = [];
                const tbody = document.getElementById('ail-ol-tbody');
                if (tbody) {
                    tbody.innerHTML = emptyRowHtml(6, HF_TXT.none);
                    ailReenhanceTable('ail-ol-table');
                }
            });
    };

    function ailEstOllamaSize(baseName, tag) {
        const base = String(baseName || '').split(':')[0].toLowerCase();
        const t = String(tag || '').toLowerCase();
        if (ollamaTagSizes[base] && ollamaTagSizes[base][t]) return ollamaTagSizes[base][t];
        const m = t.match(/^([0-9]+(?:\.[0-9]+)?)([bm])$/);
        if (!m) return '-';
        const num = parseFloat(m[1]);
        if (m[2] === 'b') {
            const gb = num * 0.65;
            return '~' + (gb >= 10 ? Math.round(gb) : gb.toFixed(1)) + ' GB';
        }
        return '~' + Math.round(num * 0.65) + ' MB';
    }

    window.ailFetchOllamaTags = function (baseName, idx) {
        const base = String(baseName || '').split(':')[0].toLowerCase();
        if (!base || ollamaTagSizes[base]) return;
        ollamaTagSizes[base] = {};
        fetch('/api/ai_models.php?action=ollama_tags&name=' + encodeURIComponent(base), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(res => {
                if (res && res.success && res.tags) {
                    ollamaTagSizes[base] = res.tags;
                    const sel = document.getElementById('ail-ol-tag-' + idx);
                    const cell = document.getElementById('ail-ol-size-' + idx);
                    if (sel && cell) cell.textContent = ailEstOllamaSize(base, sel.value);
                }
            });
    };

    window.ailOnOllamaTagChange = function (baseName, idx) {
        const sel = document.getElementById('ail-ol-tag-' + idx);
        const cell = document.getElementById('ail-ol-size-' + idx);
        if (sel && cell) cell.textContent = ailEstOllamaSize(baseName, sel.value);
        ailFetchOllamaTags(baseName, idx);
    };

    window.ailPullOllama = function (baseName, selId) {
        const sel = selId ? document.getElementById(selId) : null;
        const tag = sel ? sel.value : '';
        const fullName = (tag && !baseName.includes(':')) ? (baseName + ':' + tag) : baseName;
        ailApi('ollama_pull', { name: fullName }).then(() => ailLoadOllama(false));
    };

    window.ailRenderOllama = function () {
        const tbody = document.getElementById('ail-ol-tbody');
        if (!tbody) return;
        if (!ollamaItems.length) {
            tbody.innerHTML = emptyRowHtml(6, D.t_no_records);
        } else {
            const olRank = (m) => (m.state === 'ready' || m.state === 'loading') ? 0 : ((m.installed || m.state === 'installed') ? 1 : 2);
            const sortedOllama = [...ollamaItems].sort((a, b) => olRank(a) - olRank(b));
            tbody.innerHTML = sortedOllama.map((m, idx) => {
                const rawParams = String(m.size_label || '-');
                const tags = rawParams.split(',').map(s => s.trim()).filter(s => /^[0-9]+(\.[0-9]+)?[bm]$/i.test(s));
                const selId = 'ail-ol-tag-' + idx;
                let paramCell = esc(rawParams);
                let diskSize = m.disk_label ? String(m.disk_label) : '-';
                if (!m.installed) {
                    if (tags.length > 1 && !String(m.name || '').includes(':')) {
                        paramCell = `<select id="${selId}" class="form-control form-control-sm u-w-auto u-d-inline-block" data-ail-ol-select="1" data-name="${esc(m.name)}" data-idx="${idx}">${tags.map(t => `<option value="${esc(t)}">${esc(t)}</option>`).join('')}</select>`;
                        diskSize = ailEstOllamaSize(m.name, tags[0]);
                    } else if (tags.length === 1) {
                        diskSize = ailEstOllamaSize(m.name, tags[0]);
                    } else if (/[0-9]+\s*(gb|mb)/i.test(rawParams)) {
                        paramCell = '-';
                        diskSize = rawParams;
                    }
                }
                let btns = '';
                if (!m.installed) {
                    const targetName = (tags.length === 1 && !String(m.name || '').includes(':')) ? (m.name + ':' + tags[0]) : m.name;
                    btns = `<button type="button" class="btn btn-primary btn-xs" data-ail-action="ollama_pull" data-name="${esc(targetName)}" data-sel="${tags.length > 1 ? selId : ''}"><i class="fas fa-download"></i> Pull</button>`;
                } else {
                    if (m.state !== 'ready') btns += `<button type="button" class="btn btn-success btn-xs" data-ail-action="ollama_run" data-id="${esc(m.id)}"><i class="fas fa-play"></i> Run</button> `;
                    else btns += `<button type="button" class="btn btn-warning btn-xs" data-ail-action="ollama_stop" data-id="${esc(m.id)}"><i class="fas fa-stop"></i> Stop</button> `;
                    btns += `<button type="button" class="btn btn-danger btn-xs" data-ail-action="ollama_remove" data-id="${esc(m.id)}"><i class="fas fa-trash"></i></button>`;
                }
                return `<tr>
                    <td><strong>${esc(m.title || m.name)}</strong><div class="u-fs-11 u-muted">${esc(m.description || '')}</div></td>
                    <td><span class="badge badge-secondary u-fs-11">${esc(m.kind || 'llm')}</span></td>
                    <td class="u-fs-12 u-mono">${paramCell}</td>
                    <td id="ail-ol-size-${idx}" class="u-fs-12 u-mono">${esc(diskSize)}</td>
                    <td><span class="badge ${m.installed ? 'badge-success' : 'badge-secondary'}">${esc(m.state || 'absent')}</span></td>
                    <td class="u-text-right u-nowrap">${btns}</td>
                </tr>`;
            }).join('');
        }
        ailReenhanceTable('ail-ol-table');
    };

    // Hugging Face tab: built-in models, Piper voices and Hub results of runnable formats, one table.
    let piperVoices = null;
    const HF_TXT = {
        recommended: D.t_badge_recommended,
        download: D.t_btn_download,
        cancel: D.t_btn_cancel_download,
        run: D.t_btn_run,
        stop: D.t_btn_stop,
        remove: D.t_confirm_model_remove,
        license: D.t_license,
        noncommercial: D.t_noncommercial,
        none: D.t_no_records
    };
    window.ailLoadHf = function (refresh) {
        const q = (document.getElementById('ail-hf-query')?.value || '').trim();
        const get = (url) => fetch(url, { credentials: 'same-origin' }).then(r => r.json()).catch(() => null);
        const jobs = [get('/api/ai_models.php?action=hf_catalog&query=' + encodeURIComponent(q) + (refresh ? '&refresh=1' : ''))
            .then(res => { hfItems = (res && res.success) ? (res.models || []) : []; })];
        if (piperVoices === null || refresh) {
            jobs.push(get('/api/ai_models.php?action=piper_catalog').then(res => { piperVoices = (res && res.success) ? (res.voices || []) : []; }));
        }
        Promise.all(jobs).then(ailRenderHf);
    };

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

    window.ailRenderHf = function () {
        const tbody = document.getElementById('ail-hf-tbody');
        if (!tbody) return;
        const kind = (document.getElementById('ail-hf-kind')?.value || '').trim();
        const q = (document.getElementById('ail-hf-query')?.value || '').trim().toLowerCase();
        const match = (...texts) => !q || texts.some(t => String(t || '').toLowerCase().includes(q));
        const stateBadge = (st) => {
            const cls = { ready: 'badge-success', installed: 'badge-success', downloading: 'badge-info', loading: 'badge-info', error: 'badge-danger' }[st] || 'badge-secondary';
            return `<span class="badge ${cls} u-fs-10">${esc(st)}</span>`;
        };
        const rows = [];
        // 1. Models the service knows: built-in (recommended) and ones added before. Ollama models are on their own tab.
        for (const m of localModels) {
            if (!['tts', 'stt', 'embedding'].includes(m.kind) || String(m.id || '').startsWith('ollama-')) continue;
            if (kind && m.kind !== kind) continue;
            if (!match(m.title, m.id, (m.languages || []).join(' '))) continue;
            const st = m.state || 'absent';
            const licUrl = safeHttpUrl(m.license_url);
            const sub = esc(HF_TXT.license) + ': ' + (licUrl ? `<a href="${esc(licUrl)}" target="_blank" rel="noopener">${esc(m.license || '?')}</a>` : esc(m.license || '?'))
                + (m.commercial === false ? ' · ' + esc(HF_TXT.noncommercial) : '');
            const hfOrder = st === 'ready' || st === 'loading' ? 0
                : (st === 'installed' || st === 'downloading' ? 1 : (st === 'error' ? 2 : 3));
            rows.push({ order: hfOrder, html: `<tr>
                <td><strong>${esc(m.title || m.id)}</strong>${m.custom ? '' : ` <span class="badge badge-info u-fs-10">${esc(HF_TXT.recommended)}</span>`}<div class="u-fs-11 u-muted">${sub}</div></td>
                <td class="u-fs-12">${esc(String(m.kind).toUpperCase())}</td>
                <td class="u-fs-12">${esc((m.languages || []).join(', '))}</td>
                <td class="u-fs-12 u-mono">${esc((st === 'absent' ? m.download_mb : (m.disk_mb || m.download_mb)) + ' MB')}</td>
                <td>${stateBadge(st)}${st === 'downloading' && m.progress ? ' ' + esc(m.progress + '%') : ''}</td>
                <td class="u-text-right u-nowrap">${ailModelActions(m)}</td></tr>` });
        }
        // 2. Piper voices not added yet (TTS).
        if (!kind || kind === 'tts') {
            for (const v of piperVoices || []) {
                if (v.registered || !match(v.name, v.key, v.language, v.language_name, v.country)) continue;
                const cardUrl = safeHttpUrl(v.card_url);
                rows.push({ order: 4, html: `<tr>
                    <td><strong>Piper ${esc(v.name)}</strong> <span class="u-fs-11 u-muted">${esc(v.quality)}</span>${cardUrl ? `<div class="u-fs-11"><a href="${esc(cardUrl)}" target="_blank" rel="noopener">${esc(HF_TXT.license)}</a></div>` : ''}</td>
                    <td class="u-fs-12">TTS</td>
                    <td class="u-fs-12">${esc(v.language_name)}${v.country ? ' (' + esc(v.country) + ')' : ''}</td>
                    <td class="u-fs-12 u-mono">${esc(v.size_mb + ' MB')}</td>
                    <td>${stateBadge('absent')}</td>
                    <td class="u-text-right"><button type="button" class="btn btn-primary btn-xs" data-ail-action="add_piper" data-key="${esc(v.key)}"><i class="fas fa-download"></i> ${esc(HF_TXT.download)}</button></td></tr>` });
            }
        }
        // 3. Hub results of runnable formats (Whisper CTranslate2, ONNX sentence models), not added yet.
        const added = new Set(localModels.map(m => String(m.title || '')));
        for (const m of hfItems) {
            const repo = m.repo || m.id || '';
            if (!repo || added.has(repo) || (kind && m.kind !== kind)) continue;
            rows.push({ order: 5, html: `<tr>
                <td><strong>${esc(repo)}</strong><div class="u-fs-11 u-muted">Hugging Face · ${esc(m.license || '?')} · ↓${esc(m.downloads ?? 0)}</div></td>
                <td class="u-fs-12">${esc(String(m.kind || '').toUpperCase())}</td>
                <td class="u-fs-12">-</td>
                <td class="u-fs-12 u-mono">${esc(m.size_label || '-')}</td>
                <td>${stateBadge('absent')}</td>
                <td class="u-text-right"><button type="button" class="btn btn-primary btn-xs" data-ail-action="hf_add" data-repo="${esc(repo)}"><i class="fas fa-download"></i> ${esc(HF_TXT.download)}</button></td></tr>` });
        }
        rows.sort((x, y) => x.order - y.order);
        tbody.innerHTML = rows.length ? rows.map(r => r.html).join('') : emptyRowHtml(6, HF_TXT.none);
        ailReenhanceTable('ail-hf-table');
    };

    const olTbody = document.getElementById('ail-ol-tbody');
    if (olTbody) {
        olTbody.addEventListener('focusin', (e) => {
            const sel = e.target.closest('select[data-ail-ol-select]');
            if (sel) ailFetchOllamaTags(sel.dataset.name || '', Number(sel.dataset.idx));
        });
        olTbody.addEventListener('change', (e) => {
            const sel = e.target.closest('select[data-ail-ol-select]');
            if (sel) ailOnOllamaTagChange(sel.dataset.name || '', Number(sel.dataset.idx));
        });
        olTbody.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-ail-action]');
            if (!btn) return;
            const act = btn.dataset.ailAction;
            if (act === 'ollama_pull') ailPullOllama(btn.dataset.name || '', btn.dataset.sel || '');
            else if (act === 'ollama_run') ailApi('model_run', { id: btn.dataset.id || '' }).then(() => ailLoadOllama(false));
            else if (act === 'ollama_stop') ailApi('model_stop', { id: btn.dataset.id || '' }).then(() => ailLoadOllama(false));
            else if (act === 'ollama_remove' && confirm('Remove?')) ailApi('model_remove', { id: btn.dataset.id || '' }).then(() => ailLoadOllama(false));
        });
    }

    const hfTbody = document.getElementById('ail-hf-tbody');
    if (hfTbody) {
        hfTbody.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-ail-action]');
            if (!btn) return;
            const act = btn.dataset.ailAction;
            if (act === 'model_install') ailApi('model_install', { id: btn.dataset.id || '', accept_license: 1 });
            else if (act === 'model_cancel') ailApi('model_cancel', { id: btn.dataset.id || '' });
            else if (act === 'model_run') ailApi('model_run', { id: btn.dataset.id || '' });
            else if (act === 'model_stop') ailApi('model_stop', { id: btn.dataset.id || '' });
            else if (act === 'model_remove' && confirm(HF_TXT.remove)) ailApi('model_remove', { id: btn.dataset.id || '' });
            else if (act === 'add_piper') ailApi('add_piper', { key: btn.dataset.key || '', accept_license: 1 });
            else if (act === 'hf_add') ailApi('hf_add', { repo: btn.dataset.repo || '', accept_license: 1 });
        });
    }

    ailLoadOllama(false);
    ailLoadHf(false);
})();
