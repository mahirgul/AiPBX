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
