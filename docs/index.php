<?php
/**
 * AiPBX.bid — Minimalist & High-Performance Corporate Landing Page
 * Clean, fast, modern homepage linking to deep technical subpages.
 * Powered by centralized data.json, full trilingual support (TR/EN/DE), and Schema.org SEO.
 */
$page = 'home';
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
        <!-- ── HERO SECTION (CLEAN & MINIMALIST) ─────────────────────────── -->
        <header class="hero" id="overview">
            <div class="hero-tag">
                <i class="fa-solid fa-sparkles" style="color:var(--primary);"></i>
                <?= t(
                    '✨ Açık Kaynak Kurumsal İletişim • Geliştirici: <a href="https://mhrgl.com" target="_blank" rel="noopener">Mahir Gül</a>',
                    '✨ Open Source Enterprise Communications • Created by <a href="https://mhrgl.com" target="_blank" rel="noopener">Mahir Gül</a>',
                    '✨ Open-Source Enterprise-Kommunikation • Entwickelt von <a href="https://mhrgl.com" target="_blank" rel="noopener">Mahir Gül</a>'
                ) ?>
            </div>

            <h1 data-lang="tr">Kurumsal IP Santral, <span>Amacına Uygun Tasarlandı.</span></h1>
            <h1 data-lang="en">Enterprise IP Telephony, <span>Engineered with Purpose.</span></h1>
            <h1 data-lang="de">Enterprise IP-Telefonie, <span>Konsequent Durchdacht.</span></h1>

            <p data-lang="tr">Asterisk 22, Port 443 Nginx L4 ALPN stream çoklama, WebRTC, Passkey biyometrik kimlik doğrulama, Go anlık sohbet motoru ve yerel Android/iOS softphone istemcilerini birleştiren modern kurumsal santral.</p>
            <p data-lang="en">A high-concurrency, web-managed IP PBX uniting Asterisk 22, Port 443 ALPN stream multiplexing, WebRTC, Passkeys, Go instant messaging, and native Android &amp; iOS softphone clients.</p>
            <p data-lang="de">Eine hochskalierbare Enterprise IP-Telefonanlage mit Asterisk 22, Port 443 ALPN-Multiplexing, WebRTC, biometrischen Passkeys, Go-Echtzeitchat und nativen Mobil-Softphones.</p>

            <div class="hero-actions">
                <a href="#install" class="btn-primary">
                    <i class="fa-solid fa-bolt" style="margin-right:6px;"></i>
                    <?= t('Hızlı Kuruluma Git', 'Quick Install (5 Min)', 'Jetzt Installieren') ?>
                </a>
                <a href="/tables" class="btn-primary" style="background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); border-color: #0284c7;">
                    <i class="fa-solid fa-table-list" style="margin-right:6px;"></i>
                    <?= t('Karşılaştırma & Tablolar', 'Tables & Specs', 'Tabellen & Matrix') ?>
                </a>
                <a href="/features" class="btn-secondary">
                    <i class="fa-solid fa-microchip" style="margin-right:6px;"></i>
                    <?= t('Özellikler', 'Features', 'Funktionen') ?>
                </a>
                <a href="/architecture" class="btn-secondary">
                    <i class="fa-solid fa-sitemap" style="margin-right:6px;"></i>
                    <?= t('Sistem Mimarisi', 'Architecture', 'Architektur') ?>
                </a>
                <a href="/mobile-apps" class="btn-secondary">
                    <i class="fa-solid fa-mobile-screen" style="margin-right:6px;"></i>
                    <?= t('Mobil Softphone', 'Mobile Apps', 'Mobil-Apps') ?>
                </a>
            </div>

            <!-- Terminal Card: One-Liner Turnkey Quick Install -->
            <div class="terminal-container" id="install" style="max-width: 880px; margin: 38px auto 10px auto; border-radius: 18px; box-shadow: 0 20px 50px rgba(15, 23, 42, 0.25);">
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
        </header>

        <!-- ── STATS BAR ─────────────────────────────────────────────────── -->
        <section style="background: #ffffff; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); padding: 40px 24px;">
            <div style="max-width: 1280px; margin: 0 auto; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 24px; text-align: center;">
                <?php foreach ($stats as $st): ?>
                <div style="padding: 12px;">
                    <div style="font-size: clamp(2rem, 3.2vw, 2.6rem); font-weight: 800; color: #0f172a; line-height: 1.1; letter-spacing: -0.5px;"><?= htmlspecialchars($st['value']) ?></div>
                    <div style="font-size: 1rem; font-weight: 700; color: var(--primary); margin-top: 6px;"><?= t_field($st, 'label') ?></div>
                    <div style="font-size: 0.82rem; color: var(--text-muted); margin-top: 4px;"><?= t_field($st, 'sub') ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ── FLAGSHIP TECHNOLOGIES SHOWCASE ───────────────────────────── -->
        <section class="section-wrapper" id="features">
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
                    <h3 class="tech-card-title"><?= t_field($feat, 'title') ?></h3>
                    <p class="tech-card-desc"><?= t_field($feat, 'desc') ?></p>
                    <div style="margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border);">
                        <?php
                            $targetLink = '/features';
                            if ($feat['id'] === 'alpn') $targetLink = '/architecture';
                            elseif ($feat['id'] === 'msteams') $targetLink = '/msteams';
                            elseif ($feat['id'] === 'call_spy') $targetLink = '/callcenter';
                            elseif ($feat['id'] === 'mobile_apps') $targetLink = '/mobile-apps';
                        ?>
                        <a href="<?= $targetLink ?>" style="color: <?= htmlspecialchars($feat['color']) ?>; font-weight: 700; font-size: 0.88rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                            <span><?= t('Teknik Detayları İncele', 'View Technical Deep Dive', 'Technische Details ansehen') ?></span>
                            <i class="fa-solid fa-arrow-right" style="font-size: 0.78rem;"></i>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ── SPOTLIGHT: JSON TABLES SYSTEM ─────────────────────────────── -->
        <section class="section-wrapper" id="tables" style="background: #f8fafc; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); max-width: 100%; padding-left: max(24px, calc((100% - 1280px) / 2)); padding-right: max(24px, calc((100% - 1280px) / 2));">
            <div style="max-width: 1200px; margin: 0 auto;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 40px; align-items: center;">
                    <div>
                        <div class="section-eyebrow">
                            <i class="fa-solid fa-table-list"></i>
                            <?= t('JSON TABLO SİSTEMİ', 'JSON TABLE SYSTEM', 'JSON-TABELLENSYSTEM') ?>
                        </div>
                        <h2 style="font-size: clamp(2rem, 3.2vw, 2.6rem); font-weight: 800; color: var(--text-heading); line-height: 1.25; margin-bottom: 18px; letter-spacing: -0.5px;">
                            <?= t(
                                'Karşılaştırma Matrisi & <span>Teknik Parametreler</span>',
                                'Feature Comparison Matrix & <span>System Parameters</span>',
                                'Vergleichsmatrix & <span>Systemparameter</span>'
                            ) ?>
                        </h2>
                        <p style="font-size: 1.05rem; color: var(--text-muted); line-height: 1.7; margin-bottom: 24px;">
                            <?= t(
                                'AiPBX platformunun Cisco, Avaya ve bulut santrallerle (RingCentral, 8x8) detaylı karşılaştırması, Port 443 ALPN stream çoklama haritası, santral yıldız kodları (*90, *22, *23), arama yetkilendirme (Call Barring) ve donanım gereksinimleri bağımsız tablolarda toplandı.',
                                'Comprehensive side-by-side comparison with Cisco, Avaya, and Cloud PBX SaaS, along with Port 443 ALPN mapping, telephony star codes (*90/*22/*23), outbound call barring, and hardware specs.',
                                'Detaillierter Vergleich mit klassischen Systemen und Cloud-PBX, ergänzt durch Port-443-ALPN-Diagramme, Funktionstastencodes (*90/*22/*23), Wählberechtigungsgruppen und Systemanforderungen.'
                            ) ?>
                        </p>

                        <div style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 30px;">
                            <div style="display: flex; align-items: center; gap: 10px; font-size: 0.95rem; color: var(--text-heading);">
                                <i class="fa-solid fa-circle-check" style="color: #10b981;"></i>
                                <span><?= t('5 Dinamik Tablo (Matris, Portlar, Yıldız Kodları, Yetki, Donanım)', '5 Dynamic Tables (Matrix, Ports, Star Codes, Barring, Sizing)', '5 Dynamische Tabellen (Matrix, Ports, Codes, Rechte, Hardware)') ?></span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px; font-size: 0.95rem; color: var(--text-heading);">
                                <i class="fa-solid fa-circle-check" style="color: #10b981;"></i>
                                <span><?= t('Anlık Canlı Metin Filtreleme ve Arama', 'Instant Real-Time Keyword Filter & Search', 'Echtzeit-Stichwortsuche in Tabellen') ?></span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px; font-size: 0.95rem; color: var(--text-heading);">
                                <i class="fa-solid fa-circle-check" style="color: #10b981;"></i>
                                <span><?= t('TR / EN / DE Otomatik IP ve Dil Desteği', 'Instant TR / EN / DE Multilingual Switching', 'Dreisprachig mit synchroner Umschaltung') ?></span>
                            </div>
                        </div>

                        <a href="/tables" class="btn-primary" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 700; padding: 14px 28px; font-size: 1rem;">
                            <i class="fa-solid fa-table-list"></i>
                            <span><?= t('Tüm Tabloları & Karşılaştırmayı Aç →', 'Explore Full Tables & Comparison Matrix →', 'Alle Tabellen & Matrix Öffnen →') ?></span>
                        </a>
                    </div>

                    <!-- Visual Teaser Cards of the Tables -->
                    <div style="display: grid; grid-template-columns: 1fr; gap: 16px;">
                        <a href="/tables" style="background:#ffffff; border:1px solid var(--border); border-radius:16px; padding:20px 24px; text-decoration:none; display:flex; align-items:center; justify-content:space-between; transition: all 0.2s ease; box-shadow: 0 4px 12px rgba(0,0,0,0.03);" onmouseover="this.style.borderColor='var(--primary)'; this.style.transform='translateX(4px)';" onmouseout="this.style.borderColor='var(--border)'; this.style.transform='none';">
                            <div style="display:flex; align-items:center; gap:16px;">
                                <div style="width:44px; height:44px; border-radius:12px; background:rgba(2,132,199,0.1); color:var(--primary); display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
                                    <i class="fa-solid fa-scale-balanced"></i>
                                </div>
                                <div>
                                    <div style="font-weight:700; color:var(--text-heading); font-size:1rem;"><?= t('Karşılaştırma Matrisi', 'Comparison Matrix', 'Vergleichsmatrix') ?></div>
                                    <div style="font-size:0.82rem; color:var(--text-muted);"><?= t('AiPBX vs Eski Santraller vs Bulut SaaS', 'AiPBX vs Legacy vs Cloud SaaS', 'AiPBX vs Klassisch vs Cloud SaaS') ?></div>
                                </div>
                            </div>
                            <span class="status-badge yes"><?= t('İncele', 'View', 'Öffnen') ?></span>
                        </a>

                        <a href="/tables" style="background:#ffffff; border:1px solid var(--border); border-radius:16px; padding:20px 24px; text-decoration:none; display:flex; align-items:center; justify-content:space-between; transition: all 0.2s ease; box-shadow: 0 4px 12px rgba(0,0,0,0.03);" onmouseover="this.style.borderColor='#10b981'; this.style.transform='translateX(4px)';" onmouseout="this.style.borderColor='var(--border)'; this.style.transform='none';">
                            <div style="display:flex; align-items:center; gap:16px;">
                                <div style="width:44px; height:44px; border-radius:12px; background:rgba(16,185,129,0.1); color:#059669; display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
                                    <i class="fa-solid fa-network-wired"></i>
                                </div>
                                <div>
                                    <div style="font-weight:700; color:var(--text-heading); font-size:1rem;"><?= t('Ağ Portları & ALPN Çoklama', 'Network Ports & ALPN Stream', 'Netzwerk-Ports & ALPN') ?></div>
                                    <div style="font-size:0.82rem; color:var(--text-muted);"><?= t('Port 443, SIP TLS 5061, SRTP, Go Chat', 'Port 443, SIP TLS 5061, SRTP, Go Chat', 'Port 443, SIP TLS 5061, SRTP, Chat') ?></div>
                                </div>
                            </div>
                            <span class="status-badge yes"><?= t('İncele', 'View', 'Öffnen') ?></span>
                        </a>

                        <a href="/tables" style="background:#ffffff; border:1px solid var(--border); border-radius:16px; padding:20px 24px; text-decoration:none; display:flex; align-items:center; justify-content:space-between; transition: all 0.2s ease; box-shadow: 0 4px 12px rgba(0,0,0,0.03);" onmouseover="this.style.borderColor='#f59e0b'; this.style.transform='translateX(4px)';" onmouseout="this.style.borderColor='var(--border)'; this.style.transform='none';">
                            <div style="display:flex; align-items:center; gap:16px;">
                                <div style="width:44px; height:44px; border-radius:12px; background:rgba(245,158,11,0.1); color:#d97706; display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
                                    <i class="fa-solid fa-asterisk"></i>
                                </div>
                                <div>
                                    <div style="font-weight:700; color:var(--text-heading); font-size:1rem;"><?= t('Yıldız Kodları Kılavuzu', 'Star Codes Directory', 'Funktionstastencodes') ?></div>
                                    <div style="font-size:0.82rem; color:var(--text-muted);"><?= t('*90 Dinleme, *22/*23 Mola, *97/*98 Sesli Mesaj', '*90 Spy, *22/*23 Breaks, *97 Voicemail', '*90 Spy, *22/*23 Pausen, *97 Voicemail') ?></div>
                                </div>
                            </div>
                            <span class="status-badge yes"><?= t('İncele', 'View', 'Öffnen') ?></span>
                        </a>

                        <a href="/tables" style="background:#ffffff; border:1px solid var(--border); border-radius:16px; padding:20px 24px; text-decoration:none; display:flex; align-items:center; justify-content:space-between; transition: all 0.2s ease; box-shadow: 0 4px 12px rgba(0,0,0,0.03);" onmouseover="this.style.borderColor='#6366f1'; this.style.transform='translateX(4px)';" onmouseout="this.style.borderColor='var(--border)'; this.style.transform='none';">
                            <div style="display:flex; align-items:center; gap:16px;">
                                <div style="width:44px; height:44px; border-radius:12px; background:rgba(99,102,241,0.1); color:#6366f1; display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
                                    <i class="fa-solid fa-server"></i>
                                </div>
                                <div>
                                    <div style="font-weight:700; color:var(--text-heading); font-size:1rem;"><?= t('Arama Yetkisi & Donanım Rehberi', 'Dial Barring & System Specs', 'Wählberechtigung & Hardware') ?></div>
                                    <div style="font-size:0.82rem; color:var(--text-muted);"><?= t('6 Yetki Kademesi & 4 Sunucu Ölçeği', '6 Permission Tiers & 4 Hardware Scales', '6 Stufen & 4 Hardware-Skalen') ?></div>
                                </div>
                            </div>
                            <span class="status-badge yes"><?= t('İncele', 'View', 'Öffnen') ?></span>
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- ── ARCHITECTURE SNAPSHOT ─────────────────────────────────────── -->
        <section class="section-wrapper" id="architecture">
            <div class="section-header-center">
                <div class="section-eyebrow">
                    <i class="fa-solid fa-code-fork"></i>
                    <?= t('MİMARİ BAKIŞ', 'ARCHITECTURE SNAPSHOT', 'ARCHITEKTUR-ÜBERSICHT') ?>
                </div>
                <h2 class="section-title-large">
                    <?= t('Port 443 L4 ALPN Stream Çoklama Mimarisi', 'Port 443 L4 ALPN Stream Multiplexing', 'Port 443 L4 ALPN Stream Multiplexing') ?>
                </h2>
                <p class="section-subtitle-muted">
                    <?= t(
                        'İnternete savunmasız SIP 5060 portunu açmadan tek bir TCP 443 portundan HTTPS, WebRTC WSS ve TLS SIP trafiğini güvenle yönlendirin.',
                        'Never expose vulnerable SIP port 5060 to the public web. Multiplex HTTPS, WebRTC, and TLS SIP over a single TCP port 443.',
                        'Kein offener Port 5060 im Internet: HTTPS, WebRTC und TLS-SIP sicher über einen einzigen TCP-Port 443 betreiben.'
                    ) ?>
                </p>
            </div>

            <!-- Architecture ASCII Flow Diagram -->
            <div class="diagram-terminal" style="max-width: 960px; margin: 0 auto 30px auto;">
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

            <div style="text-align: center; margin-top: 24px;">
                <a href="/architecture" class="btn-primary" style="display: inline-flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-sitemap"></i>
                    <span><?= t('Kapsamlı Sistem Mimarisini İncele (Detaylı Kılavuz) →', 'Explore Full Architecture & Sequence Diagrams →', 'Vollständige Systemarchitektur öffnen →') ?></span>
                </a>
            </div>
        </section>

        <!-- ── CURATED FAQ SECTION ───────────────────────────────────────── -->
        <section class="section-wrapper" id="faq" style="background: #f8fafc; border-top: 1px solid var(--border);">
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
                        'AiPBX platformunun mimarisi, kurulum süreci, güvenliği ve açık kaynak lisansı hakkında en çok merak edilen konular.',
                        'Direct answers regarding AiPBX architecture, turnkey deployment, security posture, and MIT licensing.',
                        'Häufige Fragen zu Architektur, Installation, Unternehmenssicherheit und MIT-Lizenzierung von AiPBX.'
                    ) ?>
                </p>
            </div>

            <div class="faq-accordion" style="max-width: 900px; margin: 0 auto;">
                <?php foreach (array_slice($faq, 0, 5) as $idx => $fItem): ?>
                <div class="faq-item <?= ($idx === 0) ? 'active' : '' ?>">
                    <div class="faq-question">
                        <?= t_field($fItem, 'q') ?>
                        <i class="fa-solid fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">
                        <div data-lang="tr"><p><?= nl2br(htmlspecialchars($fItem['aTR'] ?? ($fItem['a'] ?? ''))) ?></p></div>
                        <div data-lang="en"><p><?= nl2br(htmlspecialchars($fItem['aEN'] ?? ($fItem['aTR'] ?? ''))) ?></p></div>
                        <div data-lang="de"><p><?= nl2br(htmlspecialchars($fItem['aDE'] ?? ($fItem['aEN'] ?? ''))) ?></p></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="text-align: center; margin-top: 36px;">
                <p style="font-size: 0.95rem; color: var(--text-muted); margin-bottom: 16px;">
                    <?= t('Daha fazla teknik bilgi veya adım adım kurulum yönergeleri mi arıyorsunuz?', 'Looking for more in-depth guides or step-by-step setup tutorials?', 'Suchen Sie nach weiteren technischen Leitfäden oder Anleitungen?') ?>
                </p>
                <div style="display: flex; flex-wrap: wrap; gap: 12px; justify-content: center;">
                    <a href="/installation" class="btn-secondary">
                        <i class="fa-solid fa-terminal" style="margin-right: 6px;"></i>
                        <?= t('Kurulum Kılavuzu', 'Installation Guide', 'Installationsanleitung') ?>
                    </a>
                    <a href="/msteams" class="btn-secondary">
                        <i class="fa-brands fa-microsoft" style="margin-right: 6px;"></i>
                        Microsoft Teams Direct Routing
                    </a>
                    <a href="/security" class="btn-secondary">
                        <i class="fa-solid fa-shield-halved" style="margin-right: 6px;"></i>
                        <?= t('Güvenlik & SBC', 'Security & SBC', 'Sicherheit & SBC') ?>
                    </a>
                </div>
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
