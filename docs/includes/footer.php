<?php
/**
 * AiPBX.bid — Universal Footer Include
 * Rich corporate footer with structured navigation, technical links, copyright, and global JS interaction script.
 */
global $LANG, $company;
?>
<footer>
    <div class="footer-grid">
        <div class="footer-brand">
            <h4>
                <img src="/logo.png" alt="AiPBX Logo" width="28" height="28" style="vertical-align:middle; border-radius:4px; margin-right:8px;">
                AiPBX
                <span class="logo-badge" style="font-size:0.68rem; margin-left:6px;">v1.0.34 LTS</span>
            </h4>
            <p>
                <?= t(
                    'Açık kaynak, yüksek eşzamanlılıklı kurumsal IP PBX ve Birleşik İletişim Platformu. Kısıtlayıcı güvenlik duvarlarını aşan Port 443 ALPN mimarisi, Asterisk 22, WebRTC, Passkey biyometrik güvenlik ve yerel mobil softphone.',
                    'Open-source, high-concurrency enterprise IP PBX and Unified Communications platform. Featuring Port 443 ALPN stream multiplexing, Asterisk 22, WebRTC, Passkeys, and native mobile softphone apps.',
                    'Open-Source Enterprise IP-Telefonanlage und Unified Communications Plattform mit Port 443 ALPN-Multiplexing, Asterisk 22, WebRTC, Passkeys und nativen mobilen Softphones.'
                ) ?>
            </p>
            <p style="margin-top:14px; font-size:0.86rem;">
                <span style="color:var(--text-muted);"><?= t('Proje Mimarı & Baş Geliştirici:', 'Architect & Lead Developer:', 'Architektur & Entwickler:') ?></span>
                <a href="https://mhrgl.com" target="_blank" rel="noopener" style="color:#38bdf8; font-weight:700; text-decoration:none;">Mahir Gül (mhrgl.com)</a>
            </p>
            <div style="margin-top: 16px; display:flex; gap:12px; align-items:center;">
                <a href="https://github.com/mahirgul/AiPBX" target="_blank" rel="noopener" class="btn-github" style="padding:6px 14px; font-size:0.82rem;">
                    <i class="fa-brands fa-github"></i> <span>GitHub</span>
                </a>
                <a href="https://mhrgl.com" target="_blank" rel="noopener" class="btn-secondary" style="padding:6px 14px; font-size:0.82rem;">
                    <i class="fa-solid fa-globe"></i> <span>mhrgl.com</span>
                </a>
            </div>
        </div>

        <div class="footer-col">
            <h5><?= t('Çözümler & Yetenekler', 'Solutions & Features', 'Lösungen & Features') ?></h5>
            <ul>
                <li><a href="#technologies"><?= t('Biyometrik Passkeys (WebAuthn)', 'Biometric Passkeys (WebAuthn)', 'Biometrische Passkeys') ?></a></li>
                <li><a href="#technologies"><?= t('Google OAuth 2.0 Giriş', 'Google OAuth 2.0 Login', 'Google OAuth 2.0 Login') ?></a></li>
                <li><a href="/msteams.html">Microsoft Teams Direct Routing</a></li>
                <li><a href="#architecture"><?= t('Port 443 ALPN Stream Çoklama', 'Port 443 ALPN Multiplexing', 'Port 443 ALPN Multiplexing') ?></a></li>
                <li><a href="#tables"><?= t('Canlı Süpervizör Dinleme (*90)', 'Supervisor Call Spy (*90)', 'Supervisor Mithören (*90)') ?></a></li>
                <li><a href="/callcenter.html"><?= t('Mola Kodları (*22/*23)', 'Break Codes (*22/*23)', 'Pausencodes (*22/*23)') ?></a></li>
                <li><a href="#technologies"><?= t('Go Sohbet Motoru (aipbx-chat)', 'Go WebSocket Chat', 'Go WebSocket Chat') ?></a></li>
            </ul>
        </div>

        <div class="footer-col">
            <h5><?= t('Dokümantasyon & Rehber', 'Docs & Guides', 'Dokumentation & Handbuch') ?></h5>
            <ul>
                <li><a href="/installation.html"><?= t('Hızlı Kurulum Kılavuzu', 'Installation Guide', 'Installationsanleitung') ?></a></li>
                <li><a href="/architecture.html"><?= t('Dual-Endpoint PJSIP Mimarisi', 'Dual-Endpoint Architecture', 'Dual-Endpoint Architektur') ?></a></li>
                <li><a href="/mobile-apps.html"><?= t('Mobil Softphone (Android/iOS)', 'Mobile Softphones', 'Mobile Softphones') ?></a></li>
                <li><a href="/callcenter.html"><?= t('Çağrı Merkezi & Kuyruklar', 'Call Center & Queues', 'Callcenter & Warteschlangen') ?></a></li>
                <li><a href="/security.html"><?= t('SBC Savunması & Fail2ban', 'SBC Defense & Security', 'Sicherheit & SBC-Schutz') ?></a></li>
                <li><a href="/api-docs.html"><?= t('REST API & Webhooks', 'REST API & Webhooks', 'REST-API & Webhooks') ?></a></li>
                <li><a href="#faq"><?= t('Sıkça Sorulan Sorular', 'Frequently Asked Questions', 'Häufig gestellte Fragen') ?></a></li>
            </ul>
        </div>

        <div class="footer-col">
            <h5><?= t('Açık Kaynak & İndirme', 'Open Source & Downloads', 'Open Source & Downloads') ?></h5>
            <ul>
                <li><a href="https://github.com/mahirgul/AiPBX" target="_blank" rel="noopener"><?= t('Kaynak Kodu (GitHub)', 'Source Code (GitHub)', 'Quellcode (GitHub)') ?></a></li>
                <li><a href="/mobile-apps.html"><?= t('Android APK (v1.0.34)', 'Android APK (v1.0.34)', 'Android APK (v1.0.34)') ?></a></li>
                <li><a href="/mobile-apps.html#ios"><?= t('iOS Swift & CallKit', 'iOS Swift & CallKit', 'iOS Swift & CallKit') ?></a></li>
                <li><a href="https://github.com/mahirgul/AiPBX/blob/main/LICENSE" target="_blank" rel="noopener">MIT License</a></li>
                <li><a href="https://github.com/mahirgul/AiPBX/releases" target="_blank" rel="noopener"><?= t('Sürüm Notları (Releases)', 'Release Notes', 'Versionshinweise') ?></a></li>
                <li><a href="/sitemap.xml" target="_blank">XML Sitemap</a></li>
            </ul>
        </div>
    </div>

    <div class="footer-bottom">
        <div>
            &copy; <?= date('Y') ?> <strong>AiPBX</strong>. <?= t('Tüm hakları saklıdır.', 'All rights reserved.', 'Alle Rechte vorbehalten.') ?>
            • <?= t('Geliştirici & Sistem Mimarı:', 'Lead Architect:', 'Entwickler & Architekt:') ?> <a href="https://mhrgl.com" target="_blank" rel="noopener">Mahir Gül</a>
        </div>
        <div style="display:flex; gap:16px; align-items:center;">
            <span><i class="fa-solid fa-code-commit" style="color:var(--primary); margin-right:4px;"></i> Build <?= htmlspecialchars($company['build'] ?? '35') ?></span>
            <span><i class="fa-solid fa-shield-check" style="color:var(--accent); margin-right:4px;"></i> FIDO2 / Passkeys</span>
            <span><i class="fa-solid fa-scale-balanced" style="color:var(--accent-amber); margin-right:4px;"></i> MIT License</span>
        </div>
    </div>
