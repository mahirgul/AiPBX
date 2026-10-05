/**
 * AI PBX Universal UI & Frontend Helper Suite
 */
const UIHelper = {
    /**
     * Show Modal Element
     */
    showModal(modalId) {
        const modal = typeof modalId === 'string' ? document.getElementById(modalId) : modalId;
        if (modal) {
            modal.classList.add('show');
            modal.style.display = 'block';
            document.body.classList.add('modal-open');
        }
    },

    /**
     * Hide Modal Element
     */
    closeModal(modalId) {
        const modal = typeof modalId === 'string' ? document.getElementById(modalId) : modalId;
        if (modal) {
            modal.classList.remove('show');
            modal.style.display = 'none';
            document.body.classList.remove('modal-open');
        }
    },

    /**
     * POST to /api/cc.php?action=X with CSRF token + form-urlencoded params.
     * cc.php reads $_POST, so the body must stay form-urlencoded (not JSON).
     */
    ccPost(action, params = {}) {
        const body = new URLSearchParams({ csrf_token: window.CSRF_TOKEN || '', ...params });
        return fetch('/api/cc.php?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(res => res.json());
    },

    /**
     * Drag-and-drop row ordering for tables whose rows carry data-id and a
     * .row-drag-handle cell; the new order is saved to /api/reorder.php.
     */
    enableRowReorder(table, entity) {
        const tbody = table && table.querySelector('tbody');
        if (!tbody || tbody.dataset.reorder === '1') return;
        tbody.dataset.reorder = '1';
        const order = () => Array.from(tbody.querySelectorAll('tr[data-id]')).map(r => r.dataset.id);
        let dragging = null;
        let before = '';

        tbody.addEventListener('mousedown', e => {
            const tr = e.target.closest('tr[data-id]');
            if (tr) tr.draggable = !!e.target.closest('.row-drag-handle');
        });
        tbody.addEventListener('dragstart', e => {
            dragging = e.target.closest('tr[data-id]');
            if (!dragging) return;
            before = order().join(',');
            dragging.classList.add('row-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', dragging.dataset.id);
        });
        tbody.addEventListener('dragover', e => {
            if (!dragging) return;
            e.preventDefault();
            const over = e.target.closest('tr[data-id]');
            if (!over || over === dragging) return;
            const box = over.getBoundingClientRect();
            tbody.insertBefore(dragging, e.clientY > box.top + box.height / 2 ? over.nextSibling : over);
        });
        tbody.addEventListener('dragend', () => {
            if (!dragging) return;
            dragging.classList.remove('row-dragging');
            dragging.draggable = false;
            dragging = null;
            const ids = order();
            if (ids.join(',') === before) return;
            const body = new URLSearchParams({ csrf_token: window.CSRF_TOKEN || '', entity: entity });
            ids.forEach(id => body.append('ids[]', id));
            fetch('/api/reorder.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(r => r.json()).then(res => {
                if (!res.success) throw new Error(res.error || 'save failed');
            }).catch(err => {
                alert('Sıralama kaydedilemedi: ' + err.message);
                location.reload();
            });
        });
    },

    /**
     * GET from /api/cc.php?action=X (optionally with an extra query string suffix)
     */
    ccGet(action, extraQuery = '') {
        return fetch('/api/cc.php?action=' + action + extraQuery).then(res => res.json());
    },

    /**
     * Universal Table Search Filter
     */
    filterTable(inputId, tableId) {
        const input = typeof inputId === 'string' ? document.getElementById(inputId) : inputId;
        const table = typeof tableId === 'string' ? document.getElementById(tableId) : tableId;
        if (!input || !table) return;

        input.addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = table.querySelectorAll('tbody tr');

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });
    },

    /**
     * Universal Clipboard Copying with Toast Notification
     */
    copyToClipboard(text, successMsg = 'Metin panoya kopyalandi!') {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(() => {
                if (window.showFooterToast) {
                    showFooterToast(successMsg, 'success');
                }
            }).catch(err => {
                console.error('Clipboard copy error:', err);
            });
        }
    },

    /**
     * Standardized Delete Confirmation
     */
    confirmDelete(msg = 'Bu kaydi silmek istediginize emin misiniz?', onConfirm) {
        if (confirm(msg)) {
            if (typeof onConfirm === 'function') onConfirm();
        }
    },

    /**
     * Show/hide a `.modal-overlay` element. This matches the convention every real modal in the
     * app actually uses (classList 'active' + display:flex) — NOT showModal()/closeModal() above,
     * which use a different '.show'/display:block convention that nothing currently calls.
     */
    openOverlayModal(modalId) {
        const modal = typeof modalId === 'string' ? document.getElementById(modalId) : modalId;
        if (modal) {
            modal.style.display = 'flex';
            modal.classList.add('active');
        }
    },

    closeOverlayModal(modalId) {
        const modal = typeof modalId === 'string' ? document.getElementById(modalId) : modalId;
        if (modal) {
            modal.style.display = 'none';
            modal.classList.remove('active');
        }
    }
};

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// escapeHtml() alone is not safe for values embedded inside an inline
// onclick="fn('${...}')" handler: it neutralizes HTML but not the JS
// single-quoted string context, so a value containing a literal quote can
// still break out of the JS string. Use this instead for that specific case
// (JS-escape first, then HTML-escape the result for the surrounding attribute).
function escapeJsAttr(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/\\/g, '\\\\')
        .replace(/'/g, "\\'")
        .replace(/\n/g, '\\n')
        .replace(/\r/g, '\\r')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// Field-level help icons (.field-help): tap-to-toggle for touch devices,
// since CSS :hover alone doesn't work on tap. Delegated so it also covers
// icons inside modals that aren't in the DOM's visible area yet.
document.addEventListener('click', function(e) {
    const icon = e.target.closest ? e.target.closest('.field-help') : null;
    document.querySelectorAll('.field-help.active').forEach(el => {
        if (el !== icon) el.classList.remove('active');
    });
    if (icon) {
        icon.classList.toggle('active');
        e.preventDefault();
    }
});

// Bind Escape key listener to close active modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const openModals = document.querySelectorAll('.modal[style*="display: block"]');
        openModals.forEach(m => { if (!m.querySelector('form')) UIHelper.closeModal(m); });
    }
});

