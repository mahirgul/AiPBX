/* Admin → E-Mail → Templates: editor toolbar, value chips, live preview, save/reset/test. */
(function () {
    const root = document.getElementById('mailTplEditor');
    if (!root || root.dataset.ready) return;
    root.dataset.ready = '1';

    const key = root.dataset.template;
    const csrf = root.dataset.csrf;
    const subject = document.getElementById('mailTplSubject');
    const body = document.getElementById('mailTplBody');
    const langSel = document.getElementById('mailTplLang');
    const frame = document.getElementById('mailTplPreview');
    const previewSubject = document.getElementById('mailTplPreviewSubject');
    const state = document.getElementById('mailTplState');
    const unsaved = document.getElementById('mailTplUnsaved');
    let saved = subject.value + '\u0000' + body.value;

    const toast = (msg, ok) => {
        if (window.showFooterToast) window.showFooterToast(msg, ok ? 'success' : 'error');
        else alert(msg);
    };

    function post(action, extra) {
        const fd = new FormData();
        fd.append('ajax', action);
        fd.append('csrf_token', csrf);
        fd.append('template', key);
        fd.append('lang', langSel.value);
        fd.append('subject', subject.value);
        fd.append('body', body.value);
        Object.entries(extra || {}).forEach(([k, v]) => fd.append(k, v));
        return fetch('/mail-templates', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json());
    }

    function markDirty() {
        unsaved.style.display = (subject.value + '\u0000' + body.value) === saved ? 'none' : '';
    }

    let timer = null;
    function preview() {
        clearTimeout(timer);
        timer = setTimeout(() => {
            post('preview').then(d => {
                if (!d.success) return;
                previewSubject.textContent = d.subject;
                frame.srcdoc = d.html;
            }).catch(() => {});
        }, 350);
    }

    function setState(custom) {
        state.textContent = custom ? state.dataset.custom : state.dataset.builtin;
        state.className = 'badge u-fs-11 ' + (custom ? 'badge-info' : 'badge-secondary');
    }

    // Toolbar: wrap the selection in a tag, make a list, add a link.
    function replaceSelection(fn) {
        const s = body.selectionStart, e = body.selectionEnd;
        const sel = body.value.slice(s, e);
        const out = fn(sel);
        body.setRangeText(out, s, e, 'end');
        body.focus();
        markDirty();
        preview();
    }
    root.querySelectorAll('[data-wrap]').forEach(b => b.addEventListener('click', () => {
        const tag = b.dataset.wrap;
        replaceSelection(sel => `<${tag}>${sel}</${tag}>`);
    }));
    root.querySelector('[data-list]').addEventListener('click', () => {
        replaceSelection(sel => {
            const lines = (sel || '').split(/\n/).filter(l => l.trim() !== '');
            return '<ul>' + (lines.length ? lines : ['']).map(l => `<li>${l.trim()}</li>`).join('') + '</ul>';
        });
    });
    const linkBtn = root.querySelector('[data-link]');
    linkBtn.addEventListener('click', () => {
        const url = prompt(linkBtn.dataset.prompt, 'https://');
        if (!url) return;
        const safe = url.replace(/"/g, '');
        replaceSelection(sel => `<a href="${safe}">${sel || safe}</a>`);
    });

    // Value chips insert {name} at the cursor (in the subject when it has the focus).
    let lastField = body;
    [subject, body].forEach(f => f.addEventListener('focus', () => { lastField = f; }));
    root.querySelectorAll('.mail-tpl-var').forEach(b => b.addEventListener('mousedown', ev => {
        ev.preventDefault(); // keep the focus/selection of the field
        const f = lastField;
        const text = '{' + b.dataset.var + '}';
        f.setRangeText(text, f.selectionStart, f.selectionEnd, 'end');
        f.focus();
        markDirty();
        preview();
    }));

    [subject, body].forEach(f => f.addEventListener('input', () => { markDirty(); preview(); }));

    langSel.addEventListener('change', () => {
        location.href = '/mail-templates?t=' + encodeURIComponent(key) + '&lang=' + encodeURIComponent(langSel.value);
    });

    document.getElementById('mailTplSave').addEventListener('click', () => {
        post('save').then(d => {
            toast(d.success ? d.message : d.error, d.success);
            if (d.success) {
                // The server removes markup that is not allowed: show what was stored.
                subject.value = d.subject;
                body.value = d.body;
                saved = subject.value + '\u0000' + body.value;
                setState(true);
                markDirty();
                preview();
            }
        });
    });

    const resetBtn = document.getElementById('mailTplReset');
    resetBtn.addEventListener('click', () => {
        if (!confirm(resetBtn.dataset.confirm)) return;
        post('reset').then(d => {
            toast(d.success ? d.message : d.error, d.success);
            if (d.success) {
                subject.value = d.subject;
                body.value = d.body;
                saved = subject.value + '\u0000' + body.value;
                setState(false);
                markDirty();
                preview();
            }
        });
    });

    document.getElementById('mailTplTest').addEventListener('click', () => {
        const to = document.getElementById('mailTplTestTo').value.trim();
        post('test', { to }).then(d => toast(d.success ? d.message : d.error, d.success));
    });

    preview();
})();
