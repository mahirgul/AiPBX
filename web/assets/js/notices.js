/**
 * Top bar notice cabinet.
 *
 * The list is fetched, not rendered by PHP, because a mailbox that changes
 * while a page is open (a trunk that comes back up) should not need a reload:
 * opening the bell re-reads it, and the count is refreshed on the same call.
 *
 * Every action posts the page's CSRF token; the endpoint refuses without it.
 */
(function () {
    'use strict';

    var bell = null;
    var dropdown = null;
    var list = null;
    var count = null;
    var csrf = '';
    var settingsForm = null;
    var settingsMsg = null;

    function t(key, fallback) {
        return (window.NOTICES_I18N && window.NOTICES_I18N[key]) || fallback;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    function paintCount(unread) {
        if (!count) return;
        count.textContent = String(unread);
        count.style.display = unread > 0 ? '' : 'none';
        if (bell) bell.classList.toggle('has-unread', unread > 0);
    }

    function paintList(notices) {
        list.innerHTML = '';
        if (!notices.length) {
            list.appendChild(el('div', 'header-notices-empty', t('empty', 'Nothing yet.')));
            return;
        }
        notices.forEach(function (notice) {
            var row = el('div', 'header-notices-item' + (notice.read ? '' : ' is-unread'));
            row.dataset.id = String(notice.id);

            var icon = el('i', 'fas ' + (notice.icon || 'fa-circle-info') + ' header-notices-icon text-' + (notice.colour || 'info'));
            row.appendChild(icon);

            var body = el('div', 'header-notices-text');
            body.appendChild(el('div', 'header-notices-title', notice.title));
            if (notice.body) {
                body.appendChild(el('div', 'header-notices-body', notice.body));
            }
            var meta = el('div', 'header-notices-meta');
            meta.textContent = notice.created_at + (notice.emailed ? ' · ' + t('emailed', 'e-mailed') : '');
            body.appendChild(meta);
            row.appendChild(body);

            row.addEventListener('click', function () { noticeMarkRead(notice.id, row); });
            list.appendChild(row);
        });
    }

    function load() {
        return fetch('/api/notices.php?limit=20', { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || !data.success) throw new Error('bad payload');
                paintCount(data.unread || 0);
                if (dropdown && dropdown.style.display !== 'none') {
                    paintList(data.notices || []);
                }
                return data;
            })
            .catch(function () {
                if (dropdown && dropdown.style.display !== 'none' && list) {
                    list.innerHTML = '';
                    list.appendChild(el('div', 'header-notices-empty', t('load_failed', 'Notices could not be loaded.')));
                }
            });
    }

    function post(action, id) {
        var form = new FormData();
        form.append('csrf_token', csrf);
        form.append('action', action);
        if (id) form.append('id', String(id));
        return fetch('/api/notices.php', { method: 'POST', body: form, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) { if (data && data.success) paintCount(data.unread || 0); return data; });
    }

    function noticeMarkRead(id, row) {
        if (row && row.classList.contains('is-unread')) {
            row.classList.remove('is-unread');
            post('read', id);
        }
    }

    window.noticeMarkRead = noticeMarkRead;

    window.noticesMarkAll = function (event) {
        if (event) event.stopPropagation();
        post('read_all').then(function () {
            if (list) {
                Array.prototype.forEach.call(list.querySelectorAll('.header-notices-item'), function (row) {
                    row.classList.remove('is-unread');
                });
            }
        });
    };

    /** The gear swaps the list for the cabinet's settings: mail, quiet hours, window. */
    window.noticesToggleSettings = function (event) {
        if (event) event.stopPropagation();
        if (!settingsForm) return;
        var opening = settingsForm.style.display === 'none';
        settingsForm.style.display = opening ? 'block' : 'none';
        if (list) list.style.display = opening ? 'none' : '';
        if (opening && settingsMsg) settingsMsg.textContent = '';
    };

    window.noticesSaveSettings = function (event) {
        if (event) event.preventDefault();
        if (!settingsForm) return;
        var form = new FormData(settingsForm);
        form.append('csrf_token', csrf);
        form.append('action', 'settings');
        if (!form.get('notice_email')) form.append('notice_email', '0');
        fetch('/api/notices.php', { method: 'POST', body: form, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!settingsMsg) return;
                settingsMsg.textContent = (data && data.success)
                    ? (data.message || t('saved', 'Saved.'))
                    : ((data && data.error) || t('load_failed', 'Could not be saved.'));
            })
            .catch(function () {
                if (settingsMsg) settingsMsg.textContent = t('load_failed', 'Could not be saved.');
            });
    };

    window.noticesToggle = function (event) {
        if (event) event.stopPropagation();
        if (!dropdown) return;
        var opening = dropdown.style.display === 'none';
        dropdown.style.display = opening ? 'block' : 'none';
        if (opening) load();
    };


    document.addEventListener('click', function (event) {
        if (!dropdown || dropdown.style.display === 'none') return;
        if (dropdown.contains(event.target) || (bell && bell.contains(event.target))) return;
        dropdown.style.display = 'none';
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && dropdown && dropdown.style.display !== 'none') {
            dropdown.style.display = 'none';
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        bell = document.getElementById('notices-bell');
        dropdown = document.getElementById('notices-dropdown');
        list = document.getElementById('notices-list');
        count = document.getElementById('notices-bell-count');
        settingsForm = document.getElementById('notices-settings');
        settingsMsg = document.getElementById('notices-settings-msg');
        if (!bell || !dropdown || !list) return;
        csrf = bell.dataset.csrf || '';
        load();
    });
})();