/**
 * Generates a strong, complex SIP password (16 characters, cryptographically random).
 * Used by the "generate" buttons in system_users.php and extensions.php.
 */
function generateSipPassword(length = 16) {
    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*_-';
    let pwd = '';
    if (window.crypto && window.crypto.getRandomValues) {
        const array = new Uint8Array(length);
        window.crypto.getRandomValues(array);
        for (let i = 0; i < length; i++) {
            pwd += chars.charAt(array[i] % chars.length);
        }
    } else {
        for (let i = 0; i < length; i++) {
            pwd += chars.charAt(Math.floor(Math.random() * chars.length));
        }
    }
    return pwd;
}

/**
 * Header "Apply" (pending sync) dropdown & direct apply handling
 */
function toggleHeaderSyncDropdown(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    const dropdown = document.getElementById('headerSyncDropdown');
    const btn = document.getElementById('header-sync-btn');
    if (!dropdown) return;
    
    const isOpen = dropdown.classList.contains('show');
    if (isOpen) {
        closeHeaderSyncDropdown();
    } else {
        dropdown.classList.add('show');
        if (btn) btn.classList.add('active');
    }
}

function closeHeaderSyncDropdown() {
    const dropdown = document.getElementById('headerSyncDropdown');
    const btn = document.getElementById('header-sync-btn');
    if (dropdown) dropdown.classList.remove('show');
    if (btn) btn.classList.remove('active');
}

// Close the menu when clicking outside the dropdown
document.addEventListener('click', function(e) {
    const container = document.getElementById('header-pending-sync-container');
    if (container && !container.contains(e.target)) {
        closeHeaderSyncDropdown();
    }
});

