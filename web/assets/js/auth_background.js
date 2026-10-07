/**
 * Animated background of the sign-in pages (taken from mhrgl.com): a
 * particles.js network and falling SIP / Asterisk log lines.
 *
 * Decorative only: behind the cards, no pointer events, skipped when the user
 * asks for reduced motion, lighter on phones, paused while the tab is hidden.
 * Colours follow the light / dark theme.
 */
(function () {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const isMobile = window.innerWidth < 768;
    const isDark = () => document.documentElement.getAttribute('data-theme') === 'dark';

    const bg = document.createElement('div');
    bg.className = 'auth-bg';
    bg.setAttribute('aria-hidden', 'true');
    bg.innerHTML = '<div id="auth-bg-particles"></div><div class="auth-bg-logs"></div>';
    document.body.prepend(bg);
    const logs = bg.querySelector('.auth-bg-logs');

    // ---- particles network -------------------------------------------------
    function startParticles() {
        if (typeof particlesJS === 'undefined') return;
        const c = isDark() ? '#00d4ff' : '#0284c7';
        particlesJS('auth-bg-particles', {
            particles: {
                number: { value: isMobile ? 18 : 45 },
                color: { value: c },
                opacity: { value: isDark() ? 0.3 : 0.35 },
                size: { value: 2 },
                line_linked: { enable: true, distance: 150, color: c, opacity: isDark() ? 0.2 : 0.18, width: 1 },
                move: { enable: true, speed: 1 }
            },
            interactivity: { detect_on: 'canvas', events: { onhover: { enable: false }, onclick: { enable: false } } },
            retina_detect: false
        });
    }

    // ---- falling SIP log lines ---------------------------------------------
    const MAX_LINES = isMobile ? 6 : 14;
    const INTERVAL = isMobile ? 900 : 350;
    const r = (a, b) => Math.floor(Math.random() * (b - a) + a);
    const ip = () => r(1, 254) + '.' + r(0, 255) + '.' + r(0, 255) + '.' + r(1, 254);
    function sipLine() {
        const to = r(1000, 9999), from = r(1000, 9999), host = ip();
        const lines = [
            'INVITE sip:' + to + '@' + host + ' SIP/2.0',
            'REGISTER sip:' + host + ' SIP/2.0',
            'SIP/2.0 200 OK (CSeq: ' + r(100, 999) + ' INVITE)',
            'SIP/2.0 180 Ringing <PJSIP/' + from + ' -> ' + to + '>',
            'ACK sip:' + to + '@' + host + ':' + r(1024, 65535) + ' SIP/2.0',
            'BYE sip:' + from + '@' + host + ' SIP/2.0',
            'Asterisk[' + r(2000, 8000) + ']: New channel PJSIP/' + from + '-0000' + r(10, 99),
            'RTCP Quality: Jitter:0.00' + r(1, 5) + ' Loss:0% Delay:1' + r(0, 9) + 'ms',
            '-- Executing [' + to + '@from-internal:1] Dial("PJSIP/' + from + '")',
            '-- PJSIP/' + to + '-0000' + r(10, 99) + ' is ringing',
            'Content-Type: application/sdp (Audio/PCMA/8000)'
        ];
        return lines[r(0, lines.length)];
    }
    function lineColor(t) {
        const dark = isDark();
        if (t.includes('INVITE')) return dark ? '#00d4ff' : '#0369a1';
        if (t.includes('REGISTER')) return dark ? '#25d366' : '#15803d';
        if (t.includes('200 OK')) return dark ? '#00ff88' : '#047857';
        if (t.includes('180 Ringing')) return dark ? '#ffcc00' : '#b45309';
        if (t.includes('BYE')) return dark ? '#ff6b3d' : '#c2410c';
        return dark ? '#00ffee' : '#0e7490';
    }
    let active = 0;
    function addLine() {
        if (document.hidden || active >= MAX_LINES) return;
        const text = sipLine(), color = lineColor(text);
        // Keep the middle (where the cards are) mostly free on wide screens.
        const left = window.innerWidth > 992
            ? (Math.random() > 0.5 ? Math.random() * 22 : Math.random() * 18 + 74)
            : Math.random() * 85;
        const el = document.createElement('div');
        el.className = 'auth-bg-line';
        el.textContent = '[' + new Date().toLocaleTimeString() + '] ' + text;
        el.style.left = left + '%';
        el.style.color = color;
        el.style.fontSize = r(11, 15) + 'px';
        el.style.opacity = (Math.random() * 0.3 + (isDark() ? 0.25 : 0.18)).toFixed(2);
        if (isDark()) el.style.textShadow = '0 0 8px ' + color + '88';
        logs.appendChild(el);
        active++;
        let y = -60;
        const speed = isMobile ? Math.random() * 0.8 + 0.6 : Math.random() * 1.4 + 0.8;
        (function fall() {
            y += speed;
            el.style.transform = 'translateY(' + y + 'px)';
            if (y < window.innerHeight + 120) requestAnimationFrame(fall);
            else { el.remove(); active--; }
        })();
    }

    // Start after the page has rendered, so the form is never delayed.
    window.addEventListener('load', function () {
        setTimeout(function () {
            startParticles();
            setInterval(addLine, INTERVAL);
            bg.classList.add('ready');
        }, isMobile ? 1200 : 600);
    });
})();
