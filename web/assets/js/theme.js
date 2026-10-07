// Theme & Typography Preference Switcher Logic (Dark / Light Mode & Font Size Scale)
document.addEventListener('DOMContentLoaded', () => {
    const themeBtn = document.getElementById('theme-toggle-btn');
    const savedTheme = localStorage.getItem('aipbx_theme') || localStorage.getItem('kb_theme') || 'light';
    const savedFont = localStorage.getItem('font_size_pref') || 'normal';
    
    setTheme(savedTheme);
    setFontSizePref(savedFont, false);
    
    if (themeBtn) {
        themeBtn.addEventListener('click', () => {
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            setTheme(newTheme);
            
            // Persist theme to database via AJAX
            fetch('/api/theme.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ theme: newTheme, csrf_token: window.CSRF_TOKEN || '' })
            }).catch(err => console.error('Theme save error:', err));
        });
    }
});

function setTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('aipbx_theme', theme);
    const icon = document.getElementById('theme-icon');
    if (icon) {
        icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }
    const textEl = document.getElementById('theme-text');
    if (textEl) {
        textEl.textContent = theme === 'dark' ? __('js.theme.light') : __('js.theme.dark');
    }
}

function setFontSizePref(size, showToast = true) {
    if (!['small', 'normal', 'large'].includes(size)) size = 'normal';
    document.documentElement.setAttribute('data-font-size', size);
    if (document.body) document.body.setAttribute('data-font-size', size);
    localStorage.setItem('font_size_pref', size);
    updateFontSizeUI(size);
    if (showToast && window.notify) {
        const labels = { small: __('js.theme.small') + ' (-4%)', normal: __('js.theme.normal') + ' (' + __('js.theme.standard') + ')', large: __('js.theme.large') + ' (+4%)' };
        window.notify.success(__('js.theme.font_updated') + labels[size]);
    }
}

function updateFontSizeUI(size) {
    size = size || localStorage.getItem('font_size_pref') || 'normal';
    const btnS = document.getElementById('btn-font-small');
    const btnN = document.getElementById('btn-font-normal');
    const btnL = document.getElementById('btn-font-large');
    const label = document.getElementById('font-size-label');

    const labels = { small: __('js.theme.small'), normal: __('js.theme.normal'), large: __('js.theme.large') };
    if (label) label.innerText = labels[size] || labels.normal;

    [btnS, btnN, btnL].forEach(btn => {
        if (btn) {
            btn.style.background = 'transparent';
            btn.style.color = 'var(--text-muted)';
        }
    });

    const activeBtn = size === 'small' ? btnS : (size === 'large' ? btnL : btnN);
    if (activeBtn) {
        activeBtn.style.background = 'var(--primary)';
        activeBtn.style.color = '#ffffff';
    }
}
