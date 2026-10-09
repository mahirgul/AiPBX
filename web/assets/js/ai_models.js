/* Page script of templates/views/ai_models/index.php */
(function () {
    const PAGE = window.AI_MODELS_PAGE;
    if (!PAGE) return;
    const T = PAGE.text;
    let timer = null;
    let lastStatus = PAGE.status;
    let expectUntil = 0;   // after an action: keep polling until its state shows up
    const bench = {};      // id -> result text (kept across re-renders)

    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const toast = (msg, type) => { if (window.showFooterToast) window.showFooterToast(msg, type); };
    const fmt = (s, ...a) => { let i = 0; return String(s).replace(/%[sd]/g, () => a[i++]); };

    function post(action, extra) {
        const body = new URLSearchParams(Object.assign({ action: action, csrf_token: window.CSRF_TOKEN || '' }, extra || {}));
        return fetch('/api/ai_models.php', { method: 'POST', body: body }).then(r => r.json());
    }

    function badge(cls, text) { return '<span class="badge ' + cls + ' u-fs-11">' + esc(text) + '</span>'; }

    function render(s) {
        lastStatus = s;
        const rt = s.runtime || { state: 'absent' };
        const svc = s.service;
        const rtCls = { installed: 'badge-success', installing: 'badge-info', removing: 'badge-info', failed: 'badge-danger' }[rt.state] || 'badge-secondary';
        let rtHtml = badge(rtCls, T['rt_' + rt.state] || rt.state);
        if (rt.step && (rt.state === 'installing' || rt.state === 'removing')) rtHtml += ' <span class="u-muted">' + esc(rt.step) + '</span>';
        if (rt.state === 'failed' && rt.error) rtHtml += ' <span class="u-danger">' + esc(rt.error) + '</span>';
        document.getElementById('aim-runtime').innerHTML = rtHtml;

        const busy = rt.state === 'installing' || rt.state === 'removing';
        document.getElementById('aim-rt-install').style.display = (rt.state === 'absent' || rt.state === 'failed') ? '' : 'none';
        document.getElementById('aim-rt-remove').style.display = (rt.state === 'installed' || rt.state === 'failed') ? '' : 'none';
        document.getElementById('aim-rt-install').disabled = busy;

        let svcHtml = '';
        if (rt.state === 'installed') {
            if (!svc) {
                svcHtml = '<i class="fas fa-circle-notch fa-spin"></i> ' + esc(T.svc_starting);
            } else {
                const cpu = svc.cpu || {}, ram = svc.ram || {}, pr = svc.process || {};
                svcHtml = '<i class="fas fa-circle u-success" style="font-size: 9px;"></i> '
                    + esc(T.cpu) + ': ' + esc(cpu.model || '?') + ' · ' + esc(fmt(T.cores, cpu.cores || '?')) + ' · '
                    + esc(T.ram) + ': ' + esc(ram.available_mb) + ' / ' + esc(ram.total_mb) + ' MB · '
                    + esc(T.process) + ': ' + esc(pr.rss_mb) + ' MB, ' + esc(pr.cpu_percent) + '% CPU';
            }
        }
        document.getElementById('aim-service').innerHTML = svcHtml;

        const card = document.getElementById('aim-models-card');
        card.style.display = rt.state === 'installed' ? '' : 'none';
        document.getElementById('aim-add-card').style.display = (rt.state === 'installed' && svc) ? '' : 'none';
        const disk = s.disk;
        document.getElementById('aim-disk').textContent = disk ? fmt(T.disk_used, disk.used_mb, disk.limit_mb) : '';
        const models = s.models || [];
        let html = '';
        if (rt.state === 'installed' && svc && !models.length) html = '<p class="u-muted">' + esc(T.no_models) + '</p>';
        if (models.length) {
            html += '<div class="table-responsive"><table class="table"><tbody>';
            models.forEach(m => {
                const stCls = { ready: 'badge-success', installed: 'badge-secondary', downloading: 'badge-info', loading: 'badge-info', error: 'badge-danger' }[m.state] || 'badge-secondary';
                const stText = m.state === 'installed' ? T.st_stopped : (T['st_' + m.state] || m.state);
                let st = badge(stCls, stText + (m.state === 'downloading' && m.progress ? ' ' + m.progress + '%' : ''));
                if (m.state === 'ready' && m.memory_mb) st += '<div class="u-muted u-fs-11">' + esc(fmt(T.memory, m.memory_mb)) + '</div>';
                if (m.state === 'error' && m.error) st += '<div class="u-danger u-fs-11">' + esc(m.error) + '</div>';
                let actions = '';
                if (m.state === 'absent' || m.state === 'error') {
                    actions = '<label class="u-check-label u-fs-12" style="display: block; margin-bottom: 6px;"><input type="checkbox" class="u-accent" id="aim-lic-' + esc(m.id) + '"> '
                        + fmt(esc(T.accept_license), '<a href="' + esc(m.license_url) + '" target="_blank" rel="noopener">' + esc(m.license) + '</a>') + '</label>'
                        + '<button type="button" class="btn btn-primary btn-sm" data-act="install" data-id="' + esc(m.id) + '"><i class="fas fa-download"></i> ' + esc(T.btn_download) + ' (' + esc(m.download_mb) + ' MB)</button>';
                }
                if (m.state === 'ready') {
                    actions += '<button type="button" class="btn btn-secondary btn-sm" data-act="benchmark" data-id="' + esc(m.id) + '"><i class="fas fa-gauge-high"></i> ' + esc(T.btn_benchmark) + '</button> ';
                    actions += '<button type="button" class="btn btn-secondary btn-sm" data-act="stop" data-id="' + esc(m.id) + '"><i class="fas fa-stop"></i> ' + esc(T.btn_stop) + '</button> ';
                }
                if (m.state === 'installed') {
                    actions += '<button type="button" class="btn btn-primary btn-sm" data-act="run" data-id="' + esc(m.id) + '"><i class="fas fa-play"></i> ' + esc(T.btn_run) + '</button> ';
                }
                if (m.state === 'ready' || m.state === 'installed' || m.state === 'error') {
                    actions += '<button type="button" class="btn btn-danger btn-sm" data-act="remove" data-id="' + esc(m.id) + '"><i class="fas fa-trash-alt"></i> ' + esc(T.btn_remove) + '</button>';
                }
                html += '<tr><td style="min-width: 220px;"><div class="u-strong">' + esc(m.title) + '</div>'
                    + '<div class="u-muted u-fs-11">' + esc(T['kind_' + m.kind] || m.kind) + ' · ' + esc((m.languages || []).join(', ')) + (m.gender ? ' · ' + esc(T['gender_' + m.gender] || m.gender) : '') + ' · '
                    + esc(T.license) + ': <a href="' + esc(m.license_url) + '" target="_blank" rel="noopener">' + esc(m.license) + '</a>'
                    + (m.homepage ? ' · <a href="' + esc(m.homepage) + '" target="_blank" rel="noopener">' + esc(T.homepage) + '</a>' : '')
                    + (m.disk_mb ? ' · ' + esc(m.disk_mb) + ' MB' : '') + '</div>'
                    + (m.measured && m.measured.realtime_factor ? '<div class="u-muted u-fs-11"><i class="fas fa-gauge-high"></i> ' + esc(fmt(T.measured, m.measured.realtime_factor, m.measured.memory_mb || '?')) + '</div>' : '')
                    + (m.commercial === false ? '<div class="u-danger u-fs-11"><i class="fas fa-triangle-exclamation"></i> ' + esc(T.noncommercial) + (m.note ? ' ' + esc(m.note) : '') + '</div>'
                        : (m.note ? '<div class="u-muted u-fs-11"><i class="fas fa-circle-info"></i> ' + esc(m.note) + '</div>' : ''))
                    + '</td>'
                    + '<td>' + st + '</td>'
                    + '<td style="text-align: right;">' + actions + (bench[m.id] ? '<div class="u-fs-12 u-mt-6">' + bench[m.id] + '</div>' : '') + '</td></tr>';
            });
            html += '</tbody></table></div>';
        }
        document.getElementById('aim-models').innerHTML = html;
        fillTry(models.filter(m => m.kind === 'tts' && m.state === 'ready'));

        // The runtime setup starts in its own unit and writes its state a moment
        // later; a model download may also take a moment to show up.
        const moving = busy || Date.now() < expectUntil
            || (rt.state === 'installed' && (!svc || models.some(m => ['downloading', 'loading'].includes(m.state))));
        clearTimeout(timer);
        if (moving) timer = setTimeout(refresh, 3000);
    }

    function fillTry(voices) {
        const card = document.getElementById('aim-try-card');
        const sel = document.getElementById('aim-try-model');
        card.style.display = voices.length ? '' : 'none';
        const keep = sel.value;
        const ids = voices.map(v => v.id).join(',');
        if (sel.dataset.ids === ids) return;
        sel.dataset.ids = ids;
        sel.innerHTML = voices.map(v => '<option value="' + esc(v.id) + '">' + esc(v.title) + ' (' + esc((v.languages || []).join(', ')) + ')</option>').join('');
        if (ids.split(',').includes(keep)) sel.value = keep;
    }

    document.getElementById('aim-try-go').addEventListener('click', () => {
        const btn = document.getElementById('aim-try-go');
        const sel = document.getElementById('aim-try-model');
        const text = document.getElementById('aim-try-text').value.trim();
        if (!sel.value || !text) return;
        btn.disabled = true;
        const title = sel.selectedOptions[0].text;
        const body = new URLSearchParams({ action: 'try', id: sel.value, text: text, speed: document.getElementById('aim-try-speed').value, csrf_token: window.CSRF_TOKEN || '' });
        fetch('/api/ai_models.php', { method: 'POST', body: body }).then(r => {
            const ms = r.headers.get('X-Synthesis-Ms');
            if ((r.headers.get('Content-Type') || '').startsWith('audio/')) return r.blob().then(b => ({ b: b, ms: ms }));
            return r.json().then(d => { throw new Error((d && d.error) || T.msg_try_error); });
        }).then(({ b, ms }) => {
            const url = URL.createObjectURL(b);
            const row = document.createElement('div');
            row.className = 'u-flex-gap u-mt-6';
            row.style.alignItems = 'center';
            row.innerHTML = '<audio controls src="' + url + '" style="height: 32px;"></audio><span class="u-fs-12">' + esc(fmt(T.try_result, title, ms)) + '</span>';
            const box = document.getElementById('aim-try-results');
            box.prepend(row);
            row.querySelector('audio').play().catch(() => {});
            while (box.children.length > 6) box.lastChild.remove();
        }).catch(e => toast(e.message, 'error')).finally(() => { btn.disabled = false; });
    });

    // ---- adding voices from the Piper voice list -------------------------
    let catalog = null;

    function renderCatalog() {
        const list = document.getElementById('aim-add-list');
        if (!catalog) return;
        const lang = document.getElementById('aim-add-lang').value;
        const q = document.getElementById('aim-add-quality').value;
        const rows = catalog.filter(v => (!lang || v.language === lang) && (!q || v.quality === q));
        if (!rows.length) { list.innerHTML = '<p class="u-muted">' + esc(T.add_none) + '</p>'; return; }
        list.innerHTML = '<div class="table-responsive"><table class="table"><tbody>' + rows.map(v => {
            const act = v.registered
                ? '<span class="badge badge-secondary u-fs-11">' + esc(T.add_in_list) + '</span>'
                : '<label class="u-check-label u-fs-12" style="display: block; margin-bottom: 6px;"><input type="checkbox" class="u-accent" data-lic="' + esc(v.key) + '"> '
                  + fmt(esc(T.accept_license), '<a href="' + esc(v.card_url) + '" target="_blank" rel="noopener">' + esc(T.add_card) + '</a>') + '</label>'
                  + '<button type="button" class="btn btn-primary btn-sm" data-add="' + esc(v.key) + '"><i class="fas fa-download"></i> ' + esc(T.add_btn) + ' (' + esc(v.size_mb) + ' MB)</button>';
            return '<tr><td><div class="u-strong">' + esc(v.name) + ' <span class="u-muted u-fs-11">' + esc(v.quality) + '</span></div>'
                + '<div class="u-muted u-fs-11">' + esc(v.language_name) + ' (' + esc(v.country) + ') · ' + esc(v.language)
                + (v.speakers > 1 ? ' · ' + esc(fmt(T.add_speakers, v.speakers)) : '') + '</div></td>'
                + '<td style="text-align: right;">' + act + '</td></tr>';
        }).join('') + '</tbody></table></div>';
    }

    document.getElementById('aim-add-open').addEventListener('click', () => {
        const body = document.getElementById('aim-add-body');
        body.style.display = body.style.display === 'none' ? '' : 'none';
        if (catalog || body.style.display === 'none') return;
        document.getElementById('aim-add-list').innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> ' + esc(T.add_loading);
        fetch('/api/ai_models.php?action=piper_catalog', { cache: 'no-store' }).then(r => r.json()).then(d => {
            if (!d.success) { document.getElementById('aim-add-list').innerHTML = '<span class="u-danger">' + esc(d.error) + '</span>'; return; }
            catalog = d.voices;
            const langs = {};
            catalog.forEach(v => { langs[v.language] = v.language_name + ' (' + v.country + ')'; });
            const sel = document.getElementById('aim-add-lang');
            const pageLang = (document.documentElement.lang || 'en').slice(0, 2);
            sel.innerHTML = '<option value="">' + esc(T.add_all_languages) + '</option>' + Object.keys(langs).sort((a, b) => langs[a].localeCompare(langs[b]))
                .map(c => '<option value="' + esc(c) + '">' + esc(langs[c]) + '</option>').join('');
            const guess = Object.keys(langs).find(c => c.startsWith(pageLang + '_'));
            if (guess) sel.value = guess;
            renderCatalog();
        });
    });
    document.getElementById('aim-add-lang').addEventListener('change', renderCatalog);
    document.getElementById('aim-add-quality').addEventListener('change', renderCatalog);
    document.getElementById('aim-add-list').addEventListener('click', e => {
        const btn = e.target.closest('button[data-add]');
        if (!btn) return;
        const key = btn.dataset.add;
        const lic = document.querySelector('input[data-lic="' + CSS.escape(key) + '"]');
        if (!lic || !lic.checked) { toast(T.need_license, 'error'); return; }
        btn.disabled = true;
        post('add_piper', { key: key, accept_license: 1 }).then(d => {
            if (d && d.success) { const v = catalog.find(x => x.key === key); if (v) { v.registered = true; v.model_id = d.id; } renderCatalog(); }
            done(d);
        });
    });

    function refresh() {
        fetch('/api/ai_models.php?action=status', { cache: 'no-store' }).then(r => r.json()).then(d => { if (d && d.success) render(d); }).catch(() => { timer = setTimeout(refresh, 5000); });
    }

    function done(d) {
        if (!d || !d.success) { toast((d && d.error) || 'Error', 'error'); } else if (d.message) { toast(d.message, 'success'); }
        if (d && d.success) expectUntil = Date.now() + 30000;
        refresh();
    }

    window.aimRuntime = function (action) {
        if (!confirm(action === 'runtime_install' ? T.confirm_runtime_install : T.confirm_runtime_remove)) return;
        post(action).then(done);
    };

    document.getElementById('aim-models').addEventListener('click', e => {
        const btn = e.target.closest('button[data-act]');
        if (!btn) return;
        const id = btn.dataset.id;
        if (btn.dataset.act === 'install') {
            const lic = document.getElementById('aim-lic-' + id);
            if (!lic || !lic.checked) { toast(T.need_license, 'error'); return; }
            btn.disabled = true;
            post('model_install', { id: id, accept_license: 1 }).then(done);
        } else if (btn.dataset.act === 'run' || btn.dataset.act === 'stop') {
            btn.disabled = true;
            post('model_' + btn.dataset.act, { id: id }).then(done);
        } else if (btn.dataset.act === 'remove') {
            if (!confirm(T.confirm_model_remove)) return;
            btn.disabled = true;
            post('model_remove', { id: id }).then(done);
        } else if (btn.dataset.act === 'benchmark') {
            btn.disabled = true;
            bench[id] = '<i class="fas fa-circle-notch fa-spin"></i> ' + esc(T.measuring);
            render(lastStatus);
            post('benchmark', { id: id }).then(d => {
                if (d && d.success) {
                    const b = d.benchmark;
                    bench[id] = esc(fmt(T.bench_result, b.realtime_factor, b.first_audio_ms, b.audio_seconds, b.seconds))
                        + '<div class="' + (b.realtime_factor >= 2 ? 'u-success' : 'u-warning') + '">' + esc(b.realtime_factor >= 2 ? T.bench_live_ok : T.bench_live_slow) + '</div>';
                } else {
                    bench[id] = '<span class="u-danger">' + esc((d && d.error) || 'Error') + '</span>';
                }
                render(lastStatus);
            });
        }
    });

    render(PAGE.status);
})();
