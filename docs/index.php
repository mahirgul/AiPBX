<?php
/**
 * AiPBX.bid — Official Landing Page & Technical Documentation
 * Powered by centralized data.json, full trilingual i18n (TR/EN/DE),
 * dynamic comparison tables, architecture deep-dives, and Schema.org SEO.
 */
require_once __DIR__ . '/includes/data.php';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($LANG) ?>" data-lang="<?= htmlspecialchars($LANG) ?>">
<head>
    <?php include __DIR__ . '/includes/head.php'; ?>
    <?php include __DIR__ . '/includes/seo-helper.php'; ?>
</head>
<body>

    <!-- Header & Navigation -->
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <main>
        <!-- ── HERO SECTION ──────────────────────────────────────────────── -->
        <header class="hero" id="overview">
            <div class="hero-tag">
                <i class="fa-solid fa-sparkles" style="color:var(--primary);"></i>
                <?= t(
                    '✨ Açık Kaynak Kurumsal İletişim • Geliştirici: <a href="https://mhrgl.com" target="_blank" rel="noopener">Mahir Gül</a>',
                    '✨ Open Source Enterprise Communications • Created by <a href="https://mhrgl.com" target="_blank" rel="noopener">Mahir Gül</a>',
                    '✨ Open-Source Enterprise-Kommunikation • Entwickelt von <a href="https://mhrgl.com" target="_blank" rel="noopener">Mahir Gül</a>'
                ) ?>
            </div>

            <h1>
                <?= t(
                    'Kurumsal IP Santral, <span>Amacına Uygun Tasarlandı.</span>',
                    'Enterprise IP Telephony, <span>Engineered with Purpose.</span>',
                    'Enterprise IP-Telefonie, <span>Konsequent Durchdacht.</span>'
                ) ?>
            </h1>

            <p>
                <?= t(
                    'Asterisk 22, Port 443 Nginx L4 ALPN stream çoklama, WebRTC, Passkey biyometrik kimlik doğrulama, Go anlık sohbet motoru ve yerel Android/iOS softphone istemcilerini birleştiren modern kurumsal santral.',
                    'A high-concurrency, web-managed IP PBX uniting Asterisk 22, Port 443 ALPN stream multiplexing, WebRTC, Passkeys, Go instant messaging, and native Android & iOS softphone clients.',
                    'Eine hochskalierbare Enterprise IP-Telefonanlage mit Asterisk 22, Port 443 ALPN-Multiplexing, WebRTC, biometrischen Passkeys, Go-Echtzeitchat und nativen Mobil-Softphones.'
                ) ?>
            </p>

            <div class="hero-actions">
                <a href="#tables" class="btn-primary">
                    <i class="fa-solid fa-table-list" style="margin-right:6px;"></i>
                    <?= t('JSON Tablolar & Matris', 'Tables & Specs', 'Tabellen & Matrix') ?>
                </a>
                <a href="#technologies" class="btn-primary" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-color: #10b981;">
                    <i class="fa-solid fa-microchip" style="margin-right:6px;"></i>
                    <?= t('Yeni Teknolojiler', 'Flagship Tech', 'Technologien') ?>
                </a>
                <a href="#architecture" class="btn-secondary">
                    <i class="fa-solid fa-sitemap" style="margin-right:6px;"></i>
                    <?= t('Sistem Mimarisi', 'Architecture', 'Architektur') ?>
                </a>
                <a href="/mobile-apps.html" class="btn-secondary">
                    <i class="fa-solid fa-mobile-screen" style="margin-right:6px;"></i>
                    <?= t('Mobil Uygulamalar', 'Mobile Apps', 'Mobil-Apps') ?>
                </a>
            </div>

            <!-- Terminal Card: One-Liner Turnkey Quick Install -->
            <div class="terminal-container" id="install" style="max-width: 880px; margin: 38px auto 26px auto; border-radius: 18px; box-shadow: 0 20px 50px rgba(15, 23, 42, 0.25);">
                <div class="terminal-header">
                    <div class="terminal-dots">
                        <span class="terminal-dot dot-red"></span>
                        <span class="terminal-dot dot-yellow"></span>
                        <span class="terminal-dot dot-green"></span>
                    </div>
                    <span class="terminal-title">bash — Quick One-Line Turnkey Install (Ubuntu 22.04 / 24.04 / Debian 12)</span>
                    <button class="terminal-copy" id="btnCopyCurl" type="button" onclick="copyInstallCmd('btnCopyCurl', '<?= htmlspecialchars($company['turnkeyCommand'] ?? '') ?>')">Copy</button>
                </div>
                <div class="terminal-body" style="padding: 22px 26px; font-size: 0.95rem; line-height: 1.8;">
                    <span class="terminal-comment"># <?= t('Tek satırda tam otomatik santral kurulumu (Turnkey installer)', 'One-liner turnkey PBX installation', 'Vollautomatische PBX-Einzeiler-Installation') ?></span><br>
                    <span class="terminal-cmd" style="color: #38bdf8; font-weight: 600; font-size: 1.05rem;"><?= htmlspecialchars($company['turnkeyCommand'] ?? '') ?></span><br><br>
                    <span class="terminal-comment"># <?= t('Veya kaynak koddan derleyerek kurulum (Alternative git clone)', 'Alternative git clone from source', 'Alternativ aus dem Quellcode via Git') ?></span><br>
                    <span class="terminal-cmd" style="color: #94a3b8;"><?= htmlspecialchars($company['gitCloneCommand'] ?? '') ?></span>
                </div>
            </div>

            <!-- Flyer Showcase -->
            <div class="flyer-wrapper" style="max-width: 980px; margin: 34px auto 10px auto; padding: 0 16px;">
                <div style="background: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 25px 60px -15px rgba(2, 132, 199, 0.22), 0 0 0 1px rgba(226, 232, 240, 0.9);">
                    <a href="/img/feature_graphic.png" target="_blank" title="<?= t('AiPBX Tanıtım Broşürü — Büyütmek için tıklayın', 'AiPBX Flyer — Click to expand', 'AiPBX Flyer — Klicken zum Vergrößern') ?>">
                        <img src="/img/feature_graphic.png" alt="AiPBX Kurumsal Akıllı Santral ve Softphone Tanıtım Broşürü" style="width: 100%; height: auto; display: block; border-radius: 20px;">
                    </a>
                </div>
                <p style="margin-top: 12px; text-align: center; font-size: 0.88rem; color: var(--text-muted);">
                    <i class="fa-solid fa-expand" style="margin-right: 6px; color: var(--primary);"></i>
                    <?= t(
                        'AiPBX Kurumsal Telefon Santrali & Mobil Softphone Tanıtımı • Büyütmek için görsele tıklayın',
                        'AiPBX Enterprise IP PBX & Mobile Softphone Overview • Click image to view full size',
                        'AiPBX Enterprise IP-Telefonie & Mobile Softphone Übersicht • Klicken zum Vergrößern'
                    ) ?>
                </p>
            </div>
        </header>

        <!-- ── STATS BAR ─────────────────────────────────────────────────── -->
        <section style="background: #ffffff; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); padding: 40px 24px;">
            <div style="max-width: 1280px; margin: 0 auto; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 24px; text-align: center;">
                <?php foreach ($stats as $st): ?>
                <div style="padding: 12px;">
                    <div style="font-size: clamp(2rem, 3.2vw, 2.6rem); font-weight: 800; color: #0f172a; line-height: 1.1; letter-spacing: -0.5px;"><?= htmlspecialchars($st['value']) ?></div>
                    <div style="font-size: 1rem; font-weight: 700; color: var(--primary); margin-top: 6px;"><?= getLocal($st, 'label') ?></div>
                    <div style="font-size: 0.82rem; color: var(--text-muted); margin-top: 4px;"><?= getLocal($st, 'sub') ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ── FLAGSHIP TECHNOLOGIES SHOWCASE ───────────────────────────── -->
        <section class="section-wrapper" id="technologies">
            <div class="section-header-center">
                <div class="section-eyebrow">
                    <i class="fa-solid fa-bolt"></i>
                    <?= t('YENİ NESİL MİMARİ', 'NEXT-GEN ARCHITECTURE', 'NEXT-GEN ARCHITEKTUR') ?>
                </div>
                <h2 class="section-title-large">
                    <?= t('En Son Eklenen Teknolojik Yetenekler', 'Flagship Technological Innovations', 'Neueste Technologische Kernfunktionen') ?>
                </h2>
                <p class="section-subtitle-muted">
                    <?= t(
                        'AiPBX, modern kurumsal iletişimin en kritik ihtiyaçlarını sıfır lisans maliyeti ve tam bağımsızlıkla çözen güçlü bileşenlerle donatıldı.',
                        'Engineered to conquer modern enterprise telecom hurdles with zero license fees, strict data sovereignty, and open protocols.',
                        'Entwickelt für moderne Enterprise-Anforderungen: Ohne Lizenzgebühren, mit voller Datensouveränität und offenen Protokollen.'
                    ) ?>
                </p>
            </div>

            <div class="tech-cards-grid">
                <?php foreach ($flagshipFeatures as $feat): ?>
                <div class="tech-card" style="--card-accent: <?= htmlspecialchars($feat['color']) ?>;">
                    <div class="tech-card-header">
                        <div class="tech-icon-box">
                            <i class="<?= (!empty($feat['iconBrand']) ? 'fa-brands' : 'fa-solid') ?> <?= htmlspecialchars($feat['icon']) ?>"></i>
                        </div>
                        <span class="tech-badge"><?= htmlspecialchars($feat['badge']) ?></span>
                    </div>
                    <h3 class="tech-card-title"><?= getLocal($feat, 'title') ?></h3>
                    <p class="tech-card-desc"><?= getLocal($feat, 'desc') ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ── JSON TABLES & COMPARISON MATRIX ──────────────────────────── -->
        <section class="section-wrapper" id="tables" style="padding-top: 20px;">
            <div class="section-header-center">
                <div class="section-eyebrow">
                    <i class="fa-solid fa-table"></i>
                    <?= t('JSON TABLO SİSTEMİ', 'JSON TABLE SYSTEM', 'JSON-TABELLENSYSTEM') ?>
                </div>
                <h2 class="section-title-large">
                    <?= t('Teknik Özellik Matrisi & Sistem Parametreleri', 'Technical Feature Matrix & System Specs', 'Technische Funktionsmatrix & Systemparameter') ?>
                </h2>
                <p class="section-subtitle-muted">
                    <?= t(
                        'data.json üzerinden dinamik olarak beslenen karşılaştırmalı santral matrisi, ağ portları, yıldız kodları ve arama yetki seviyeleri.',
                        'Centralized data.json powering our comprehensive PBX comparison matrix, network port architecture, feature star codes, and dialing permissions.',
                        'Zentral über data.json gesteuerte PBX-Vergleichsmatrix, Netzwerk-Portarchitektur, Funktionstastencodes und Berechtigungsstufen.'
                    ) ?>
                </p>
            </div>

            <div class="table-system-container">
                <!-- Toolbar: Tabs + Instant Search Filter -->
                <div class="table-toolbar">
                    <div class="table-tabs-nav" role="tablist">
                        <button class="table-tab-btn active" data-target="comparison" type="button" role="tab">
                            <i class="fa-solid fa-scale-balanced"></i>
                            <?= t('Karşılaştırma Matrisi', 'Comparison Matrix', 'Vergleichsmatrix') ?>
                        </button>
                        <button class="table-tab-btn" data-target="protocols" type="button" role="tab">
                            <i class="fa-solid fa-network-wired"></i>
                            <?= t('Ağ Portları & ALPN', 'Ports & Protocols', 'Netzwerk & Ports') ?>
                        </button>
                        <button class="table-tab-btn" data-target="starcodes" type="button" role="tab">
                            <i class="fa-solid fa-asterisk"></i>
                            <?= t('Yıldız Kodları (*90/*22)', 'Star Codes (*90/*22)', 'Funktionscodes (*90/*22)') ?>
                        </button>
                        <button class="table-tab-btn" data-target="permissions" type="button" role="tab">
                            <i class="fa-solid fa-phone-slash"></i>
                            <?= t('Arama Yetki Grupları', 'Call Barring', 'Wählberechtigung') ?>
                        </button>
                        <button class="table-tab-btn" data-target="requirements" type="button" role="tab">
                            <i class="fa-solid fa-server"></i>
                            <?= t('Sistem Gereksinimleri', 'System Specs', 'Systemanforderungen') ?>
                        </button>
                    </div>

                    <div class="table-search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="tableFilterInput" class="table-search-input" placeholder="<?= t('Tabloda ara (ör. passkey, port, *90)...', 'Search in table (e.g. passkey, port, *90)...', 'In Tabelle suchen (z.B. Passkey, Port, *90)...') ?>">
                    </div>
                </div>

                <!-- 1. COMPARISON MATRIX TAB -->
                <div class="table-tab-panel active" id="panel-comparison" role="tabpanel">
                    <div class="table-responsive-wrapper">
                        <table class="aipbx-data-table">
                            <thead>
                                <tr>
                                    <th style="width: 14%;"><?= t('Kategori', 'Category', 'Kategorie') ?></th>
                                    <th style="width: 24%;"><?= t('Özellik / Yetenek', 'Feature / Capability', 'Funktion / Eigenschaft') ?></th>
                                    <th class="col-aipbx" style="width: 20%;"><i class="fa-solid fa-award" style="margin-right:6px;"></i>AiPBX v1.0 LTS</th>
                                    <th style="width: 18%;"><?= t('Geleneksel Santral', 'Legacy PBX', 'Klassische PBX') ?></th>
                                    <th style="width: 24%;"><?= t('Ticari Bulut PBX', 'Cloud PBX SaaS', 'Cloud PBX SaaS') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tables['comparison'] as $row): ?>
                                <tr>
                                    <td><span class="category-pill"><?= getLocal($row, 'category') ?></span></td>
                                    <td>
                                        <strong><?= getLocal($row, 'feature') ?></strong>
                                        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;"><?= getLocal($row, 'details') ?></div>
                                    </td>
                                    <td class="col-aipbx">
                                        <span class="status-badge <?= htmlspecialchars($row['aipbxStatus']) ?>">
                                            <?= htmlspecialchars($row['aipbx']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= htmlspecialchars($row['legacyStatus']) ?>">
                                            <?= htmlspecialchars($row['legacy']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= htmlspecialchars($row['cloudStatus']) ?>">
                                            <?= htmlspecialchars($row['cloud']) ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 2. NETWORK PROTOCOLS TAB -->
                <div class="table-tab-panel" id="panel-protocols" role="tabpanel">
                    <div class="table-responsive-wrapper">
                        <table class="aipbx-data-table">
                            <thead>
                                <tr>
                                    <th style="width: 16%;"><?= t('Port / Soket', 'Port / Socket', 'Port / Socket') ?></th>
                                    <th style="width: 16%;"><?= t('Protokol', 'Protocol', 'Protokoll') ?></th>
                                    <th style="width: 26%;"><?= t('Servis Adı & Bileşen', 'Service & Component', 'Dienst & Komponente') ?></th>
                                    <th style="width: 24%;"><?= t('Sistem Rolü & Açıklama', 'System Role & Scope', 'Systemrolle & Zweck') ?></th>
                                    <th style="width: 18%;"><?= t('Güvenlik', 'Security', 'Sicherheit') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tables['networkProtocols'] as $pRow): ?>
                                <tr>
                                    <td><span class="chip-port"><?= htmlspecialchars($pRow['port']) ?></span></td>
                                    <td><span class="chip-proto"><?= htmlspecialchars($pRow['protocol']) ?></span></td>
                                    <td><strong><?= getLocal($pRow, 'service') ?></strong></td>
                                    <td style="font-size:0.88rem;"><?= getLocal($pRow, 'role') ?></td>
                                    <td><span style="font-size:0.82rem; color:#059669; font-weight:600;"><i class="fa-solid fa-lock" style="margin-right:4px;"></i><?= getLocal($pRow, 'security') ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 3. STAR CODES TAB -->
                <div class="table-tab-panel" id="panel-starcodes" role="tabpanel">
                    <div class="table-responsive-wrapper">
                        <table class="aipbx-data-table">
                            <thead>
                                <tr>
                                    <th style="width: 18%;"><?= t('Tuşlama Kodu', 'Dial Code', 'Wählcode') ?></th>
                                    <th style="width: 28%;"><?= t('Fonksiyon Adı', 'Function Name', 'Funktionsbezeichnung') ?></th>
                                    <th style="width: 36%;"><?= t('Açıklama & Kullanım Şekli', 'Description & How-to', 'Beschreibung & Anwendung') ?></th>
                                    <th style="width: 18%;"><?= t('Yetki Düzeyi', 'Access Level', 'Berechtigungsstufe') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tables['starCodes'] as $sc): ?>
                                <tr>
                                    <td><span class="star-code-pill"><i class="fa-solid fa-hashtag" style="color:var(--primary); font-size:0.8rem;"></i><?= htmlspecialchars($sc['code']) ?></span></td>
                                    <td><strong><?= getLocal($sc, 'name') ?></strong></td>
                                    <td style="font-size:0.88rem;"><?= getLocal($sc, 'desc') ?></td>
                                    <td><span class="category-pill"><?= getLocal($sc, 'role') ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 4. CALL BARRING TAB -->
                <div class="table-tab-panel" id="panel-permissions" role="tabpanel">
                    <div class="table-responsive-wrapper">
                        <table class="aipbx-data-table">
                            <thead>
                                <tr>
                                    <th style="width: 28%;"><?= t('Arama Yetki Kademesi', 'Dial Permission Level', 'Wählberechtigungsstufe') ?></th>
                                    <th style="width: 52%;"><?= t('İzin Verilen Arama Kapsamı', 'Permitted Calling Scope', 'Erlaubter Wählbereich') ?></th>
                                    <th style="width: 20%;"><?= t('Politika Rozeti', 'Policy Badge', 'Richtlinien-Badge') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tables['dialPermissions'] as $perm): ?>
                                <tr>
                                    <td><strong style="color:var(--text-heading);"><?= getLocal($perm, 'level') ?></strong></td>
                                    <td style="font-size:0.9rem;"><?= getLocal($perm, 'scope') ?></td>
                                    <td><span class="status-badge" style="background:<?= htmlspecialchars($perm['color']) ?>20; color:<?= htmlspecialchars($perm['color']) ?>; border:1px solid <?= htmlspecialchars($perm['color']) ?>40;"><?= htmlspecialchars($perm['badge']) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 5. SYSTEM REQUIREMENTS TAB -->
                <div class="table-tab-panel" id="panel-requirements" role="tabpanel">
                    <div class="table-responsive-wrapper">
                        <table class="aipbx-data-table">
                            <thead>
                                <tr>
                                    <th style="width: 22%;"><?= t('Kapasite Ölçeği', 'Deployment Scale', 'Einsatzbereich') ?></th>
                                    <th style="width: 16%;">İşlemci (vCPU)</th>
                                    <th style="width: 14%;">RAM</th>
                                    <th style="width: 20%;"><?= t('Disk Depolama', 'Storage', 'Festplatte') ?></th>
                                    <th style="width: 14%;"><?= t('Ağ Hızı', 'Network', 'Netzwerk') ?></th>
                                    <th style="width: 14%;"><?= t('İşletim Sistemi', 'Operating System', 'Betriebssystem') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tables['systemRequirements'] as $req): ?>
                                <tr>
                                    <td><strong style="color:var(--primary);"><?= getLocal($req, 'tier') ?></strong></td>
                                    <td><span class="chip-proto"><?= htmlspecialchars($req['cpu']) ?></span></td>
                                    <td><span class="chip-proto"><?= htmlspecialchars($req['ram']) ?></span></td>
                                    <td style="font-size:0.88rem;"><?= htmlspecialchars($req['storage']) ?></td>
                                    <td><span class="chip-proto"><?= htmlspecialchars($req['network']) ?></span></td>
                                    <td style="font-size:0.84rem; font-weight:600;"><?= htmlspecialchars($req['os']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- ── TECHNICAL DEEP DIVE SECTION ───────────────────────────────── -->
        <section class="section-wrapper" id="architecture" style="background: #f8fafc; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); max-width: 100%; padding-left: max(24px, calc((100% - 1280px) / 2)); padding-right: max(24px, calc((100% - 1280px) / 2));">
            <div class="section-header-center">
                <div class="section-eyebrow">
                    <i class="fa-solid fa-code-fork"></i>
                    <?= t('MİMARİ & PROTOKOL AKIŞI', 'ARCHITECTURE & PROTOCOL FLOW', 'ARCHITEKTUR & PROTOKOLLABLAUF') ?>
                </div>
                <h2 class="section-title-large">
                    <?= t('Teknik Konular & Çözüm Mimarisi', 'Technical Deep Dive & Architecture', 'Technische Architektur & Deep Dive') ?>
                </h2>
                <p class="section-subtitle-muted">
                    <?= t(
                        'AiPBX çekirdeğinde yer alan L4 stream çoklama, dual-endpoint PJSIP senkronizasyonu ve mobil push uyanma algoritmalarının detaylı mühendislik açıklaması.',
                        'In-depth technical breakdown of L4 stream multiplexing, dual-endpoint PJSIP synchronization, and high-efficiency push wake-up algorithms.',
                        'Detaillierte Analyse des L4-Stream-Multiplexings, der Dual-Endpoint-PJSIP-Synchronisierung und der Push-Wakeup-Algorithmen.'
                    ) ?>
                </p>
            </div>

            <!-- Architecture ASCII Flow Diagram -->
            <div class="diagram-terminal" style="max-width: 960px; margin: 0 auto 36px auto;">
                <div style="font-size:0.75rem; text-transform:uppercase; color:#94a3b8; margin-bottom:12px; font-weight:700;">
                    <i class="fa-solid fa-diagram-project" style="color:#38bdf8; margin-right:6px;"></i>
                    <?= t('AiPBX Port 443 L4 ALPN Stream & Bileşen Yönlendirme Şeması', 'AiPBX Port 443 L4 ALPN Stream & Component Routing Flow', 'AiPBX Port 443 L4 ALPN Stream & Routing-Diagramm') ?>
                </div>
                <pre>
   [ İstemciler: Web Tarayıcı / Mobil App / Masaüstü IP Tel / Teams ]
                                │
                                ▼  (Tek Port: TCP 443)
              ┌────────────────────────────────────┐
              │   Nginx L4 Stream (ssl_preread)    │
              │  TLS ClientHello ALPN Denetimi     │
              └─────────────────┬──────────────────┘
                                │
         ┌──────────────────────┼──────────────────────┐
         ▼ (ALPN: http/1.1, h2) ▼ (ALPN: webrtc / WSS)  ▼ (ALPN: sip / TLS SIP)
  ┌──────────────┐       ┌──────────────┐       ┌──────────────┐
  │ Apache Web   │       │ Asterisk WSS │       │ PJSIP SIP    │
  │ Portal :8443 │       │ WebRTC :8089 │       │ Core :5061   │
  └──────────────┘       └──────────────┘       └──────────────┘
         │                      │                      │
         └──────────────┬───────┴──────────────────────┘
                        │
                        ▼
       ┌─────────────────────────────────┐
       │   Go Chat Daemon (Port 8090)    │
       │   FCM / APNs Push Engine        │
       │   Asterisk 22 & MariaDB 11      │
       └─────────────────────────────────┘
                </pre>
            </div>

            <div class="deepdive-grid">
                <!-- Card 1: ALPN -->
                <div class="deepdive-card">
                    <div class="deepdive-header">
                        <div class="deepdive-icon"><i class="fa-solid fa-network-wired"></i></div>
                        <h3 class="deepdive-title"><?= getLocal($technicalDeepDive['alpn'], 'title') ?></h3>
                    </div>
                    <p class="deepdive-text"><?= getLocal($technicalDeepDive['alpn'], 'concept') ?></p>
                </div>

                <!-- Card 2: Dual Endpoint -->
                <div class="deepdive-card">
                    <div class="deepdive-header">
                        <div class="deepdive-icon" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);"><i class="fa-solid fa-clone"></i></div>
                        <h3 class="deepdive-title"><?= getLocal($technicalDeepDive['dualEndpoint'], 'title') ?></h3>
                    </div>
                    <p class="deepdive-text"><?= getLocal($technicalDeepDive['dualEndpoint'], 'concept') ?></p>
                </div>

                <!-- Card 3: Push Wakeup -->
                <div class="deepdive-card">
                    <div class="deepdive-header">
                        <div class="deepdive-icon" style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);"><i class="fa-solid fa-mobile-screen-button"></i></div>
                        <h3 class="deepdive-title"><?= getLocal($technicalDeepDive['pushWakeup'], 'title') ?></h3>
                    </div>
                    <p class="deepdive-text"><?= getLocal($technicalDeepDive['pushWakeup'], 'concept') ?></p>
                </div>
            </div>
        </section>

        <!-- ── FAQ SECTION ───────────────────────────────────────────────── -->
        <section class="section-wrapper" id="faq">
            <div class="section-header-center">
                <div class="section-eyebrow">
                    <i class="fa-solid fa-circle-question"></i>
                    <?= t('MERAK EDİLENLER', 'FAQ', 'HÄUFIG GESTELLTE FRAGEN') ?>
                </div>
                <h2 class="section-title-large">
                    <?= t('Sıkça Sorulan Sorular', 'Frequently Asked Questions', 'Häufig gestellte Fragen') ?>
                </h2>
                <p class="section-subtitle-muted">
                    <?= t(
                        'AiPBX platformunun mimarisi, kurulum süreci, güvenliği ve açık kaynak lisansı hakkında kapsamlı yanıtlar.',
                        'Detailed answers regarding AiPBX architecture, turnkey deployment, enterprise security, and MIT licensing.',
                        'Detaillierte Antworten zu Architektur, Installation, Unternehmenssicherheit und MIT-Lizenzierung von AiPBX.'
                    ) ?>
                </p>
            </div>

            <div class="faq-accordion">
                <?php foreach ($faq as $idx => $fItem): ?>
                <div class="faq-item <?= ($idx === 0) ? 'active' : '' ?>">
                    <div class="faq-question">
                        <span><?= getLocal($fItem, 'q') ?></span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">
                        <p><?= nl2br(htmlspecialchars(getLocal($fItem, 'a'))) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ── FINAL CALL TO ACTION (CTA) ────────────────────────────────── -->
        <section style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #ffffff; padding: 80px 24px; text-align: center; border-top: 1px solid #334155;">
            <div style="max-width: 820px; margin: 0 auto;">
                <h2 style="font-size: clamp(2rem, 3.2vw, 2.75rem); font-weight: 800; margin-bottom: 18px; letter-spacing: -0.5px;">
                    <?= t(
                        'Kurumsal İletişiminizi Bugün Özgürleştirin.',
                        'Liberate Your Enterprise Telephony Today.',
                        'Befreien Sie Ihre Unternehmenskommunikation noch heute.'
                    ) ?>
                </h2>
                <p style="font-size: 1.15rem; color: #94a3b8; line-height: 1.7; margin-bottom: 34px;">
                    <?= t(
                        'Tek bir komutla kendi donanımınızda veya bulut sunucunuzda dakikalar içinde yüksek kapasiteli, güvenli ve lisanssız IP santralinizi devreye alın.',
                        'Deploy your private, high-concurrency, license-free IP PBX on bare-metal or cloud instances in minutes with a single command.',
                        'Installieren Sie Ihre private, lizenzfreie Telefonanlage mit voller Kapazität auf eigener Hardware oder Cloud in wenigen Minuten.'
                    ) ?>
                </p>

                <div style="display:flex; flex-wrap:wrap; gap:16px; justify-content:center; align-items:center;">
                    <a href="#install" class="btn-primary" style="background:#38bdf8; color:#0f172a; border-color:#38bdf8; font-weight:700;">
                        <i class="fa-solid fa-bolt" style="margin-right:6px;"></i>
                        <?= t('Hızlı Kuruluma Git', 'Get Started (5 Min)', 'Jetzt Installieren') ?>
                    </a>
                    <a href="https://github.com/mahirgul/AiPBX" target="_blank" rel="noopener" class="btn-secondary" style="color:#ffffff; border-color:#475569; background:rgba(255,255,255,0.06);">
                        <i class="fa-brands fa-github" style="margin-right:6px;"></i>
                        <?= t('GitHub Deposu & Kaynak Kod', 'GitHub Repository & Code', 'GitHub Repository & Quellcode') ?>
                    </a>
                </div>
            </div>
        </section>
    </main>

    <!-- Universal Footer -->
    <?php include __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
