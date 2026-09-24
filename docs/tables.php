<?php
/**
 * AiPBX.bid — JSON Table System & Technical Feature Matrix
 * Standalone dedicated page for full comparison matrix, network ports & ALPN,
 * star codes, dial permissions, and hardware specs.
 * Powered by centralized data.json, full trilingual support (TR/EN/DE), and Schema.org SEO.
 */
$page = 'tables';
require_once __DIR__ . '/includes/data.php';

$pageTitle = [
    'tr' => 'Teknik Özellik Matrisi & Sistem Parametreleri — JSON Tabloları | AiPBX',
    'en' => 'Technical Feature Matrix & System Specs — JSON Tables | AiPBX',
    'de' => 'Technische Funktionsmatrix & Systemparameter — JSON-Tabellen | AiPBX'
];
$pageDesc = [
    'tr' => 'AiPBX kurumsal açık kaynak IP santral karşılaştırma matrisi, ağ portları ve L4 ALPN stream çoklama, santral yıldız kodları (*90, *22, *23, *97, *8000), arama yetki kademeleri ve donanım gereksinimleri tablosu.',
    'en' => 'AiPBX open-source enterprise IP PBX feature comparison matrix, network port architecture, ALPN stream multiplexing, feature star codes (*90, *22, *23), call barring levels, and hardware specs.',
    'de' => 'AiPBX Enterprise Open-Source IP-Telefonie Vergleichsmatrix, Port-Architektur, ALPN-Multiplexing, Funktionstastencodes (*90, *22, *23), Wählberechtigungsstufen und Hardwareanforderungen.'
];
$pageUrl = 'https://aipbx.bid/tables.html';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($LANG) ?>" data-lang="<?= htmlspecialchars($LANG) ?>">
<head>
    <?php include __DIR__ . '/includes/head.php'; ?>

    <title><?= htmlspecialchars($pageTitle[$LANG] ?? $pageTitle['en']) ?></title>
    <meta name="description" content="<?= htmlspecialchars($pageDesc[$LANG] ?? $pageDesc['en']) ?>">
    <meta name="keywords" content="santral karşılaştırma tablosu, aipbx feature matrix, asterisk vs cisco vs 3cx, pbx portları, alpn stream çoklama, pbx yıldız kodları, call barring, asterisk donanım gereksinimleri, kurumsal ip pbx json tablosu">
    <link rel="canonical" href="<?= $pageUrl ?>">

    <!-- Multilingual Hreflang Tags -->
    <link rel="alternate" hreflang="tr" href="https://aipbx.bid/tables.html">
    <link rel="alternate" hreflang="en" href="https://aipbx.bid/tables.html">
    <link rel="alternate" hreflang="de" href="https://aipbx.bid/tables.html">
    <link rel="alternate" hreflang="x-default" href="https://aipbx.bid/tables.html">

    <!-- Open Graph -->
    <meta property="og:site_name" content="AiPBX — Modern Open Source Enterprise IP Telephony">
    <meta property="og:type" content="article">
    <meta property="og:url" content="<?= $pageUrl ?>">
    <meta property="og:title" content="<?= htmlspecialchars($pageTitle[$LANG] ?? $pageTitle['en']) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($pageDesc[$LANG] ?? $pageDesc['en']) ?>">
    <meta property="og:image" content="https://aipbx.bid/logo.png">

    <!-- Schema.org JSON-LD (BreadcrumbList & ItemList) -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@graph": [
        {
          "@type": "BreadcrumbList",
          "itemListElement": [
            {
              "@type": "ListItem",
              "position": 1,
              "name": "AiPBX",
              "item": "https://aipbx.bid/"
            },
            {
              "@type": "ListItem",
              "position": 2,
              "name": "<?= htmlspecialchars($pageTitle[$LANG] ?? $pageTitle['en']) ?>",
              "item": "<?= $pageUrl ?>"
            }
          ]
        },
        {
          "@type": "SoftwareApplication",
          "name": "AiPBX Enterprise Telephony Matrix",
          "applicationCategory": "BusinessApplication, TelecommunicationsApplication",
          "operatingSystem": "Linux, Android, iOS, Web",
          "url": "<?= $pageUrl ?>",
          "description": "Comprehensive PBX feature matrix, star codes, port mappings, and hardware sizing.",
          "author": {
            "@type": "Person",
            "name": "Mahir Gül",
            "url": "https://mhrgl.com"
          }
        },
        {
          "@type": "Table",
          "about": "PBX Telephony System Feature Comparison Matrix and Network Specifications"
        }
      ]
    }
    </script>