function executeDirectPendingSync(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    closeHeaderSyncDropdown();

    const btn = document.getElementById('header-sync-btn');
    if (!btn || btn.disabled) return;

    const originalContent = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span class="d-none d-sm-inline">' + (window.LANG_APPLYING || 'Uygulanıyor...') + '</span>';

    fetch('/api/pending_sync.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': window.CSRF_TOKEN || ''
        },
        body: JSON.stringify({ action: 'apply', csrf_token: window.CSRF_TOKEN || '' })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalContent;

        if (data.success) {
            if (typeof showFooterToast === 'function') {
                showFooterToast(data.message || 'Değişiklikler başarıyla Asterisk\'e uygulandı.', 'success');
            }
            // Success: hide the badges in the header and sidebar, reset the counters
            const headerContainer = document.getElementById('header-pending-sync-container');
            if (headerContainer) headerContainer.style.display = 'none';

            const sidebarBadge = document.getElementById('pending-sync-badge');
            if (sidebarBadge) sidebarBadge.style.display = 'none';

            const headerCount = document.getElementById('header-pending-sync-count');
            if (headerCount) headerCount.textContent = '0';
            const sidebarCount = document.getElementById('pending-sync-count');
            if (sidebarCount) sidebarCount.textContent = '0';
            const dropdownCount = document.getElementById('header-sync-dropdown-count');
            if (dropdownCount) dropdownCount.textContent = '0';

            const pageData = document.getElementById('spa-page-data');
            if (pageData) pageData.setAttribute('data-pending-sync-count', '0');

            // If we are on the /pending-sync page right now, reload that page too
            if (window.location.pathname === '/pending-sync') {
                if (typeof loadSPAPage === 'function') {
                    loadSPAPage('/pending-sync', false);
                } else {
                    window.location.reload();
                }
            }
        } else {
            if (typeof showFooterToast === 'function') {
                showFooterToast(data.error || 'Uygulama sırasında hata oluştu.', 'danger');
            }
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = originalContent;
        console.error('Pending sync apply error:', err);
        if (typeof showFooterToast === 'function') {
            showFooterToast('Bağlantı hatası: ' + err.message, 'danger');
        }
    });
}

/**
 * Universal Settings Tab Switcher
 */
function switchSettingsTab(tabKey, btn) {
    if (!btn) return;
    const tabContainer = btn.closest('.settings-tabs');
    if (tabContainer) {
        tabContainer.querySelectorAll('.settings-tab-btn').forEach(b => b.classList.remove('active'));
    }
    btn.classList.add('active');

    // Panes
    const root = btn.closest('form') || btn.closest('.spa-content-area') || document;
    root.querySelectorAll('.settings-tab-pane').forEach(p => {
        p.classList.remove('active');
        p.style.display = 'none';
    });

    const target = document.getElementById('tab_' + tabKey);
    if (target) {
        target.classList.add('active');
        target.style.display = 'block';
    }

    try {
        sessionStorage.setItem('active_settings_tab_' + window.location.pathname, tabKey);
    } catch(e) {}
}

// Restore saved settings tab on page load
document.addEventListener('DOMContentLoaded', () => {
    try {
        const savedTab = sessionStorage.getItem('active_settings_tab_' + window.location.pathname);
        if (savedTab) {
            const btn = document.querySelector(`.settings-tab-btn[data-tab="${savedTab}"]`);
            if (btn) btn.click();
        }
    } catch(e) {}
});

// Auto-switch to tab containing invalid input on form submission
document.addEventListener('invalid', function(e) {
    const pane = e.target.closest('.settings-tab-pane, .modal-tab-pane, .trunk-tab-pane, .queue-tab-pane');
    if (pane && (pane.style.display === 'none' || getComputedStyle(pane).display === 'none')) {
        const tabId = pane.id.replace(/^tab_/, '').replace(/^trunk_tab_/, '').replace(/^queue_tab_/, '');
        const btn = document.querySelector(`[data-tab="${tabId}"]`);
        if (btn) btn.click();
        setTimeout(() => {
            try { e.target.focus(); } catch(err) {}
        }, 50);
    }
}, true);

