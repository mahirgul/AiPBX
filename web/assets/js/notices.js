/**
 * Top bar bell (administrators): open problems and recent changes from
 * /api/notices.php. The badge shows how many problems are open (red when one
 * has failed); a dot marks changes since this user last opened the bell.
 * Refreshed every minute while the tab is visible, and on every opening.
 */
(function () {
    'use strict';
    var REFRESH_MS = 60000;
    var timer = null;

    function $(id) { return document.getElementById(id); }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null && text !== '') n.textContent = text;
        return n;
    }

    function paintBadge(data) {
        var btn = $('header-notices-btn'), count = $('header-notices-count');
        if (!btn || !count) return;
        count.textContent = String(data.count);
        count.hidden = data.count === 0;
        count.className = 'header-notices-count' + (data.worst === 'fail' ? ' is-fail' : '');
        btn.classList.toggle('has-new', data.new > 0);
    }

    function row(item, recent) {
        var sev = item.severity === 'ok' ? 'ok' : (item.severity === 'fail' ? 'fail' : 'warn');
        var r = el(item.link ? 'a' : 'div', 'header-notices-item is-' + sev);
        if (item.link) { r.href = item.link; r.addEventListener('click', closeNotices); }
        var icon = sev === 'ok' ? 'fa-circle-check' : (sev === 'fail' ? 'fa-circle-xmark' : 'fa-triangle-exclamation');
        r.appendChild(el('i', 'fas ' + icon + ' header-notices-icon'));
        var text = el('div', 'header-notices-text');
        text.appendChild(el('div', 'header-notices-item-title', item.title));
        var msg = recent && sev === 'ok' ? __('js.notices.fixed') : item.message;
        if (msg) text.appendChild(el('div', 'header-notices-msg', msg));
        if (item.since) text.appendChild(el('div', 'header-notices-time', recent ? item.since : __('js.notices.since', item.since)));
        r.appendChild(text);
        return r;
    }

    function paintList(data) {
        var list = $('header-notices-list');
        if (!list) return;
        list.innerHTML = '';
        if (!data.open.length) {
            list.appendChild(el('div', 'header-notices-empty', __('js.notices.all_good')));
        } else {
            list.appendChild(el('div', 'header-notices-section', __('js.notices.open_title')));
            data.open.forEach(function (i) { list.appendChild(row(i, false)); });
        }
        if (data.recent.length) {
            list.appendChild(el('div', 'header-notices-section', __('js.notices.recent_title')));
            data.recent.forEach(function (i) { list.appendChild(row(i, true)); });
        }
    }

    function load(paint) {
        return fetch('/api/notices.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.success) return;
                paintBadge(data);
                if (paint) paintList(data);
            })
            .catch(function () { /* offline or signed out: keep the last state */ });
    }

    function markSeen() {
        var btn = $('header-notices-btn');
        if (btn) btn.classList.remove('has-new');
        fetch('/api/notices.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
            body: JSON.stringify({ action: 'seen', csrf_token: window.CSRF_TOKEN || '' })
        }).catch(function () {});
    }

    window.toggleNotices = function (event) {
        if (event) { event.preventDefault(); event.stopPropagation(); }
        var dd = $('header-notices-dropdown'), btn = $('header-notices-btn');
        if (!dd) return;
        if (dd.classList.contains('show')) { closeNotices(); return; }
        dd.classList.add('show');
        if (btn) btn.setAttribute('aria-expanded', 'true');
        load(true).then(markSeen);
    };

    function closeNotices() {
        var dd = $('header-notices-dropdown'), btn = $('header-notices-btn');
        if (dd) dd.classList.remove('show');
        if (btn) btn.setAttribute('aria-expanded', 'false');
    }
    window.closeNotices = closeNotices;

    document.addEventListener('click', function (e) {
        var box = $('header-notices');
        if (box && !box.contains(e.target)) closeNotices();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeNotices();
    });

    function start() {
        if (!$('header-notices-btn')) return;
        load(false);
        if (timer) clearInterval(timer);
        timer = setInterval(function () {
            if (document.visibilityState === 'visible') load(false);
        }, REFRESH_MS);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