</head>
<body>

    <!-- Header & Navigation -->
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <main>
        <!-- ── TABLES HERO / HEADER ──────────────────────────────────────── -->
        <header class="hero" style="padding-top: 50px; padding-bottom: 30px;">
            <!-- Breadcrumb Navigation -->
            <div style="margin-bottom: 20px; font-size: 0.85rem; color: var(--text-muted);">
                <a href="/" style="color: var(--primary); text-decoration: none; font-weight: 600;">
                    <i class="fa-solid fa-house" style="margin-right: 4px;"></i>
                    <?= t('Ana Sayfa', 'Home', 'Startseite') ?>
                </a>
                <span style="margin: 0 8px; color: var(--border);">/</span>
                <span style="color: var(--text-heading); font-weight: 600;">
                    <?= t('Teknik Özellik Tabloları & Matris', 'Technical Feature Tables & Matrix', 'Technische Funktionstabellen & Matrix') ?>
                </span>
            </div>

            <div class="hero-tag">
                <i class="fa-solid fa-table-list" style="color:var(--primary);"></i>
                <?= t('JSON TABLO SİSTEMİ & TEKNİK PARAMETRELER', 'JSON TABLE SYSTEM & SPECS', 'JSON-TABELLENSYSTEM & PARAMETER') ?>
            </div>

            <h1 data-lang="tr" style="max-width: 900px; margin: 0 auto 16px auto;">Teknik Özellik Matrisi & <span>Sistem Parametreleri</span></h1>
            <h1 data-lang="en" style="max-width: 900px; margin: 0 auto 16px auto;">Technical Feature Matrix & <span>System Parameters</span></h1>
            <h1 data-lang="de" style="max-width: 900px; margin: 0 auto 16px auto;">Technische Funktionsmatrix & <span>Systemparameter</span></h1>

            <p data-lang="tr" style="max-width: 820px; margin: 0 auto 24px auto;">AiPBX açık kaynak santral platformunun kurumsal karşılaştırma matrisi, ağ portları ve L4 ALPN stream çoklama haritası, santral yıldız kodları (*90, *22, *23, *97, *8000), arama yetki kademeleri (Call Barring) ve donanım gereksinimleri.</p>
            <p data-lang="en" style="max-width: 820px; margin: 0 auto 24px auto;">Centralized, dynamic JSON tables detailing the AiPBX enterprise feature comparison, network port architecture, star codes (*90, *22, *23), outbound call barring policies, and hardware sizing specs.</p>
            <p data-lang="de" style="max-width: 820px; margin: 0 auto 24px auto;">Zentral gesteuerte, dynamische JSON-Tabellen mit PBX-Vergleichsmatrix, Netzwerk-Portarchitektur, Funktionstastencodes (*90, *22, *23), Wählberechtigungsstufen und Hardware-Dimensionierung.</p>

            <!-- Quick Specs Badges -->
            <div style="display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; align-items: center; margin-top: 10px;">
                <span class="tech-badge" style="background: rgba(2, 132, 199, 0.1); color: var(--primary); border-color: rgba(2, 132, 199, 0.25); font-weight: 700; padding: 6px 14px; font-size: 0.82rem;">
                    <i class="fa-solid fa-layer-group" style="margin-right: 5px;"></i>
                    <?= t('5 Dinamik Tablo', '5 Dynamic Tables', '5 Dynamische Tabellen') ?>
                </span>
                <span class="tech-badge" style="background: rgba(16, 185, 129, 0.1); color: #059669; border-color: rgba(16, 185, 129, 0.25); font-weight: 700; padding: 6px 14px; font-size: 0.82rem;">
                    <i class="fa-solid fa-bolt" style="margin-right: 5px;"></i>
                    <?= t('Anlık Arama & Filtreleme', 'Real-Time Search Filter', 'Echtzeit-Suchfilter') ?>
                </span>
                <span class="tech-badge" style="background: rgba(99, 102, 241, 0.1); color: #6366f1; border-color: rgba(99, 102, 241, 0.25); font-weight: 700; padding: 6px 14px; font-size: 0.82rem;">
                    <i class="fa-solid fa-database" style="margin-right: 5px;"></i>
                    <?= t('data.json ile Yönetilebilir', 'Powered by data.json', 'Gesteuert über data.json') ?>
                </span>
                <span class="tech-badge" style="background: rgba(245, 158, 11, 0.1); color: #d97706; border-color: rgba(245, 158, 11, 0.25); font-weight: 700; padding: 6px 14px; font-size: 0.82rem;">
                    <i class="fa-solid fa-mobile-screen" style="margin-right: 5px;"></i>
                    <?= t('Tam Mobil Uyumlu', '100% Mobile Ready', '100% Mobil-optimiert') ?>
                </span>
            </div>
        </header>

        <!-- ── JSON TABLES WORKBENCH ─────────────────────────────────────── -->
        <section class="section-wrapper" style="padding-top: 10px; padding-bottom: 80px;">
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
                        <input type="text" id="tableFilterInput" class="table-search-input" placeholder="<?= t('Tabloda ara (ör. passkey, port 443, *90, yetki)...', 'Search in table (e.g. passkey, port 443, *90)...', 'In Tabelle suchen (z.B. Passkey, Port 443, *90)...') ?>">
                    </div>
                </div>

                <!-- 1. COMPARISON MATRIX TAB -->
                <div class="table-tab-panel active" id="panel-comparison" role="tabpanel">
                    <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 14px; padding: 18px 22px; margin-bottom: 22px; display: flex; align-items: flex-start; gap: 14px;">
                        <i class="fa-solid fa-circle-info" style="color: var(--primary); font-size: 1.3rem; margin-top: 2px;"></i>
                        <div style="font-size: 0.92rem; color: var(--text-muted); line-height: 1.6;">
                            <?= t(
                                '<strong style="color:var(--text-heading);">Neden AiPBX?</strong> Geleneksel donanım santralleri (Cisco, Avaya, Karel) yüksek ilk yatırım ve pahalı lisans kartları gerektirirken, ticari bulut santraller (RingCentral, 8x8) kullanıcı başına aylık 15$-35$ fatura keser. AiPBX, Asterisk 22 ve Passkey/ALPN teknolojilerini birleştirerek kendi donanımınızda sıfır lisans maliyetiyle kurumsal gücü sunar.',
                                '<strong style="color:var(--text-heading);">Why AiPBX?</strong> Legacy hardware PBXs mandate steep upfront hardware costs and per-feature DSP cards, while cloud PBX SaaS platforms bill $15-$35 per user every month. AiPBX unites Asterisk 22, Passkeys, and ALPN stream multiplexing on your own private infrastructure with zero license fees.',
                                '<strong style="color:var(--text-heading);">Warum AiPBX?</strong> Klassische Hardware-Telefonanlagen erfordern hohe Anschaffungskosten und Lizenzkarten, während Cloud-SaaS-Anbieter 15€–35€ pro Nebenstelle monatlich abrechnen. AiPBX bietet volle Enterprise-Leistung mit Asterisk 22 und biometrischen Passkeys auf eigener Infrastruktur bei 0 € Lizenzkosten.'
                            ) ?>
                        </div>
                    </div>

                    <div class="table-responsive-wrapper">
                        <table class="aipbx-data-table">
                            <thead>
                                <tr>
                                    <th style="width: 14%;"><?= t('Kategori', 'Category', 'Kategorie') ?></th>
                                    <th style="width: 26%;"><?= t('Özellik / Yetenek', 'Feature / Capability', 'Funktion / Eigenschaft') ?></th>
                                    <th class="col-aipbx" style="width: 20%;"><i class="fa-solid fa-award" style="margin-right:6px;"></i>AiPBX v1.0 LTS</th>
                                    <th style="width: 18%;"><?= t('Geleneksel Santral', 'Legacy PBX', 'Klassische PBX') ?></th>
                                    <th style="width: 22%;"><?= t('Ticari Bulut PBX', 'Cloud PBX SaaS', 'Cloud PBX SaaS') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tables['comparison'] as $row): ?>
                                <tr>
                                    <td><span class="category-pill"><?= t_field($row, 'category') ?></span></td>
                                    <td>
                                        <strong style="color:var(--text-heading);"><?= t_field($row, 'feature') ?></strong>
                                        <div style="font-size:0.82rem; color:var(--text-muted); margin-top:3px; line-height:1.4;"><?= t_field($row, 'details', 'div') ?></div>
                                    </td>
                                    <td class="col-aipbx">
                                        <span class="status-badge <?= htmlspecialchars($row['aipbxStatus']) ?>">
                                            <?= t_field($row, 'aipbx') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= htmlspecialchars($row['legacyStatus']) ?>">
                                            <?= t_field($row, 'legacy') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= htmlspecialchars($row['cloudStatus']) ?>">
                                            <?= t_field($row, 'cloud') ?>
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
                    <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 14px; padding: 18px 22px; margin-bottom: 22px; display: flex; align-items: flex-start; gap: 14px;">
                        <i class="fa-solid fa-shield-halved" style="color: #10b981; font-size: 1.3rem; margin-top: 2px;"></i>
                        <div style="font-size: 0.92rem; color: var(--text-muted); line-height: 1.6;">
                            <?= t(
                                '<strong style="color:var(--text-heading);">Port 443 ALPN Güvenlik Devrimi:</strong> Klasik santraller internete savunmasız SIP 5060 portunu açmak zorunda kalır ve port tarayıcılarının (SIP scanner) saldırılarına maruz kalır. AiPBX, Nginx L4 Stream modülü ve ALPN denetimi ile TCP 443 üzerinden Web, WebRTC ve SIP TLS paketlerini ayrıştırır. Dış dünyaya 5060 açılmaz, otel ve misafir ağlarında sıfır engel ile bağlanır.',
                                '<strong style="color:var(--text-heading);">Port 443 ALPN Security Breakthrough:</strong> Legacy IP PBXs expose vulnerable SIP port 5060 to the public web, drawing relentless bot scans and brute-force attacks. AiPBX solves this by inspecting TLS ClientHello ALPN protocols on port 443, routing Web, WebRTC WSS, and TLS SIP internally. Zero firewall hassle in hotels and enterprise guest networks.',
                                '<strong style="color:var(--text-heading);">Port 443 ALPN Sicherheits-Vorteil:</strong> Herkömmliche PBX-Systeme müssen den anfälligen SIP-Port 5060 öffnen. AiPBX prüft über Nginx L4 Stream den TLS ALPN-Header auf Port 443 und verteilt HTTPS, WebRTC WSS und TLS-SIP intern. Port 5060 bleibt nach außen geschlossen – ideal für Hotels und Firmennetze.'
                            ) ?>
                        </div>
                    </div>

                    <div class="table-responsive-wrapper">
                        <table class="aipbx-data-table">
                            <thead>
                                <tr>
                                    <th style="width: 16%;"><?= t('Port / Soket', 'Port / Socket', 'Port / Socket') ?></th>
                                    <th style="width: 15%;"><?= t('Protokol', 'Protocol', 'Protokoll') ?></th>
                                    <th style="width: 25%;"><?= t('Servis Adı & Bileşen', 'Service & Component', 'Dienst & Komponente') ?></th>
                                    <th style="width: 26%;"><?= t('Sistem Rolü & Açıklama', 'System Role & Scope', 'Systemrolle & Zweck') ?></th>
                                    <th style="width: 18%;"><?= t('Güvenlik', 'Security', 'Sicherheit') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tables['networkProtocols'] as $pRow): ?>
                                <tr>
                                    <td><span class="chip-port"><?= htmlspecialchars($pRow['port']) ?></span></td>
                                    <td><span class="chip-proto"><?= htmlspecialchars($pRow['protocol']) ?></span></td>
                                    <td><strong style="color:var(--text-heading);"><?= t_field($pRow, 'service') ?></strong></td>
                                    <td style="font-size:0.88rem;"><?= t_field($pRow, 'role') ?></td>
                                    <td><span style="font-size:0.82rem; color:#059669; font-weight:600;"><i class="fa-solid fa-lock" style="margin-right:4px;"></i><?= t_field($pRow, 'security') ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 3. STAR CODES TAB -->
                <div class="table-tab-panel" id="panel-starcodes" role="tabpanel">
                    <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 14px; padding: 18px 22px; margin-bottom: 22px; display: flex; align-items: flex-start; gap: 14px;">
                        <i class="fa-solid fa-asterisk" style="color: var(--primary); font-size: 1.3rem; margin-top: 2px;"></i>
                        <div style="font-size: 0.92rem; color: var(--text-muted); line-height: 1.6;">
                            <?= t(
                                '<strong style="color:var(--text-heading);">Santral Yıldız Kodları (* Codes):</strong> Fiziksel masa telefonu, web softphone ve mobil uygulamalar üzerinden özel tuş kombinasyonlarıyla santral fonksiyonlarını tetikleyebilirsiniz. Örneğin süpervizörler *901002 tuşlayarak 1002 nolu dahiliyi anında gizlice dinleyebilir (Spy), tuşlama ile ajana fısıldayabilir (Whisper) veya çağrıya katılabilir.',
                                '<strong style="color:var(--text-heading);">Telephony Star Codes (* Codes):</strong> Feature access codes dialable from desk phones, browser WebRTC, and mobile softphone apps. For example, supervisors dial *901002 to instantly eavesdrop on extension 1002 silently (Spy), press 5 to whisper coaching, or press 4 to barge in.',
                                '<strong style="color:var(--text-heading);">Telefon-Funktionscodes (* Codes):</strong> Schnellwahltasten für Tischtelefone, WebRTC und Mobil-Apps. Beispielsweise wählt ein Supervisor *901002, um Nebenstelle 1002 lautlos abzuhören (Spy), drückt 5 zum Einflüstern (Whisper) oder 4 zum Aufschalten (Barge-in).'
                            ) ?>
                        </div>
                    </div>

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
                                    <td><strong style="color:var(--text-heading);"><?= t_field($sc, 'name') ?></strong></td>
                                    <td style="font-size:0.88rem;"><?= t_field($sc, 'desc') ?></td>
                                    <td><span class="category-pill"><?= t_field($sc, 'role') ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 4. CALL BARRING TAB -->
                <div class="table-tab-panel" id="panel-permissions" role="tabpanel">
                    <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 14px; padding: 18px 22px; margin-bottom: 22px; display: flex; align-items: flex-start; gap: 14px;">
                        <i class="fa-solid fa-phone-slash" style="color: #ef4444; font-size: 1.3rem; margin-top: 2px;"></i>
                        <div style="font-size: 0.92rem; color: var(--text-muted); line-height: 1.6;">
                            <?= t(
                                '<strong style="color:var(--text-heading);">Arama Kısıtlama ve Yetkilendirme (Call Barring):</strong> Dahili bazında yetkisiz dış hat aramalarını engelleyerek telefon faturası suiistimallerini (toll fraud) önler. Her personele veya departmana uygun bir yetki seviyesi atanır; yetkisiz bir arama yapıldığında santral otomatik olarak "Bu yöne arama yetkiniz bulunmamaktadır" anonsunu dinletir.',
                                '<strong style="color:var(--text-heading);">Call Barring & Toll Fraud Prevention:</strong> Granular dialing permission tiers applied per extension to prevent unauthorized long-distance or premium rate calls. If a restricted destination is dialed, Asterisk plays an automated intercept announcement.',
                                '<strong style="color:var(--text-heading);">Wählberechtigungsgruppen (Call Barring):</strong> Verhindert Gebührenmissbrauch (Toll Fraud) durch zonenbasierte Berechtigungen pro Nebenstelle. Bei unberechtigten Anrufen ertönt automatisch eine Sperransage.'
                            ) ?>
                        </div>
                    </div>

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
                                    <td><strong style="color:var(--text-heading);"><?= t_field($perm, 'level') ?></strong></td>
                                    <td style="font-size:0.9rem;"><?= t_field($perm, 'scope') ?></td>
                                    <td><span class="status-badge" style="background:<?= htmlspecialchars($perm['color']) ?>20; color:<?= htmlspecialchars($perm['color']) ?>; border:1px solid <?= htmlspecialchars($perm['color']) ?>40;"><?= htmlspecialchars($perm['badge']) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 5. SYSTEM REQUIREMENTS TAB -->
                <div class="table-tab-panel" id="panel-requirements" role="tabpanel">
                    <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 14px; padding: 18px 22px; margin-bottom: 22px; display: flex; align-items: flex-start; gap: 14px;">
                        <i class="fa-solid fa-microchip" style="color: #6366f1; font-size: 1.3rem; margin-top: 2px;"></i>
                        <div style="font-size: 0.92rem; color: var(--text-muted); line-height: 1.6;">
                            <?= t(
                                '<strong style="color:var(--text-heading);">Donanım Ölçekleme Rehberi:</strong> AiPBX C diliyle derlenmiş Asterisk 22 ve optimize edilmiş PJSIP çekirdeği kullandığı için son derece az kaynak tüketir. 2 vCPU ve 4 GB RAM ile 100 eşzamanlı görüşme rahatlıkla taşınabilir. Ses kayıtlarının saklanması için NVMe SSD diskler önerilir.',
                                '<strong style="color:var(--text-heading);">Hardware Sizing & Deployment:</strong> Built upon pure C Asterisk 22 and optimized PJSIP, AiPBX exhibits extraordinarily low memory and CPU footprint. An instance with 2 vCPUs and 4 GB RAM smoothly handles 100 concurrent channels. Fast NVMe SSD storage is advised for audio call recording archives.',
                                '<strong style="color:var(--text-heading);">Hardware-Dimensionierung:</strong> Dank optimiertem Asterisk 22 C-Core und PJSIP arbeitet AiPBX extrem ressourceneffizient. 2 vCPUs und 4 GB RAM genügen für 100 parallele Gespräche. Für Anrufaufzeichnungen werden schnelle NVMe-SSDs empfohlen.'
                            ) ?>
                        </div>
                    </div>

                    <div class="table-responsive-wrapper">
                        <table class="aipbx-data-table">
                            <thead>
                                <tr>
                                    <th style="width: 22%;"><?= t('Kapasite Ölçeği', 'Deployment Scale', 'Einsatzbereich') ?></th>
                                    <th style="width: 16%;"><?= t('İşlemci (vCPU)', 'Processor (vCPU)', 'Prozessor (vCPU)') ?></th>
                                    <th style="width: 14%;">RAM</th>
                                    <th style="width: 20%;"><?= t('Disk Depolama', 'Storage', 'Festplatte') ?></th>
                                    <th style="width: 14%;"><?= t('Ağ Hızı', 'Network', 'Netzwerk') ?></th>
                                    <th style="width: 14%;"><?= t('İşletim Sistemi', 'Operating System', 'Betriebssystem') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tables['systemRequirements'] as $req): ?>
                                <tr>
                                    <td><strong style="color:var(--primary);"><?= t_field($req, 'tier') ?></strong></td>
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

                <!-- Admin Link Footer -->
                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px dashed var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; font-size: 0.85rem; color: var(--text-muted);">
                    <div>
                        <i class="fa-solid fa-pen-to-square" style="margin-right: 6px; color: var(--primary);"></i>
                        <?= t('Tablo verileri <code>docs/data.json</code> dosyasından okunmaktadır.', 'Table data is rendered dynamically from <code>docs/data.json</code>.', 'Tabellendaten werden dynamisch aus <code>docs/data.json</code> gerendert.') ?>
                    </div>
                    <div>
                        <a href="/admin/" style="color: var(--primary); font-weight: 600; text-decoration: none;">
                            <i class="fa-solid fa-lock" style="margin-right: 4px;"></i>
                            <?= t('Yönetici Paneli (JSON Düzenle) →', 'Admin Editor (Edit JSON) →', 'Admin-Editor (JSON bearbeiten) →') ?>
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- ── CTA BANNER ────────────────────────────────────────────────── -->
        <section style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #ffffff; padding: 70px 24px; text-align: center; border-top: 1px solid #334155;">
            <div style="max-width: 820px; margin: 0 auto;">
                <h2 style="font-size: clamp(1.8rem, 3vw, 2.4rem); font-weight: 800; margin-bottom: 16px;">
                    <?= t(
                        'Kendi Sunucunuzda Hemen Test Edin',
                        'Deploy and Test on Your Server in 5 Minutes',
                        'In 5 Minuten auf Ihrem eigenen Server testen'
                    ) ?>
                </h2>
                <p style="font-size: 1.05rem; color: #94a3b8; line-height: 1.6; margin-bottom: 28px;">
                    <?= t(
                        'Ubuntu 22.04 / 24.04 veya Debian 12 kurulu temiz bir sanal sunucuda tek satırlık komutla Asterisk 22, Port 443 ALPN ve web yönetim panelini hemen ayağa kaldırın.',
                        'Run our turnkey one-liner on a clean Ubuntu 22.04 / 24.04 or Debian 12 VPS to spin up Asterisk 22, Port 443 ALPN, and the full web interface.',
                        'Führen Sie den Turnkey-Einzeiler auf einem frischen Ubuntu 22.04 / 24.04 oder Debian 12 Server aus, um Asterisk 22, Port 443 ALPN und das Webportal sofort zu starten.'
                    ) ?>
                </p>

                <div style="display: flex; flex-wrap: wrap; gap: 14px; justify-content: center; align-items: center;">
                    <a href="/installation.html" class="btn-primary" style="background: #38bdf8; color: #0f172a; border-color: #38bdf8; font-weight: 700;">
                        <i class="fa-solid fa-terminal" style="margin-right: 6px;"></i>
                        <?= t('Kurulum Kılavuzunu İncele', 'View Installation Guide', 'Installationsanleitung ansehen') ?>
                    </a>
                    <a href="/" class="btn-secondary" style="color: #ffffff; border-color: #475569; background: rgba(255,255,255,0.06);">
                        <i class="fa-solid fa-house" style="margin-right: 6px;"></i>
                        <?= t('Ana Sayfaya Dön', 'Return to Homepage', 'Zur Startseite') ?>
                    </a>
                </div>
            </div>
        </section>
    </main>

    <!-- Universal Footer -->
    <?php include __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
