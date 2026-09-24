<?php
/**
 * AiPBX.bid — Universal Navigation Bar Include
 * Features announcement banner, brand identity, quick anchors, docs dropdown, SVG flags, and mobile drawer.
 */
global $LANG, $company;
?>
<!-- Top Announcement Banner -->
<div class="top-banner">
    <div class="top-banner-inner">
        <span class="banner-desktop">
            <i class="fa-solid fa-sparkles"></i> <strong>AiPBX v1.0.34 LTS</strong> — 
            <?= t('Açık Kaynak Kurumsal IP Santral & Birleşik İletişim', 'Open-Source Enterprise IP PBX & Unified Communications', 'Open-Source Enterprise IP-Telefonie & Unified Communications') ?>
            • <a href="https://mhrgl.com" target="_blank" rel="noopener">Mahir Gül (mhrgl.com)</a>
        </span>
        <span class="banner-mobile">
            <strong>AiPBX v1.0.34 LTS</strong> • <a href="https://mhrgl.com" target="_blank" rel="noopener">mhrgl.com</a>
        </span>
    </div>
</div>

<!-- Main Navigation Bar -->
<nav>
    <a href="/" class="logo" aria-label="AiPBX Home">
        <img src="/logo.png" alt="AiPBX Logo" width="38" height="38">
        <span>AiPBX</span>
        <span class="logo-badge">LTS</span>
    </a>

    <ul class="nav-links">
        <li>
            <a href="/" class="<?= (!isset($page) || $page === 'home') ? 'active' : '' ?>">
                <?= t('Genel Bakış', 'Overview', 'Übersicht') ?>
            </a>
        </li>
        <li>
            <a href="/features.html" class="<?= (isset($page) && $page === 'features') ? 'active' : '' ?>">
                <?= t('Özellikler', 'Features', 'Funktionen') ?>
            </a>
        </li>
        <li>
            <a href="/tables.php" class="<?= (isset($page) && $page === 'tables') ? 'active' : '' ?>">
                <?= t('Karşılaştırma & Tablolar', 'Tables & Specs', 'Tabellen') ?>
            </a>
        </li>
        <li>
            <a href="/architecture.html" class="<?= (isset($page) && $page === 'architecture') ? 'active' : '' ?>">
                <?= t('Mimari', 'Architecture', 'Architektur') ?>
            </a>
        </li>
        <li>
            <a href="/mobile-apps.html" class="<?= (isset($page) && $page === 'mobile') ? 'active' : '' ?>">
                <?= t('Mobil', 'Mobile Apps', 'Mobil-Apps') ?>
            </a>
        </li>
        <li>
            <a href="/msteams.html" class="<?= (isset($page) && $page === 'teams') ? 'active' : '' ?>">
                <i class="fa-brands fa-microsoft" style="color: #6264a7; margin-right: 4px;"></i><span>Teams</span>
            </a>
        </li>
        <li class="nav-dropdown">
            <button class="nav-dropdown-btn <?= (isset($page) && in_array($page, ['docs', 'installation', 'callcenter', 'security', 'api'])) ? 'active' : '' ?>" type="button" aria-haspopup="true">
                <?= t('Dokümantasyon', 'Docs', 'Handbuch') ?>
                <i class="fa-solid fa-chevron-down" style="font-size:10px; margin-left:3px;"></i>
            </button>
            <div class="nav-dropdown-menu">
                <a href="/installation.html" class="nav-dropdown-item <?= (isset($page) && $page === 'installation') ? 'active' : '' ?>">
                    <i class="fa-solid fa-terminal" style="color:#0284c7; font-size:1.1rem; width:20px; text-align:center;"></i>
                    <div>
                        <div style="font-weight:700; color:#0f172a;"><?= t('Kurulum Kılavuzu', 'Installation Guide', 'Installationsanleitung') ?></div>
                        <small style="color:#64748b; font-size:0.76rem;">Ubuntu 22.04 / 24.04 &amp; Debian 12</small>
                    </div>
                </a>
                <a href="/msteams.html" class="nav-dropdown-item <?= (isset($page) && $page === 'teams') ? 'active' : '' ?>">
                    <i class="fa-brands fa-microsoft" style="color:#6264a7; font-size:1.1rem; width:20px; text-align:center;"></i>
                    <div>
                        <div style="font-weight:700; color:#0f172a;">Microsoft Teams Direct Routing</div>
                        <small style="color:#64748b; font-size:0.76rem;"><?= t('Yerleşik SBC & Çift Yönlü Köprü', 'Built-in SBC & 2-Way Bridge', 'Integriertes SBC & 2-Wege-Gateway') ?></small>
                    </div>
                </a>
                <a href="/mobile-apps.html" class="nav-dropdown-item <?= (isset($page) && $page === 'mobile') ? 'active' : '' ?>">
                    <i class="fa-solid fa-mobile-screen" style="color:#10b981; font-size:1.1rem; width:20px; text-align:center;"></i>
                    <div>
                        <div style="font-weight:700; color:#0f172a;"><?= t('Mobil Softphone', 'Mobile Softphone', 'Mobile Softphones') ?></div>
                        <small style="color:#64748b; font-size:0.76rem;">Android (Kotlin) &amp; iOS (Swift)</small>
                    </div>
                </a>
                <a href="/callcenter.html" class="nav-dropdown-item <?= (isset($page) && $page === 'callcenter') ? 'active' : '' ?>">
                    <i class="fa-solid fa-headset" style="color:#ec4899; font-size:1.1rem; width:20px; text-align:center;"></i>
                    <div>
                        <div style="font-weight:700; color:#0f172a;"><?= t('Çağrı Merkezi & Kuyruklar', 'Call Center & Queues', 'Callcenter & Warteschlangen') ?></div>
                        <small style="color:#64748b; font-size:0.76rem;"><?= t('*22/*23 Mola & *90 Dinleme', '*22/*23 Breaks & *90 Spy', '*22/*23 Pausen & *90 Spy') ?></small>
                    </div>
                </a>
                <a href="/security.html" class="nav-dropdown-item <?= (isset($page) && $page === 'security') ? 'active' : '' ?>">
                    <i class="fa-solid fa-shield-halved" style="color:#f59e0b; font-size:1.1rem; width:20px; text-align:center;"></i>
                    <div>
                        <div style="font-weight:700; color:#0f172a;"><?= t('Güvenlik & SBC Savunması', 'Security & SBC Defense', 'Sicherheit & SBC-Schutz') ?></div>
                        <small style="color:#64748b; font-size:0.76rem;">Fail2ban, TLS 1.3, Passkeys</small>
                    </div>
                </a>
                <a href="/api-docs.html" class="nav-dropdown-item <?= (isset($page) && $page === 'api') ? 'active' : '' ?>">
                    <i class="fa-solid fa-code" style="color:#6366f1; font-size:1.1rem; width:20px; text-align:center;"></i>
                    <div>
                        <div style="font-weight:700; color:#0f172a;">REST API &amp; WebSocket</div>
                        <small style="color:#64748b; font-size:0.76rem;"><?= t('Entegrasyon, Webhook & Go Chat', 'Integrations, Webhooks & Chat', 'Integrationen, Webhooks & Chat') ?></small>
                    </div>
                </a>
            </div>
        </li>
    </ul>

    <div class="nav-actions">
        <!-- SVG Flag Language Dropdown (TR, EN, DE with Austrian Flag) -->
        <div class="lang-selector" id="langSelector">
            <button class="lang-btn" id="langSelectorBtn" aria-label="Select Language" type="button">
                <span class="lang-flag" id="currentLangFlag">
                    <svg class="flag-icon" viewBox="0 0 1200 800" width="20" height="14"><rect width="1200" height="800" fill="#E30A17"/><circle cx="480" cy="400" r="200" fill="#fff"/><circle cx="520" cy="400" r="160" fill="#E30A17"/><polygon fill="#fff" points="583,400 641,335 600,400 641,465" transform="rotate(18,610,400)"/></svg>
                </span>
                <span class="lang-code" id="currentLangCode">TR</span>
                <i class="fa-solid fa-chevron-down" style="font-size:10px; margin-left:2px;"></i>
            </button>
            <div class="lang-dropdown" id="langDropdown">
                <button type="button" class="lang-option active" data-set-lang="tr" onclick="setLanguage('tr')">
                    <span class="flag"><svg class="flag-icon" viewBox="0 0 1200 800" width="20" height="14"><rect width="1200" height="800" fill="#E30A17"/><circle cx="480" cy="400" r="200" fill="#fff"/><circle cx="520" cy="400" r="160" fill="#E30A17"/><polygon fill="#fff" points="583,400 641,335 600,400 641,465" transform="rotate(18,610,400)"/></svg></span>
                    <span>Türkçe (TR)</span>
                    <i class="fa-solid fa-check check"></i>
                </button>
                <button type="button" class="lang-option" data-set-lang="en" onclick="setLanguage('en')">
                    <span class="flag"><svg class="flag-icon" viewBox="0 0 60 30" width="20" height="14"><rect width="60" height="30" fill="#012169"/><path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/><path d="M0,0 L60,30 M60,0 L0,30" stroke="#C8102E" stroke-width="4"/><path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/><path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/></svg></span>
                    <span>English (EN)</span>
                    <i class="fa-solid fa-check check"></i>
                </button>
                <button type="button" class="lang-option" data-set-lang="de" onclick="setLanguage('de')">
                    <span class="flag"><svg class="flag-icon" viewBox="0 0 900 600" width="20" height="14"><rect width="900" height="600" fill="#fff"/><rect width="900" height="200" fill="#ed2939"/><rect y="400" width="900" height="200" fill="#ed2939"/></svg></span>
                    <span>Deutsch (AT)</span>
                    <i class="fa-solid fa-check check"></i>
                </button>
            </div>
        </div>

        <!-- GitHub Repo Button -->
        <a href="https://github.com/mahirgul/AiPBX" target="_blank" rel="noopener" class="btn-github desktop-only" title="GitHub Repository">
            <i class="fa-brands fa-github"></i>
            <span>GitHub</span>
        </a>

        <!-- Mobile Toggle Button -->
        <button class="mobile-toggle-btn" id="mobileToggleBtn" aria-label="Toggle Menu" type="button">
            <span></span>
            <span></span>
            <span></span>
        </button>
    </div>
