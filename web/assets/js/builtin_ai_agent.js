// AI Agent page: "Clear voice cache" (templates/views/builtin_ai_agent/index.php).
(function () {
    const btn = document.getElementById('builtinAgentClearCache');
    if (!btn) return;
    btn.addEventListener('click', function () {
        if (!confirm(btn.dataset.confirm || '')) return;
        const body = new URLSearchParams({ action: 'tts_cache_clear', csrf_token: btn.dataset.csrf || '' });
        fetch('/api/ai_models.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                const ok = res && res.success;
                const msg = ok ? res.message : ((res && res.error) || 'Error');
                if (window.showFooterToast) window.showFooterToast(msg, ok ? 'success' : 'danger'); else alert(msg);
            })
            .catch(function () { if (window.showFooterToast) window.showFooterToast('Error', 'danger'); });
    });
})();

// The agent form: Realtime or Cascade fields, the instruction tools (palette + live "tools found"), the token counter.
(function () {
    const form = document.getElementById('builtinAgentForm');
    if (!form) return;
    const P = JSON.parse(form.dataset.palette || '{}');
    const ta = document.getElementById('builtin_agent_instructions');
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

    function showMode() {
        const checked = form.querySelector('[data-agent-mode]:checked');
        const mode = checked ? checked.value : 'cascade';
        form.querySelectorAll('[data-agent-pane]').forEach(function (pane) { pane.hidden = pane.dataset.agentPane !== mode; });
    }
    form.querySelectorAll('[data-agent-mode]').forEach(r => r.addEventListener('change', showMode));
    showMode();

    // ---- tool brackets: the same rules as AgentInstructions.php (the server checks again on save)
    function tokens(s) {
        const out = [];
        for (let i = 0; i < s.length; i++) {
            if (s[i] !== '{' || (i > 0 && s[i - 1] === '\\')) continue;
            let depth = 0, j = i;
            for (; j < s.length; j++) {
                if (s[j] === '{' && (j === 0 || s[j - 1] !== '\\')) depth++;
                else if (s[j] === '}') depth--;
                if (depth === 0) break;
            }
            if (j >= s.length) break;
            const parts = s.slice(i + 1, j).split(',').map(x => x.trim());
            const word = parts[0].toLowerCase();
            if (parts.length > 1 && (P.words || []).includes(word)) { out.push({ start: i, word, parts, inner: s.slice(i + 1, j) }); i = j; }
        }
        return out;
    }
    function resolveTarget(t) {
        const T = P.targets || {};
        const m = /^(queue|group|extension):(.+)$/.exec(t);
        if (m) {
            const type = { queue: 'queue', group: 'ring_group', extension: 'extension' }[m[1]];
            return T[type] && T[type][m[2]] !== undefined ? { name: T[type][m[2]] } : { err: 'unknown' };
        }
        const hits = ['extension', 'queue', 'ring_group'].filter(type => T[type] && T[type][t] !== undefined);
        if (hits.length > 1) return { err: 'ambiguous' };
        return hits.length ? { name: T[hits[0]][t] } : { err: 'unknown' };
    }
    const found = document.getElementById('agentFound');
    function check() {
        if (!ta || !found) return;
        const text = ta.value, rows = [], names = {};
        const nameOk = n => /^[a-z][a-z0-9_]{0,29}$/.test(n) ? ((P.reserved || []).includes(n) ? 'reserved' : (names[n] ? 'format' : '')) : 'name';
        const once = {};
        tokens(text).forEach(tk => {
            const line = text.slice(0, tk.start).split('\n').length, p = tk.parts;
            const rest = n => tk.inner.split(',').slice(n).join(',').trim();   // the text after the n-th comma, as written
            let ok = '', err = '';
            if (['barge_in', 'announcements', 'email', 'language'].includes(tk.word) && once[tk.word]) { rows.push({ line, word: tk.word, ok: '', err: 'format' }); return; }
            once[tk.word] = 1;
            switch (tk.word) {
                case 'transfer': {
                    if (p.length !== 3) { err = 'format'; break; }
                    err = nameOk(p[1]); if (err) break;
                    names[p[1]] = 1;
                    const r = resolveTarget(p[2]);
                    if (r.err) err = r.err; else ok = p[1] + ' → ' + r.name;
                    break;
                }
                case 'http':
                    if (p.length < 3 || !/^https?:\/\//.test(rest(2))) { err = 'format'; break; }
                    err = nameOk(p[1]); if (!err) { names[p[1]] = 1; ok = p[1] + ' → ' + rest(2); }
                    break;
                case 'greeting':
                    if (p.length < 4 || !/^\d+$/.test(p[1])) { err = 'format'; break; }
                    if (!(P.languages || []).includes(p[2].toLowerCase())) { err = 'language'; break; }
                    ok = p[2].toLowerCase() + ' ' + p[1] + ': ' + rest(3);
                    break;
                case 'waiting': ok = rest(1); break;
                case 'db': if (P.queries && P.queries[p[1]] !== undefined) ok = p[1] + ' — ' + P.queries[p[1]]; else err = 'unknown-item'; break;
                case 'knowledge': {
                    const want = rest(1).toLowerCase();
                    const hit = Object.keys(P.bases || {}).find(n => n.trim().toLowerCase() === want);
                    if (hit) ok = hit + ' — ' + P.bases[hit]; else err = 'unknown-item';
                    break;
                }
                case 'pbx': if ((P.pbx || []).includes(p[1])) ok = p[1]; else err = 'unknown-item'; break;
                case 'language':
                    if (p.length === 2 && (P.languages || []).includes(p[1].toLowerCase())) ok = p[1].toLowerCase(); else err = 'language';
                    break;
                case 'barge_in': case 'announcements':
                    if (p.length === 2 && ['on', 'off'].includes(p[1].toLowerCase())) ok = p[1].toLowerCase(); else err = 'format';
                    break;
                case 'email':
                    if (p.length === 2 && /^[^\s@,]+@[^\s@,]+\.[^\s@,]+$/.test(p[1])) ok = p[1]; else err = 'email';
                    break;
            }
            rows.push({ line, word: tk.word, ok, err });
        });
        const ds = found.dataset;
        const why = { unknown: ds.unknown, ambiguous: ds.ambiguous, format: ds.format, name: ds.name, reserved: ds.reserved, language: ds.language, 'unknown-item': ds.unknownItem, email: ds.email };
        found.innerHTML = '<div class="u-fs-12 u-fw-600">' + esc(ds.title) + '</div>' + (rows.length ? rows.map(r => r.err
            ? '<div class="u-fs-12 u-danger"><i class="fas fa-xmark"></i> ' + esc(ds.line.replace('%d', r.line)) + ' · <code>' + esc(r.word) + '</code> ' + esc(why[r.err] || r.err) + '</div>'
            : '<div class="u-fs-12 u-success"><i class="fas fa-check"></i> <code>' + esc(r.word) + '</code> ' + esc(r.ok) + '</div>').join('')
            : '<div class="u-fs-12 u-muted">' + esc(ds.none) + '</div>');
    }

    // ---- counter and inserting
    const count = document.getElementById('agentInstrCount');
    const warnAt = Number(form.dataset.warnTokens || 15000);
    function recount() {
        if (!ta || !count) return;
        const tokensN = Math.round(ta.value.length / 3.5);       // a rough estimate: about 3.5 characters per token
        count.textContent = '≈ ' + tokensN.toLocaleString() + ' tokens';
        count.classList.toggle('u-danger', tokensN > warnAt);
        count.title = tokensN > warnAt ? (count.dataset.warn || '') : '';
    }
    let timer = null;
    function changed() { recount(); clearTimeout(timer); timer = setTimeout(check, 250); }
    function insert(token) {
        if (!ta) return;
        const focused = document.activeElement === ta;
        const start = focused ? ta.selectionStart : (ta.dataset.caret !== undefined ? Number(ta.dataset.caret) : ta.value.length);
        const end = focused ? ta.selectionEnd : start;
        ta.value = ta.value.slice(0, start) + token + ta.value.slice(end);
        const pos = start + token.length;
        ta.focus(); ta.setSelectionRange(pos, pos); ta.dataset.caret = String(pos);
        changed();
    }
    if (ta) {
        ta.addEventListener('input', changed);
        ta.addEventListener('blur', () => { ta.dataset.caret = String(ta.selectionStart); });
        recount(); check();
    }
    form.querySelectorAll('#agentInstrVars [data-insert-var]').forEach(function (chip) {
        const token = chip.getAttribute('data-insert-var') || '';
        chip.addEventListener('click', () => insert(token));
        chip.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); insert(token); } });
        chip.addEventListener('dragstart', e => { e.dataTransfer.setData('text/plain', token); e.dataTransfer.effectAllowed = 'copy'; });
    });

    // ---- the palette: one small form per tool word; Insert writes the bracket where the cursor was
    const pal = document.getElementById('agentPalette');
    let word = '';
    const slug = s => String(s || '').toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '').replace(/ı/g, 'i')
        .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^(\d)/, 't$1').slice(0, 30) || 'target';
    form.querySelectorAll('[data-palette-open]').forEach(btn => btn.addEventListener('click', function () {
        word = btn.dataset.paletteOpen;
        pal.hidden = false;
        pal.querySelectorAll('[data-palette]').forEach(d => { d.hidden = d.dataset.palette !== word; });
        const first = pal.querySelector('[data-palette="' + word + '"] input, [data-palette="' + word + '"] select');
        if (first) first.focus();
    }));
    const clearPalette = () => {
        pal.querySelectorAll('input[data-p]').forEach(inp => { inp.value = ''; });
    };
    pal.querySelector('[data-palette-close]').addEventListener('click', () => { clearPalette(); pal.hidden = true; });
    pal.querySelector('[data-palette-insert]').addEventListener('click', function () {
        const box = pal.querySelector('[data-palette="' + word + '"]');
        const v = k => (box.querySelector('[data-p="' + k + '"]') || {}).value || '';
        const sel = box.querySelector('select[data-p="target"]');
        const selLabel = (sel && sel.selectedIndex >= 0 && sel.options[sel.selectedIndex]) ? sel.options[sel.selectedIndex].text.split('·').pop() : '';
        let token = '';
        if (word === 'transfer') token = '{transfer, ' + (v('name') || slug(selLabel)) + ', ' + v('target') + '}';
        if (word === 'greeting') {
            const lang = v('lang');
            const n = tokens(ta.value).filter(t => t.word === 'greeting' && (t.parts[2] || '').toLowerCase() === lang).length + 1;
            token = '{greeting, ' + n + ', ' + lang + ', ' + (v('text') || '') + '}';
        }
        if (word === 'knowledge') token = '{knowledge, ' + v('base') + '}';
        if (word === 'db') token = '{db, ' + v('query') + '}';
        if (word === 'http') token = '{http, ' + (v('name') || 'lookup') + ', ' + (v('url') || 'https://') + '}';
        if (word === 'pbx') {
            // "all": one bracket per tool on its own line, except the ones off by default and those already written
            const have = tokens(ta.value).filter(t => t.word === 'pbx').map(t => t.parts[1]);
            const list = v('tool') === '*' ? (P.pbx || []).filter(n => !(P.pbxOff || []).includes(n) && !have.includes(n)) : [v('tool')];
            token = list.map(n => '{pbx, ' + n + '}').join('\n');
            const at = document.activeElement === ta ? ta.selectionStart : (ta.dataset.caret !== undefined ? Number(ta.dataset.caret) : ta.value.length);
            const before = ta.value.slice(0, at);
            if (list.length > 1 && before && !before.endsWith('\n')) token = '\n' + token;
        }
        if (word === 'waiting') token = '{waiting, ' + v('text') + '}';
        if (word === 'language') token = '{language, ' + v('lang') + '}';
        if (word === 'barge_in') token = '{barge_in, ' + v('on') + '}';
        if (word === 'announcements') token = '{announcements, ' + v('on') + '}';
        if (word === 'email') token = '{email, ' + (v('mail') || 'calls@example.com') + '}';
        if (token) insert(token);
        clearPalette();
        pal.hidden = true;
    });

    form.querySelectorAll('[data-agent-act]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const act = btn.dataset.agentAct;
            if (act === 'fullscreen') document.getElementById('builtinAgentInstrBox').classList.toggle('ai-editor-fs');
            if (act === 'copy' && ta && navigator.clipboard) navigator.clipboard.writeText(ta.value);
            if (act === 'reset' && ta) { ta.value = btn.dataset.default || ''; ta.focus(); changed(); }
        });
    });

    // ---- cards: closed at first; the summary shows the value; an invalid field opens its card
    form.addEventListener('invalid', e => { const card = e.target.closest('details'); if (card) card.open = true; }, true);
    const hintOf = {
        name: () => [form.elements.title.value, form.elements.internal_number.value].filter(Boolean).join(' · '),
        mode: () => { const r = form.querySelector('[data-agent-mode]:checked'); return r ? r.parentElement.textContent.trim().split('(')[0].trim() : ''; },
        instructions: () => count ? count.textContent : '',
        fallback: () => { const t = form.querySelector('#agent_dest_type'), d = form.querySelector('#agent_dest_id');
            const label = o => o && o.selectedIndex >= 0 ? o.options[o.selectedIndex].text : '';
            return [label(t), t && t.value !== 'hangup' ? label(d) : ''].filter(Boolean).join(' → '); }
    };
    function hints() { form.querySelectorAll('[data-hint]').forEach(el => { const f = hintOf[el.dataset.hint]; if (f) el.textContent = f(); }); }
    form.addEventListener('input', hints); form.addEventListener('change', hints);
    setTimeout(hints, 600);                       // after the destination list has loaded
    hints();

    // ---- one card open at a time (not after a refused save: every card is open then, so the problem is seen)
    if (form.dataset.openAll !== '1') {
        const cards = Array.from(form.querySelectorAll('details.agent-card'));
        cards.forEach(card => card.addEventListener('toggle', function () {
            if (card.open) cards.forEach(other => { if (other !== card) other.open = false; });
        }));
    }
})();
