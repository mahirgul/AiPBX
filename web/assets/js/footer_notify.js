/**
 * Shared footer notification & system warning manager (Unified Footer Notification System)
 * AI PBX — PBX, fax & call center portal
 */

(function() {
    'use strict';

    // Notification queue & active state
    let _queue = [];
    let _activeToastTimer = null;
    let _activeToastCount = 0;

    document.addEventListener('DOMContentLoaded', function() {
        startFooterClock();
    });

    /**
     * Footer live clock module
     */
    function startFooterClock() {
        const clockEl = document.getElementById('footer-clock');
        if (!clockEl) return;

        function update() {
            const now = new Date();
            const d = String(now.getDate()).padStart(2, '0');
            const m = String(now.getMonth() + 1).padStart(2, '0');
            const y = now.getFullYear();
            const h = String(now.getHours()).padStart(2, '0');
            const min = String(now.getMinutes()).padStart(2, '0');
            const s = String(now.getSeconds()).padStart(2, '0');

            clockEl.innerHTML = `<i class="far fa-clock"></i> ${d}.${m}.${y} ${h}:${min}:${s}`;
        }

        update();
        setInterval(update, 1000);
    }

    /**
     * Safe HTML escape helper
     */
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    /**
     * Centered floating toast notification (footer center)
     * @param {string} msg
     * @param {string} type - 'success', 'danger', 'warning', 'info'
     * @param {number} durationMs
     */
    function showFooterToast(msg, type = 'success', durationMs = 6000) {
        if (!msg) return;

        let container = document.getElementById('footer-toast-container');
        let legacyToast = document.getElementById('footer-center-toast');

        // Create the main container if missing
        if (!container) {
            container = document.createElement('div');
            container.id = 'footer-toast-container';
            container.className = 'footer-toast-container';
            
            const footerBar = document.getElementById('app-footer-bar');
            if (footerBar) {
                footerBar.appendChild(container);
            } else {
                document.body.appendChild(container);
            }
        }

        // Simge Belirleme
        let icon = 'fa-check-circle';
        if (type === 'danger' || type === 'error') icon = 'fa-exclamation-circle';
        else if (type === 'warning') icon = 'fa-exclamation-triangle';
        else if (type === 'info') icon = 'fa-info-circle';

        const toastItem = document.createElement('div');
        toastItem.className = `footer-center-toast toast-${type} toast-enter`;
        toastItem.innerHTML = `
            <i class="fas ${icon} toast-icon"></i>
            <span class="toast-text">${escapeHtml(msg)}</span>
            <button type="button" class="toast-close-btn" title="${__('js.common.close')}">&times;</button>
        `;

        // Close button event
        const closeBtn = toastItem.querySelector('.toast-close-btn');
        closeBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            dismissToast(toastItem);
        });

        // Hide/remove the old toast element
        if (legacyToast) {
            legacyToast.style.display = 'none';
        }

        container.appendChild(toastItem);

        // Trigger the entry animation
        requestAnimationFrame(() => {
            toastItem.classList.remove('toast-enter');
            toastItem.classList.add('toast-active');
        });

        // Auto-close timer
        let timer = setTimeout(() => {
            dismissToast(toastItem);
        }, durationMs);

        // Freeze the timer on hover
        toastItem.addEventListener('mouseenter', () => clearTimeout(timer));
        toastItem.addEventListener('mouseleave', () => {
            timer = setTimeout(() => dismissToast(toastItem), 3000);
        });

        // Custom Event Tetikleme
        window.dispatchEvent(new CustomEvent('app:notification', {
            detail: { message: msg, type: type, duration: durationMs }
        }));
    }

    /**
     * Toast Bildirimini Animasyonla Kapatma
     */
    function dismissToast(el) {
        if (!el || el.classList.contains('toast-leaving')) return;
        el.classList.add('toast-leaving');
        setTimeout(() => {
            if (el.parentNode) {
                el.parentNode.removeChild(el);
            }
        }, 300);
    }

    // Global functions & API definitions
    window.showFooterToast = showFooterToast;

    // Ortak Nesne API: window.notify
    window.notify = {
        show: function(msg, type, duration) { showFooterToast(msg, type || 'info', duration); },
        success: function(msg, duration) { showFooterToast(msg, 'success', duration); },
        warning: function(msg, duration) { showFooterToast(msg, 'warning', duration); },
        danger: function(msg, duration) { showFooterToast(msg, 'danger', duration); },
        error: function(msg, duration) { showFooterToast(msg, 'danger', duration); },
        info: function(msg, duration) { showFooterToast(msg, 'info', duration); }
    };
})();