</nav>

<!-- Mobile Navigation Drawer -->
<div class="mobile-drawer" id="mobileDrawer">
    <div class="mobile-drawer-header">
        <a href="/" class="logo">
            <img src="/logo.png" alt="AiPBX Logo" width="34" height="34">
            <span>AiPBX</span>
            <span class="logo-badge">LTS</span>
        </a>
        <button class="mobile-drawer-close" id="mobileDrawerClose" aria-label="Close menu">&times;</button>
    </div>

    <!-- Mobile Language Bar with SVG Flags -->
    <div class="mobile-lang-bar">
        <button type="button" class="mobile-lang-btn active" data-set-lang="tr" onclick="setLanguage('tr')">
            <svg class="flag-icon" viewBox="0 0 1200 800" width="18" height="13"><rect width="1200" height="800" fill="#E30A17"/><circle cx="480" cy="400" r="200" fill="#fff"/><circle cx="520" cy="400" r="160" fill="#E30A17"/><polygon fill="#fff" points="583,400 641,335 600,400 641,465" transform="rotate(18,610,400)"/></svg>
            <span>TR</span>
        </button>
        <button type="button" class="mobile-lang-btn" data-set-lang="en" onclick="setLanguage('en')">
            <svg class="flag-icon" viewBox="0 0 60 30" width="18" height="13"><rect width="60" height="30" fill="#012169"/><path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/><path d="M0,0 L60,30 M60,0 L0,30" stroke="#C8102E" stroke-width="4"/><path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/><path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/></svg>
            <span>EN</span>
        </button>
        <button type="button" class="mobile-lang-btn" data-set-lang="de" onclick="setLanguage('de')">
            <svg class="flag-icon" viewBox="0 0 900 600" width="18" height="13"><rect width="900" height="600" fill="#fff"/><rect width="900" height="200" fill="#ed2939"/><rect y="400" width="900" height="200" fill="#ed2939"/></svg>
            <span>DE (AT)</span>
        </button>
    </div>

    <ul class="mobile-nav-links">
        <li><a href="/" class="mobile-nav-item"><i class="fa-solid fa-house" style="color:#0284c7;"></i> <span><?= t('Genel Bakış', 'Overview', 'Übersicht') ?></span></a></li>
        <li><a href="/features.html" class="mobile-nav-item"><i class="fa-solid fa-bolt" style="color:#f59e0b;"></i> <span><?= t('Özellikler & Modüller', 'Features & Modules', 'Funktionen & Module') ?></span></a></li>
        <li><a href="/tables.php" class="mobile-nav-item"><i class="fa-solid fa-table-list" style="color:#10b981;"></i> <span><?= t('Karşılaştırma & Tablolar', 'Tables & Specs', 'Tabellen') ?></span></a></li>
        <li><a href="/architecture.html" class="mobile-nav-item"><i class="fa-solid fa-sitemap" style="color:#6366f1;"></i> <span><?= t('Sistem Mimarisi', 'Architecture', 'Architektur') ?></span></a></li>
        <li><a href="/mobile-apps.html" class="mobile-nav-item"><i class="fa-solid fa-mobile-screen" style="color:#0ea5e9;"></i> <span><?= t('Mobil Softphone', 'Mobile Softphone', 'Mobile Apps') ?></span></a></li>
        <li><a href="/msteams.html" class="mobile-nav-item"><i class="fa-brands fa-microsoft" style="color:#6264a7;"></i> <span>Microsoft Teams Direct Routing</span></a></li>
        <li style="border-top: 1px solid var(--border); margin: 8px 0; padding-top: 8px;">
            <div style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; padding:4px 12px;"><?= t('Kılavuzlar & Dokümantasyon', 'Guides & Documentation', 'Handbücher & Dokumentation') ?></div>
        </li>
        <li><a href="/installation.html" class="mobile-nav-item"><i class="fa-solid fa-terminal" style="color:#0284c7;"></i> <span><?= t('Kurulum Kılavuzu', 'Installation Guide', 'Installation') ?></span></a></li>
        <li><a href="/msteams.html" class="mobile-nav-item"><i class="fa-brands fa-microsoft" style="color:#6264a7;"></i> <span>Microsoft Teams</span></a></li>
        <li><a href="/mobile-apps.html" class="mobile-nav-item"><i class="fa-solid fa-mobile-screen" style="color:#10b981;"></i> <span><?= t('Mobil Softphone', 'Mobile Softphone', 'Mobile Apps') ?></span></a></li>
        <li><a href="/callcenter.html" class="mobile-nav-item"><i class="fa-solid fa-headset" style="color:#ec4899;"></i> <span><?= t('Çağrı Merkezi & Mola', 'Call Center & Breaks', 'Callcenter & Pausen') ?></span></a></li>
        <li><a href="/security.html" class="mobile-nav-item"><i class="fa-solid fa-shield-halved" style="color:#f59e0b;"></i> <span><?= t('Güvenlik & SBC', 'Security & SBC', 'Sicherheit & SBC') ?></span></a></li>
        <li><a href="/api-docs.html" class="mobile-nav-item"><i class="fa-solid fa-code" style="color:#8b5cf6;"></i> <span>REST API &amp; WebSocket</span></a></li>
    </ul>

    <div class="mobile-drawer-actions">
        <a href="https://github.com/mahirgul/AiPBX" target="_blank" rel="noopener" class="btn-github" style="justify-content:center;">
            <i class="fa-brands fa-github"></i>
            <span>GitHub Repository</span>
        </a>
        <a href="https://mhrgl.com" target="_blank" rel="noopener" class="btn-secondary" style="justify-content:center;">
            <i class="fa-solid fa-globe"></i>
            <span>mhrgl.com</span>
        </a>
    </div>
</div>
<div class="mobile-backdrop" id="mobileBackdrop"></div>
