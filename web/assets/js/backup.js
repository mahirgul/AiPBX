/* Page script of templates/views/backup/index.php */

var BK = window.BACKUP_PAGE;
var bkTimer = null;
var bkRestoreName = '';
var bkToken = '';
// A small backup is done before the first check: keep looking for a while after starting one.
var bkWatchUntil = 0;

function bkToast(msg, type) { if (window.showFooterToast) window.showFooterToast(msg, type); }
function bkCsrf() { return window.CSRF_TOKEN || ''; }

function bkPost(action, extra) {
    var body = new URLSearchParams(Object.assign({ action: action, csrf_token: bkCsrf() }, extra || {}));
    return fetch('/api/backup.php', { method: 'POST', body: body }).then(function (r) { return r.json(); });
}

function bkRowHtml(b) {
    var badges = '';
    if (b.uploaded) badges += ' <span class="badge badge-warning">' + escapeHtml(BK.text.kind_uploaded) + '</span>';
    if (b.safety) badges += ' <span class="badge badge-info">' + escapeHtml(BK.text.kind_safety) + '</span>';
    return '<tr data-name="' + escapeHtml(b.name) + '"><td><code>' + escapeHtml(b.name) + '</code>' + badges + '</td>'
        + '<td>' + escapeHtml(b.date) + '</td><td>' + escapeHtml(b.size) + '</td>'
        + '<td>' + escapeHtml(b.version ? 'v' + b.version : '—') + '</td>'
        + '<td class="u-flex-gap" style="flex-wrap: wrap;">'
        + '<button type="button" class="btn btn-secondary btn-sm" data-act="download"><i class="fas fa-download"></i> ' + escapeHtml(BK.text.btn_download) + '</button> '
        + '<button type="button" class="btn btn-warning btn-sm" data-act="restore"><i class="fas fa-rotate-left"></i> ' + escapeHtml(BK.text.btn_restore) + '</button> '
        + '<button type="button" class="btn btn-danger btn-sm" data-act="delete"><i class="fas fa-trash"></i> ' + escapeHtml(BK.text.btn_delete) + '</button>'
        + '</td></tr>';
}

function bkRender(d) {
    if (!d || !d.success) return;
    var rows = document.getElementById('bk-rows');
    if (rows) {
        rows.innerHTML = d.backups.length ? d.backups.map(bkRowHtml).join('')
            : '<tr><td colspan="5" class="u-muted">' + escapeHtml(BK.text.empty) + '</td></tr>';
    }
    BK.creating = !!d.creating;
    var btn = document.getElementById('bk-create');
    if (btn) {
        btn.disabled = BK.creating;
        document.getElementById('bk-create-label').textContent = BK.creating ? BK.text.creating : BK.text.btn_create;
    }
    if (d.status) bkShowState(d.status.state, d.status.step);
    var log = document.getElementById('bk-log');
    if (log && typeof d.log === 'string') { log.textContent = d.log; log.scrollTop = log.scrollHeight; }
    if (BK.creating || Date.now() < bkWatchUntil || (d.status && d.status.state === 'running')) bkPoll();
    else if (!bkToken) bkStopPoll();
}

function bkShowState(state, step) {
    var el = document.getElementById('bk-state');
    if (!el) return;
    var label = BK.text['state_' + state] || state;
    el.innerHTML = '<strong>' + escapeHtml(label) + '</strong> — ' + escapeHtml(step || '');
}

