/**
 * AI → Cloud TTS page: voices per provider/language, synthesis, playback,
 * saving a result as an announcement. Talks to /api/ai_tts.php.
 */
(function () {
    'use strict';
    const T = window.TTS || {};
    let voices = [];
    let current = null;   // history id of the result on screen

    const $ = id => document.getElementById(id);
    const langName = code => {
        if (code === '*') return T.text.all_voices;
        try { return new Intl.DisplayNames([T.lang || 'en'], { type: 'language' }).of(code) + ' (' + code + ')'; } catch (e) { return code; }
    };
    const status = (msg, kind) => {
        const el = $('ttsStatus');
        if (!el) return;
        el.textContent = msg || '';
        el.style.color = kind === 'error' ? 'var(--danger)' : (kind === 'ok' ? 'var(--success)' : 'var(--text-muted)');
    };
    const post = (data) => {
        const body = new URLSearchParams(Object.assign({ csrf_token: T.csrf }, data));
        return fetch('/api/ai_tts.php', { method: 'POST', body: body }).then(r => r.json());
    };

    function fillLanguages() {
        const sel = $('ttsLanguage');
        const prev = sel.value || localStorage.getItem('ttsLanguage') || 'en-US';
        const langs = [...new Set(voices.filter(v => v.language !== '*').map(v => v.language))].sort((a, b) => langName(a).localeCompare(langName(b)));
        const multi = voices.some(v => v.language === '*');
        sel.innerHTML = '';
        if (multi && !langs.length) sel.add(new Option(T.text.all_voices, '*'));
        langs.forEach(l => sel.add(new Option(langName(l), l)));
        if ([...sel.options].some(o => o.value === prev)) sel.value = prev;
        fillVoices();
    }

    function fillVoices() {
        const sel = $('ttsVoice');
        const lang = $('ttsLanguage').value;
        const prev = sel.value || localStorage.getItem('ttsVoice:' + $('ttsProvider').value);
        sel.innerHTML = '';
        voices.filter(v => v.language === lang || v.language === '*').forEach(v => {
            sel.add(new Option(v.name + (v.gender ? ' · ' + v.gender : ''), v.id));
        });
        if (prev && [...sel.options].some(o => o.value === prev)) sel.value = prev;
    }

    window.ttsLoadVoices = function (refresh) {
        const provider = $('ttsProvider').value;
        status(T.text.loading);
        $('ttsVoice').innerHTML = '';
        fetch('/api/ai_tts.php?action=voices&provider=' + encodeURIComponent(provider) + (refresh ? '&refresh=1' : ''), { cache: 'no-store' })
            .then(r => r.json())
            .then(d => {
                if (!d.success) { status(d.error || T.text.error, 'error'); return; }
                voices = d.voices || [];
                status('');
                fillLanguages();
            })
            .catch(() => status(T.text.error, 'error'));
    };

    function updateCount() {
        const text = $('ttsText').value;
        const max = Number($('ttsProvider').selectedOptions[0]?.dataset.max || 4000);
        $('ttsCount').textContent = T.text.chars.replace('%d', text.length.toLocaleString(T.lang));
        const parts = Math.ceil(text.length / max);
        $('ttsChunks').textContent = parts > 1 ? T.text.chunks.replace('%d', parts) : '';
    }

    function showResult(id, title) {
        current = id;
        $('ttsResult').style.display = '';
        $('ttsResultTitle').textContent = T.text.result.replace('%s', title);
        $('ttsAudio').src = '/api/ai_tts.php?action=audio&id=' + id;
        $('ttsDownload').href = '/api/ai_tts.php?action=audio&id=' + id + '&download=1';
        if ($('ttsSoundName')) $('ttsSoundName').value = 'tts_' + id;
        if ($('ttsSoundTitle')) $('ttsSoundTitle').value = title;
    }

    window.ttsShow = function (id, title) {
        if (!$('ttsResult')) { window.open('/api/ai_tts.php?action=audio&id=' + id, '_blank'); return; }
        showResult(id, title);
        $('ttsAudio').play().catch(() => {});
        $('ttsResult').scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    window.ttsSynthesize = function () {
        const btn = $('ttsGo');
        const text = $('ttsText').value.trim();
        if (!text) { $('ttsText').focus(); return; }
        btn.disabled = true;
        status(T.text.working);
        localStorage.setItem('ttsLanguage', $('ttsLanguage').value);
        localStorage.setItem('ttsVoice:' + $('ttsProvider').value, $('ttsVoice').value);
        post({ action: 'synthesize', provider: $('ttsProvider').value, voice: $('ttsVoice').value, language: $('ttsLanguage').value, text: text, speed: $('ttsSpeed').value })
            .then(d => {
                btn.disabled = false;
                if (!d.success) { status(d.error || T.text.error, 'error'); return; }
                status('');
                showResult(d.item.id, text.slice(0, 60));
                $('ttsAudio').play().catch(() => {});
            })
            .catch(() => { btn.disabled = false; status(T.text.error, 'error'); });
    };

    window.ttsSaveAnnouncement = function () {
        if (!current) return;
        post({ action: 'save_announcement', id: current, sound_name: $('ttsSoundName').value, title: $('ttsSoundTitle').value })
            .then(d => {
                const msg = d.success ? d.message : (d.error || T.text.error);
                if (window.showFooterToast) window.showFooterToast(msg, d.success ? 'success' : 'error'); else status(msg, d.success ? 'ok' : 'error');
            });
    };

    window.ttsTest = function (provider, btn) {
        btn.disabled = true;
        fetch('/api/ai_tts.php?action=voices&refresh=1&provider=' + encodeURIComponent(provider), { cache: 'no-store' })
            .then(r => r.json())
            .then(d => {
                btn.disabled = false;
                const msg = d.success ? T.text.test_ok.replace('%d', (d.voices || []).length) : (d.error || T.text.error);
                if (window.showFooterToast) window.showFooterToast(msg, d.success ? 'success' : 'error'); else alert(msg);
            })
            .catch(() => { btn.disabled = false; });
    };

    function init() {
        if (!$('ttsProvider')) return;
        $('ttsProvider').addEventListener('change', () => { window.ttsLoadVoices(false); updateCount(); });
        $('ttsLanguage').addEventListener('change', fillVoices);
        $('ttsText').addEventListener('input', updateCount);
        window.ttsLoadVoices(false);
        updateCount();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
