/* Sign-in pages: an eye button in every password field shows / hides the typed password (#11). */
(function () {
    const labels = window.AIPBX_REVEAL_LABELS || { show: 'Show password', hide: 'Hide password' };
    document.querySelectorAll('input[type="password"]').forEach(input => {
        if (input.dataset.reveal) return;
        input.dataset.reveal = '1';
        const wrap = input.parentElement;
        if (getComputedStyle(wrap).position === 'static') wrap.style.position = 'relative';
        input.style.paddingRight = '44px';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'password-reveal-btn';
        btn.setAttribute('aria-label', labels.show);
        btn.title = labels.show;
        btn.innerHTML = '<i class="fas fa-eye" aria-hidden="true"></i>';
        btn.addEventListener('click', () => {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.innerHTML = '<i class="fas ' + (show ? 'fa-eye-slash' : 'fa-eye') + '" aria-hidden="true"></i>';
            btn.setAttribute('aria-label', show ? labels.hide : labels.show);
            btn.title = show ? labels.hide : labels.show;
            input.focus();
        });
        // A submitted form never sends the password as a visible field left open.
        if (input.form) input.form.addEventListener('submit', () => { input.type = 'password'; });
        input.insertAdjacentElement('afterend', btn);
    });
})();