function bkRefresh() {
    // A restore restarts Apache and replaces the database: a failed request is normal then.
    if (bkToken) {
        return fetch('/api/restore_status.php', { headers: { 'X-Restore-Token': bkToken }, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (s) {
                if (!s.success) return;
                bkShowState(s.state, s.step);
                if (s.state !== 'running') {
                    bkStopPoll();
                    var token = bkToken;
                    bkToken = '';
                    bkAfterRestore(s, token);
                }
            }).catch(function () {});
    }
    return fetch('/api/backup.php?action=status', { cache: 'no-store' })
        .then(function (r) { return r.json(); }).then(bkRender).catch(function () {});
}
function bkPoll() { if (!bkTimer) bkTimer = setInterval(bkRefresh, 3000); }
function bkStopPoll() { if (bkTimer) { clearInterval(bkTimer); bkTimer = null; } }

function bkAfterRestore(s) {
    if (s.state === 'done') bkToast(BK.text.state_signin, 'success');
    // The session may be gone: the users are those of the backup now.
    fetch('/api/backup.php?action=status', { cache: 'no-store' })
        .then(function (r) {
            if (r.status === 401) {
                var el = document.getElementById('bk-state');
                if (el) el.insertAdjacentHTML('beforeend', '<br><a href="/login">' + escapeHtml(BK.text.state_signin) + '</a>');
                return null;
            }
            return r.json();
        }).then(function (d) { if (d) bkRender(d); }).catch(function () {});
}

function bkCreate() {
    bkPost('create').then(function (res) {
        if (!res.success) { bkToast(res.error, 'error'); return; }
        bkToast(res.message, 'success');
        BK.creating = true;
        bkWatchUntil = Date.now() + 15000;
        bkPoll();
        bkRefresh();
    });
}

function bkSaveKeep() {
    bkPost('keep', { days: document.getElementById('bk-keep').value }).then(function (res) {
        bkToast(res.success ? res.message : res.error, res.success ? 'success' : 'error');
    });
}

function bkDownload(name) {
    window.location.href = '/api/backup.php?action=download&name=' + encodeURIComponent(name) + '&csrf_token=' + encodeURIComponent(bkCsrf());
}

function bkDelete(name) {
    if (!window.confirm(BK.text.confirm_delete.replace('%s', name))) return;
    bkPost('delete', { name: name }).then(function (res) {
        bkToast(res.success ? res.message : res.error, res.success ? 'success' : 'error');
        bkRefresh();
    });
}

function bkRestoreOpen(name) {
    bkRestoreName = name;
    document.getElementById('bk-restore-title').textContent = BK.text.restore_title.replace('%s', name);
    document.getElementById('bk-confirm').value = '';
    var pw = document.getElementById('bk-password');
    if (pw) pw.value = '';
    var panel = document.getElementById('bk-restore');
    panel.style.display = '';
    panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
function bkRestoreClose() { document.getElementById('bk-restore').style.display = 'none'; bkRestoreName = ''; }

function bkRestoreGo() {
    var pw = document.getElementById('bk-password');
    var extra = {
        name: bkRestoreName,
        confirm_name: document.getElementById('bk-confirm').value,
        password: pw ? pw.value : ''
    };
    if (document.querySelector('input[name="bk-scope"]:checked').value === 'db') extra.db_only = '1';
    if (document.getElementById('bk-allow-calls').checked) extra.allow_calls = '1';
    var go = document.getElementById('bk-restore-go');
    go.disabled = true;
    bkPost('restore', extra).then(function (res) {
        go.disabled = false;
        if (!res.success) {
            bkToast(res.error, 'error');
            if (res.relogin) window.location.href = '/login';
            return;
        }
        bkToken = res.token;
        bkRestoreClose();
        bkShowState('running', '');
        bkPoll();
    }).catch(function () { go.disabled = false; });
}

function bkUpload() {
    var input = document.getElementById('bk-file');
    var state = document.getElementById('bk-upload-state');
    var file = input.files && input.files[0];
    if (!file) { bkToast(BK.text.upload_choose, 'error'); return; }
    var btn = document.getElementById('bk-upload');
    btn.disabled = true;
    var stop = function (msg) { btn.disabled = false; state.textContent = ''; bkToast(msg, 'error'); };

    bkPost('upload_start').then(function (start) {
        if (!start.success) { stop(start.error); return; }
        var size = start.chunk_bytes;
        var send = function (offset) {
            if (offset >= file.size) {
                state.textContent = BK.text.upload_checking;
                return bkPost('upload_finish', { token: start.token }).then(function (res) {
                    btn.disabled = false;
                    state.textContent = '';
                    bkToast(res.success ? res.message : res.error, res.success ? 'success' : 'error');
                    input.value = '';
                    bkRefresh();
                });
            }
            state.textContent = BK.text.upload_progress.replace('%d%%', Math.floor(offset * 100 / file.size) + '%');
            var form = new FormData();
            form.append('action', 'upload_chunk');
            form.append('csrf_token', bkCsrf());
            form.append('token', start.token);
            form.append('offset', String(offset));
            form.append('chunk', file.slice(offset, offset + size), 'chunk');
            return fetch('/api/backup.php', { method: 'POST', body: form })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) { stop(res.error); return null; }
                    return send(res.received);
                });
        };
        return send(0);
    }).catch(function () { stop(BK.text.upload_failed); });
}

(function () {
    var rows = document.getElementById('bk-rows');
    if (rows) {
        rows.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('button[data-act]') : null;
            if (!btn) return;
            var name = btn.closest('tr').getAttribute('data-name');
            if (btn.getAttribute('data-act') === 'download') bkDownload(name);
            if (btn.getAttribute('data-act') === 'restore') bkRestoreOpen(name);
            if (btn.getAttribute('data-act') === 'delete') bkDelete(name);
        });
    }
    if (BK.running || BK.creating) bkPoll();
})();
