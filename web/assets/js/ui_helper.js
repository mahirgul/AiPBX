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
        openModals.forEach(m => UIHelper.closeModal(m));
    }
});

/**
 * Güçlü karmaşık SIP şifresi üretir (16 karakter, kriptografik rastgele).
 * system_users.php ve extensions.php'deki "otomatik üret" butonları kullanır.
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
 * Header "Uygula" (Pending Sync) Dropdown & Direkt Uygulama Yönetimi
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

// Dropdown dışına tıklandığında menüyü kapat
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
            // Başarılı: Başlık ve kenar çubuğundaki rozetleri gizle, sayaçları sıfırla
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

            // Eğer şu an /pending-sync sayfasındaysa o sayfayı da yenile
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
