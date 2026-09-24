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
    <a href="/<?= ($LANG !== 'tr') ? '?lang=' . $LANG : '' ?>" class="logo" aria-label="AiPBX Home">
        <img src="/logo.png" alt="AiPBX Logo" width="38" height="38">
        <span>AiPBX</span>
        <span class="logo-badge">LTS</span>
    </a>

    <ul class="nav-links">
        <li>
            <a href="#overview" class="active">
                <?= t('Genel Bakış', 'Overview', 'Übersicht') ?>
            </a>
        </li>
        <li>
            <a href="#technologies">
                <?= t('Yeni Teknolojiler', 'Flagship Tech', 'Technologien') ?>
            </a>
        </li>
        <li>
            <a href="#tables">
                <?= t('JSON Tablolar & Matris', 'Tables & Specs', 'Tabellen & Matrix') ?>
            </a>
        </li>
        <li>
            <a href="#architecture">
                <?= t('Sistem Mimarisi', 'Architecture', 'Architektur') ?>
            </a>
        </li>
        <li>
            <a href="#faq">
                <?= t('SSS', 'FAQ', 'FAQ') ?>
            </a>
        </li>
        <li class="nav-dropdown">
            <button class="nav-dropdown-btn" type="button" aria-haspopup="true">
                <?= t('Dokümantasyon', 'Docs', 'Handbuch') ?>
                <i class="fa-solid fa-chevron-down nav-caret"></i>
            </button>
            <div class="nav-dropdown-menu">
                <a href="/installation.html" class="nav-dropdown-item">
                    <i class="fa-solid fa-terminal item-icon-terminal"></i>
                    <div>
                        <div class="item-title"><?= t('Kurulum Kılavuzu', 'Installation Guide', 'Installationsanleitung') ?></div>
                        <small class="item-desc">Ubuntu 22.04 / 24.04 &amp; Debian 12</small>
                    </div>
                </a>
                <a href="/msteams.html" class="nav-dropdown-item">
                    <i class="fa-brands fa-microsoft item-icon-teams"></i>
                    <div>
                        <div class="item-title">Microsoft Teams Direct Routing</div>
                        <small class="item-desc"><?= t('Yerleşik SBC & Çift Yönlü Köprü', 'Built-in SBC & 2-Way Bridge', 'Integriertes SBC & 2-Wege-Gateway') ?></small>
                    </div>
                </a>
                <a href="/mobile-apps.html" class="nav-dropdown-item">
                    <i class="fa-solid fa-mobile-screen item-icon-mobile"></i>
                    <div>
                        <div class="item-title"><?= t('Mobil Softphone', 'Mobile Softphone', 'Mobile Softphones') ?></div>
                        <small class="item-desc">Android (Kotlin) &amp; iOS (Swift)</small>
                    </div>
                </a>
                <a href="/callcenter.html" class="nav-dropdown-item">
                    <i class="fa-solid fa-headset item-icon-headset"></i>
                    <div>
                        <div class="item-title"><?= t('Çağrı Merkezi & Kuyruklar', 'Call Center & Queues', 'Callcenter & Warteschlangen') ?></div>
                        <small class="item-desc"><?= t('*22/*23 Mola & *90 Dinleme', '*22/*23 Breaks & *90 Spy', '*22/*23 Pausen & *90 Spy') ?></small>
                    </div>
                </a>
                <a href="/security.html" class="nav-dropdown-item">
                    <i class="fa-solid fa-shield-halved item-icon-shield"></i>
                    <div>
                        <div class="item-title"><?= t('Güvenlik & SBC Savunması', 'Security & SBC Defense', 'Sicherheit & SBC-Schutz') ?></div>
                        <small class="item-desc">Fail2ban, TLS 1.3, Passkeys</small>
                    </div>
                </a>
                <a href="/api-docs.html" class="nav-dropdown-item">
                    <i class="fa-solid fa-code item-icon-api"></i>
                    <div>
                        <div class="item-title">REST API &amp; WebSocket</div>
                        <small class="item-desc"><?= t('Entegrasyon, Webhook & Go Chat', 'Integrations, Webhooks & Chat', 'Integrationen, Webhooks & Chat') ?></small>
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
                    <?php if ($LANG === 'de'): ?>
                    <svg class="flag-icon" viewBox="0 0 900 600" width="20" height="14"><rect width="900" height="600" fill="#fff"/><rect width="900" height="200" fill="#ed2939"/><rect y="400" width="900" height="200" fill="#ed2939"/></svg>
                    <?php elseif ($LANG === 'en'): ?>
                    <svg class="flag-icon" viewBox="0 0 60 30" width="20" height="14"><rect width="60" height="30" fill="#012169"/><path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/><path d="M0,0 L60,30 M60,0 L0,30" stroke="#C8102E" stroke-width="4"/><path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/><path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/></svg>
                    <?php else: ?>
                    <svg class="flag-icon" viewBox="0 0 1200 800" width="20" height="14"><rect width="1200" height="800" fill="#E30A17"/><circle cx="480" cy="400" r="200" fill="#fff"/><circle cx="520" cy="400" r="160" fill="#E30A17"/><polygon fill="#fff" points="583,400 641,335 600,400 641,465" transform="rotate(18,610,400)"/></svg>
                    <?php endif; ?>
                </span>
                <span class="lang-code" id="currentLangCode"><?= strtoupper($LANG === 'de' ? 'DE (AT)' : $LANG) ?></span>
                <i class="fa-solid fa-chevron-down nav-caret"></i>
            </button>
            <div class="lang-dropdown" id="langDropdown">
                <a href="<?= htmlspecialchars(getLangUrl('tr')) ?>" class="lang-option <?= ($LANG === 'tr') ? 'active' : '' ?>">
                    <span class="flag"><svg class="flag-icon" viewBox="0 0 1200 800" width="20" height="14"><rect width="1200" height="800" fill="#E30A17"/><circle cx="480" cy="400" r="200" fill="#fff"/><circle cx="520" cy="400" r="160" fill="#E30A17"/><polygon fill="#fff" points="583,400 641,335 600,400 641,465" transform="rotate(18,610,400)"/></svg></span>
                    <span>Türkçe (TR)</span>
                    <?php if ($LANG === 'tr'): ?><i class="fa-solid fa-check check"></i><?php endif; ?>
                </a>
                <a href="<?= htmlspecialchars(getLangUrl('en')) ?>" class="lang-option <?= ($LANG === 'en') ? 'active' : '' ?>">
                    <span class="flag"><svg class="flag-icon" viewBox="0 0 60 30" width="20" height="14"><rect width="60" height="30" fill="#012169"/><path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/><path d="M0,0 L60,30 M60,0 L0,30" stroke="#C8102E" stroke-width="4"/><path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/><path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/></svg></span>
                    <span>English (EN)</span>
                    <?php if ($LANG === 'en'): ?><i class="fa-solid fa-check check"></i><?php endif; ?>
                </a>
                <a href="<?= htmlspecialchars(getLangUrl('de')) ?>" class="lang-option <?= ($LANG === 'de') ? 'active' : '' ?>">
                    <span class="flag"><svg class="flag-icon" viewBox="0 0 900 600" width="20" height="14"><rect width="900" height="600" fill="#fff"/><rect width="900" height="200" fill="#ed2939"/><rect y="400" width="900" height="200" fill="#ed2939"/></svg></span>
                    <span>Deutsch (AT)</span>
                    <?php if ($LANG === 'de'): ?><i class="fa-solid fa-check check"></i><?php endif; ?>
                </a>
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

    <!-- Mobile Language Bar -->
    <div class="mobile-lang-bar">
        <a href="<?= htmlspecialchars(getLangUrl('tr')) ?>" class="mobile-lang-btn <?= ($LANG === 'tr') ? 'active' : '' ?>">
            <svg class="flag-icon" viewBox="0 0 1200 800" width="18" height="13"><rect width="1200" height="800" fill="#E30A17"/><circle cx="480" cy="400" r="200" fill="#fff"/><circle cx="520" cy="400" r="160" fill="#E30A17"/><polygon fill="#fff" points="583,400 641,335 600,400 641,465" transform="rotate(18,610,400)"/></svg>
            <span>TR</span>
        </a>
        <a href="<?= htmlspecialchars(getLangUrl('en')) ?>" class="mobile-lang-btn <?= ($LANG === 'en') ? 'active' : '' ?>">
            <svg class="flag-icon" viewBox="0 0 60 30" width="18" height="13"><rect width="60" height="30" fill="#012169"/><path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/><path d="M0,0 L60,30 M60,0 L0,30" stroke="#C8102E" stroke-width="4"/><path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/><path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/></svg>
            <span>EN</span>
        </a>
        <a href="<?= htmlspecialchars(getLangUrl('de')) ?>" class="mobile-lang-btn <?= ($LANG === 'de') ? 'active' : '' ?>">
            <svg class="flag-icon" viewBox="0 0 900 600" width="18" height="13"><rect width="900" height="600" fill="#fff"/><rect width="900" height="200" fill="#ed2939"/><rect y="400" width="900" height="200" fill="#ed2939"/></svg>
            <span>DE (AT)</span>
        </a>
    </div>

    <ul class="mobile-nav-links">
        <li><a href="#overview" class="mobile-nav-item"><i class="fa-solid fa-house item-icon-terminal"></i> <span><?= t('Genel Bakış', 'Overview', 'Übersicht') ?></span></a></li>
        <li><a href="#technologies" class="mobile-nav-item"><i class="fa-solid fa-microchip item-icon-teams"></i> <span><?= t('Yeni Teknolojiler', 'Flagship Tech', 'Technologien') ?></span></a></li>
        <li><a href="#tables" class="mobile-nav-item"><i class="fa-solid fa-table-list item-icon-mobile"></i> <span><?= t('JSON Tablolar & Matris', 'Tables & Specs', 'Tabellen') ?></span></a></li>
        <li><a href="#architecture" class="mobile-nav-item"><i class="fa-solid fa-sitemap item-icon-headset"></i> <span><?= t('Sistem Mimarisi', 'Architecture', 'Architektur') ?></span></a></li>
        <li><a href="#faq" class="mobile-nav-item"><i class="fa-solid fa-circle-question item-icon-shield"></i> <span><?= t('SSS & Sorular', 'FAQ', 'FAQ') ?></span></a></li>
        <li style="border-top: 1px solid var(--border); margin: 8px 0; padding-top: 8px;">
            <div style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; padding:4px 12px;">Dokümantasyon</div>
        </li>
        <li><a href="/installation.html" class="mobile-nav-item"><i class="fa-solid fa-terminal item-icon-terminal"></i> <span><?= t('Kurulum Kılavuzu', 'Installation Guide', 'Installation') ?></span></a></li>
        <li><a href="/msteams.html" class="mobile-nav-item"><i class="fa-brands fa-microsoft item-icon-teams"></i> <span>Microsoft Teams</span></a></li>
        <li><a href="/mobile-apps.html" class="mobile-nav-item"><i class="fa-solid fa-mobile-screen item-icon-mobile"></i> <span><?= t('Mobil Softphone', 'Mobile Softphone', 'Mobile Apps') ?></span></a></li>
        <li><a href="/callcenter.html" class="mobile-nav-item"><i class="fa-solid fa-headset item-icon-headset"></i> <span><?= t('Çağrı Merkezi & Mola', 'Call Center & Breaks', 'Callcenter & Pausen') ?></span></a></li>
        <li><a href="/security.html" class="mobile-nav-item"><i class="fa-solid fa-shield-halved item-icon-shield"></i> <span><?= t('Güvenlik & SBC', 'Security & SBC', 'Sicherheit & SBC') ?></span></a></li>
        <li><a href="/api-docs.html" class="mobile-nav-item"><i class="fa-solid fa-code item-icon-api"></i> <span>REST API &amp; WebSocket</span></a></li>
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
