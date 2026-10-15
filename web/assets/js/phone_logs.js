/* Page script of templates/views/phone_logs/index.php: reads the phone log
 * (POST /phone-logs action=read) and follows it every 3 s from the cursor. */
(function () {
    const root = document.getElementById('phoneLogs');
    if (!root) return;
    const labels = JSON.parse(root.dataset.labels || '{}');
    const who = document.getElementById('plWho');
    const text = document.getElementById('plText');
    const level = document.getElementById('plLevel');
    const live = document.getElementById('plLive');
    const rows = document.getElementById('plRows');
    const box = document.getElementById('plBox');
    const empty = document.getElementById('plEmpty');
    const count = document.getElementById('plCount');
    const LEVELS = { emerg: 0, alert: 1, crit: 2, err: 3, error: 3, warning: 4, warn: 4, notice: 5, info: 6, debug: 7 };
    const COLORS = { 0: 'var(--danger, #dc2626)', 1: 'var(--danger, #dc2626)', 2: 'var(--danger, #dc2626)', 3: 'var(--danger, #dc2626)', 4: 'var(--warning, #d97706)' };
    const MAX_ROWS = 3000;
    let cursor = null;
    let busy = false;
    let generation = 0;

    const fmtMac = m => m ? m.toUpperCase().match(/../g).join(':') : '';
    const cell = (txt, style) => {
        const td = document.createElement('td');
        td.textContent = txt;
        td.style.cssText = 'padding: 2px 8px; border: 0; white-space: nowrap; vertical-align: top;' + (style || '');
        return td;
    };

    function add(lines) {
        const atBottom = box.scrollTop + box.clientHeight >= box.scrollHeight - 30;
        const max = +level.value;
        for (const l of lines) {
            const sev = LEVELS[l.severity] ?? 6;
            if (sev > max) continue;
            const tr = document.createElement('tr');
            const color = COLORS[sev] ? 'color:' + COLORS[sev] + ';' : '';
            const phone = l.mac ? (labels[l.mac] ? labels[l.mac] + ' · ' : '') + fmtMac(l.mac) : '';
            tr.append(cell(l.time.replace('T', ' ').slice(0, 19)), cell(l.ip), cell(phone, 'opacity:.75;'),
                cell(l.severity, color), cell(l.message, 'white-space: pre-wrap; word-break: break-all;' + color));
            rows.append(tr);
        }
        while (rows.children.length > MAX_ROWS) rows.firstChild.remove();
        empty.style.display = rows.children.length ? 'none' : '';
        count.textContent = rows.children.length ? __('js.phonelogs.lines').replace('%d', rows.children.length) : '';
        if (atBottom) box.scrollTop = box.scrollHeight;
    }

    function load(reset) {
        if (busy && !reset) return;
        if (reset) {
            generation++;
            cursor = null;
            rows.innerHTML = '';
        }
        const gen = generation;
        busy = true;
        const form = new FormData();
        form.append('action', 'read');
        form.append('csrf_token', window.CSRF_TOKEN);
        form.append('who', who.value.trim());
        form.append('text', text.value.trim());
        if (cursor) {
            form.append('ino', cursor.ino);
            form.append('pos', cursor.pos);
        }
        fetch('/phone-logs', { method: 'POST', body: form })
            .then(r => r.json())
            .then(d => {
                if (gen !== generation) return;
                if (!d.success) throw new Error(d.message || '');
                cursor = d.cursor;
                add(d.lines);
                if (reset) box.scrollTop = box.scrollHeight;
            })
            .catch(e => { if (reset) { empty.style.display = ''; empty.textContent = e.message || __('js.phonelogs.failed'); } })
            .finally(() => { if (gen === generation) busy = false; });
    }

    let timer = null;
    const refilter = () => { clearTimeout(timer); timer = setTimeout(() => load(true), 400); };
    who.addEventListener('input', refilter);
    text.addEventListener('input', refilter);
    level.addEventListener('change', () => load(true));
    document.getElementById('plClear').addEventListener('click', () => { rows.innerHTML = ''; add([]); });
    setInterval(() => { if (live.checked && !document.hidden && cursor) load(false); }, 3000);
    load(true);
})();