</footer>

<!-- Global Interaction Scripts -->
<script>
    // Mobile Drawer Toggle
    const mobileToggleBtn = document.getElementById('mobileToggleBtn');
    const mobileDrawer = document.getElementById('mobileDrawer');
    const mobileBackdrop = document.getElementById('mobileBackdrop');
    const mobileDrawerClose = document.getElementById('mobileDrawerClose');

    function openDrawer() {
        if (mobileDrawer) mobileDrawer.classList.add('active');
        if (mobileBackdrop) mobileBackdrop.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeDrawer() {
        if (mobileDrawer) mobileDrawer.classList.remove('active');
        if (mobileBackdrop) mobileBackdrop.classList.remove('active');
        document.body.style.overflow = '';
    }

    if (mobileToggleBtn) mobileToggleBtn.addEventListener('click', openDrawer);
    if (mobileDrawerClose) mobileDrawerClose.addEventListener('click', closeDrawer);
    if (mobileBackdrop) mobileBackdrop.addEventListener('click', closeDrawer);

    // Language Dropdown Toggle
    const langBtn = document.getElementById('langSelectorBtn');
    const langDropdown = document.getElementById('langDropdown');
    if (langBtn && langDropdown) {
        langBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            langDropdown.classList.toggle('active');
        });
        document.addEventListener('click', (e) => {
            if (!langDropdown.contains(e.target) && !langBtn.contains(e.target)) {
                langDropdown.classList.remove('active');
            }
        });
    }

    // JSON Tables Tab Switcher
    function initTableTabs() {
        const tabButtons = document.querySelectorAll('.table-tab-btn');
        const tabPanels = document.querySelectorAll('.table-tab-panel');

        tabButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const target = btn.getAttribute('data-target');
                tabButtons.forEach(b => b.classList.remove('active'));
                tabPanels.forEach(p => p.classList.remove('active'));

                btn.classList.add('active');
                const panel = document.getElementById('panel-' + target);
                if (panel) {
                    panel.classList.add('active');
                }
            });
        });
    }

    // Table Search Filter
    function initTableSearch() {
        const searchInput = document.getElementById('tableFilterInput');
        if (!searchInput) return;

        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            const activePanel = document.querySelector('.table-tab-panel.active');
            if (!activePanel) return;

            const rows = activePanel.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(query)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }

    // FAQ Accordion
    function initFaqAccordion() {
        const faqItems = document.querySelectorAll('.faq-item');
        faqItems.forEach(item => {
            const header = item.querySelector('.faq-question');
            if (header) {
                header.addEventListener('click', () => {
                    const isOpen = item.classList.contains('active');
                    faqItems.forEach(i => i.classList.remove('active'));
                    if (!isOpen) {
                        item.classList.add('active');
                    }
                });
            }
        });
    }

    // One-liner copy button
    function copyInstallCmd(btnId, cmd) {
        const btn = document.getElementById(btnId);
        if (!btn) return;
        navigator.clipboard.writeText(cmd).then(() => {
            const orig = btn.innerText;
            btn.innerText = 'Copied!';
            btn.classList.add('copied');
            setTimeout(() => {
                btn.innerText = orig;
                btn.classList.remove('copied');
            }, 2500);
        });
    }

    // Run initializers
    document.addEventListener('DOMContentLoaded', () => {
        initTableTabs();
        initTableSearch();
        initFaqAccordion();
    });
</script>
