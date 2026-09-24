<?php
/**
 * AiPBX.bid — Microsoft Teams Entegrasyonu & Direct Routing — Asterisk 22 SBC Gateway | AiPBX
 */
$page = 'msteams';
require_once __DIR__ . '/includes/data.php';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($LANG) ?>" data-lang="<?= htmlspecialchars($LANG) ?>">
<head>
    <?php include __DIR__ . '/includes/head.php'; ?>
    <?php include __DIR__ . '/includes/seo-helper.php'; ?>
</head>


<body>
    <!-- Top Announcement Banner & Main Navigation Bar -->
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <div class="docs-layout">

        <!-- Left Sidebar Navigation -->
        <aside class="docs-sidebar">
            <div class="sidebar-search">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" id="sidebarSearch" placeholder="Dokümantasyonda ara... / Search...">
            </div>

            <div class="sidebar-group">
                <div class="sidebar-title">
                    <span data-lang="tr">Bu Sayfada (Hızlı Gezinti)</span>
                    <span data-lang="en">On This Page</span>
                    <span data-lang="de">Auf dieser Seite</span>
                </div>
                <ul class="sidebar-menu">
                    <li><a href="#overview"><span data-lang="tr">🌟 Genel Bakış &amp; Avantajlar</span><span data-lang="en">🌟 Overview &amp; Benefits</span><span data-lang="de">🌟 Übersicht &amp; Vorteile</span></a></li>
                    <li><a href="#architecture"><span data-lang="tr">🏗️ Direct Routing Mimarisi</span><span data-lang="en">🏗️ Direct Routing Architecture</span><span data-lang="de">🏗️ Direct-Routing-Architektur</span></a></li>
                    <li><a href="#prerequisites"><span data-lang="tr">📋 Ön Koşullar &amp; Lisanslar</span><span data-lang="en">📋 Prerequisites &amp; Licenses</span><span data-lang="de">📋 Voraussetzungen &amp; Lizenzen</span></a></li>
                    <li><a href="#firewall"><span data-lang="tr">🌐 Portlar &amp; Güvenlik Duvarı</span><span data-lang="en">🌐 Ports &amp; Firewall Rules</span><span data-lang="de">🌐 Ports &amp; Firewall-Regeln</span></a></li>
                    <li><a href="#asterisk-pjsip"><span data-lang="tr">⚙️ Asterisk 22 &amp; PJSIP Yapılandırması</span><span data-lang="en">⚙️ Asterisk 22 &amp; PJSIP Config</span><span data-lang="de">⚙️ Asterisk 22 &amp; PJSIP Konfig</span></a></li>
                    <li><a href="#m365-powershell"><span data-lang="tr">💻 M365 PowerShell Adımları</span><span data-lang="en">💻 M365 PowerShell Setup</span><span data-lang="de">💻 M365 PowerShell Schritte</span></a></li>
                    <li><a href="#call-flows"><span data-lang="tr">📞 Çağrı Akışları &amp; Senaryolar</span><span data-lang="en">📞 Call Scenarios &amp; Flows</span><span data-lang="de">📞 Anrufszenarien &amp; Abläufe</span></a></li>
                    <li><a href="#webhooks"><span data-lang="tr">💬 Teams Webhook Bildirimleri</span><span data-lang="en">💬 Teams Webhook Alerts</span><span data-lang="de">💬 Teams-Webhook-Benachrichtigungen</span></a></li>
                    <li><a href="#troubleshooting"><span data-lang="tr">🔍 Sorun Giderme &amp; Tanı</span><span data-lang="en">🔍 Troubleshooting &amp; Diagnostics</span><span data-lang="de">🔍 Fehlerbehebung &amp; Diagnose</span></a></li>
                </ul>
            </div>

            <div class="sidebar-group">
                <div class="sidebar-title">
                    <span data-lang="tr">Kurumsal Entegrasyonlar</span>
                    <span data-lang="en">Enterprise Integrations</span>
                    <span data-lang="de">Enterprise-Integrationen</span>
                </div>
                <ul class="sidebar-menu">
                    <li>
                        <a href="/msteams" class="active">
                            <span data-lang="tr">💼 Microsoft Teams Direct Routing <span class="sidebar-badge">Yeni</span></span>
                            <span data-lang="en">💼 Microsoft Teams Direct Routing <span class="sidebar-badge">New</span></span>
                            <span data-lang="de">💼 Microsoft Teams Direct Routing <span class="sidebar-badge">Neu</span></span>
                        </a>
                    </li>
                    <li>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="sidebar-group">
                <div class="sidebar-title">
                    <span data-lang="tr">Platform &amp; Mimari</span>
                    <span data-lang="en">Platform &amp; Architecture</span>
                    <span data-lang="de">Plattform &amp; Architektur</span>
                </div>
                <ul class="sidebar-menu">
                    <li>
                        <a href="/">
                            <span data-lang="tr">🏠 Genel Bakış (Overview)</span>
                            <span data-lang="en">🏠 Overview (Home)</span>
                            <span data-lang="de">🏠 Übersicht (Home)</span>
                        </a>
                    </li>
                    <li>
                        <a href="/architecture">
                            <span data-lang="tr">🏗️ Sistem Mimarisi &amp; ALPN</span>
                            <span data-lang="en">🏗️ Architecture &amp; ALPN</span>
                            <span data-lang="de">🏗️ Architektur &amp; ALPN</span>
                        </a>
                    </li>
                    <li>
                        <a href="/features">
                            <span data-lang="tr">⚡ Santral Modülleri</span>
                            <span data-lang="en">⚡ PBX Modules</span>
                            <span data-lang="de">⚡ PBX-Module</span>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="sidebar-group">
                <div class="sidebar-title">
                    <span data-lang="tr">Yönetim &amp; Güvenlik</span>
                    <span data-lang="en">Admin &amp; Security</span>
                    <span data-lang="de">Admin &amp; Sicherheit</span>
                </div>
                <ul class="sidebar-menu">
                    <li>
                        <a href="/installation">
                            <span data-lang="tr">🚀 Hızlı Kurulum (install.sh)</span>
                            <span data-lang="en">🚀 Quick Install (install.sh)</span>
                            <span data-lang="de">🚀 Schnellinstallation (install.sh)</span>
                        </a>
                    </li>
                    <li>
                        <a href="/installation#ports">
                            <span data-lang="tr">🌐 Port &amp; Güvenlik Duvarı</span>
                            <span data-lang="en">🌐 Ports &amp; Firewall</span>
                            <span data-lang="de">🌐 Ports &amp; Firewall</span>
                        </a>
                    </li>
                    <li>
                        <a href="/security">
                            <span data-lang="tr">🛡️ Güvenlik &amp; Fail2ban</span>
                            <span data-lang="en">🛡️ Security &amp; Fail2ban</span>
                            <span data-lang="de">🛡️ Sicherheit &amp; Fail2ban</span>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="sidebar-group">
                <div class="sidebar-title">
                    <span data-lang="tr">Geliştirici &amp; API</span>
                    <span data-lang="en">Developer &amp; API</span>
                    <span data-lang="de">Entwickler &amp; API</span>
                </div>
                <ul class="sidebar-menu">
                    <li>
                        <a href="/api-docs">
                            <span data-lang="tr">🔌 REST API Referansı</span>
                            <span data-lang="en">🔌 REST API Reference</span>
                            <span data-lang="de">🔌 REST-API Referenz</span>
                        </a>
                    </li>
                    <li>
                        <a href="/api-docs#websocket">
                            <span data-lang="tr">💬 Go WebSocket Protokolü</span>
                            <span data-lang="en">💬 Go WebSocket Protocol</span>
                            <span data-lang="de">💬 Go WebSocket Protokoll</span>
                        </a>
                    </li>
                </ul>
            </div>
        </aside>

        <!-- Main Documentation Content -->
        <main class="docs-content">
            <div class="docs-breadcrumb">
                <a href="/">
                    <span data-lang="tr">Ana Sayfa</span>
                    <span data-lang="en">Home</span>
                    <span data-lang="de">Startseite</span>
                </a> / 
                <a href="/features">
                    <span data-lang="tr">Entegrasyonlar</span>
                    <span data-lang="en">Integrations</span>
                    <span data-lang="de">Integrationen</span>
                </a> / 
                <span>
                    <span data-lang="tr">Microsoft Teams Direct Routing</span>
                    <span data-lang="en">Microsoft Teams Direct Routing</span>
                    <span data-lang="de">Microsoft Teams Direct Routing</span>
                </span>
            </div>

            <div class="docs-header">
                <span class="section-badge" style="background: rgba(99, 102, 241, 0.15); border: 1px solid rgba(99, 102, 241, 0.35); color: #818cf8; padding: 4px 14px; border-radius: 20px; font-size: 0.8rem; font-weight: 700; text-transform: uppercase;">
                    💼 Enterprise Hybrid Telephony · Microsoft 365
                </span>
                <h1 style="margin-top: 14px;">
                    <span data-lang="tr">💼 Microsoft Teams Entegrasyonu &amp; Direct Routing SBC Gateway</span>
                    <span data-lang="en">💼 Microsoft Teams Direct Routing &amp; SBC Gateway Integration</span>
                    <span data-lang="de">💼 Microsoft Teams Direct Routing &amp; SBC-Gateway-Integration</span>
                </h1>
                <p class="lead" data-lang="tr">
                    AiPBX Asterisk 22 motorunu doğrudan Microsoft 365 Teams Phone System bulutuna bağlayın. Mevcut Türk Telekom veya operatör SIP trunk hatlarınızı değiştirmeden, fahiş Microsoft arama tarifelerine katlanmadan, Teams kullanıcılarını kurum içi dahili santral sisteminizle uçtan uca birleştirin.
                </p>
                <p class="lead" data-lang="en">
                    Bridge your AiPBX Asterisk 22 telephony infrastructure with Microsoft 365 Teams Phone System. Retain existing local SIP carrier trunks without paying expensive Microsoft Calling Plan fees, seamlessly integrating Teams desktop/mobile clients with internal extensions and call queues.
                </p>
                <p class="lead" data-lang="de">
                    Verbinden Sie Ihre AiPBX Asterisk 22-Infrastruktur direkt mit dem Microsoft 365 Teams Phone System. Behalten Sie bestehende lokale SIP-Trunks ohne teure Microsoft Calling Plans und integrieren Sie Teams-Clients nahtlos in Nebenstellen und Warteschlangen.
                </p>
            </div>

            <!-- Section 1: Overview & Benefits -->
            <section class="docs-section" id="overview">
                <h2>
                    <span data-lang="tr">1. Genel Bakış &amp; Neden Direct Routing?</span>
                    <span data-lang="en">1. Overview &amp; Why Direct Routing?</span>
                    <span data-lang="de">1. Übersicht &amp; Warum Direct Routing?</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        Microsoft Teams, günümüz kurumsal dünyasında en yaygın kullanılan iş birliği aracıdır. Ancak kurumsal telefon görüşmeleri söz konusu olduğunda Microsoft'un sunduğu yerel arama paketleri (Calling Plans) hem son derece maliyetlidir hem de Türkiye ve pek çok ülkede yerel numara taşıma ve regülasyon engellerine takılmaktadır.
                    </p>
                    <p>
                        <strong>AiPBX Direct Routing Gateway</strong> çözümü ile AiPBX, Microsoft Teams Phone System için bir <strong>Session Border Controller (SBC)</strong> görevi üstlenir. Bu sayede:
                    </p>
                    <ul>
                        <li><strong>Operatör Özgürlüğü:</strong> Mevcut Türk Telekom, Vodafone, Turkcell Superonline veya alternatif SIP trunk sağlayıcılarınızı olduğu gibi kullanmaya devam edersiniz.</li>
                        <li><strong>%70'e Varan Maliyet Tasarrufu:</strong> Microsoft'un kullanıcı başı aylık yüksek uluslararası/yerel arama paketleri yerine, mevcut yerel operatör tarifenizden faydalanırsınız.</li>
                        <li><strong>Hibrit İletişim:</strong> Microsoft Teams kullanan beyaz yaka çalışanlar ile sahadaki Android/iOS AiPBX softphone kullananlar, fabrika/depodaki analog dahili telefonlar ve masaüstü IP telefonlar arasında <strong>ücretsiz 3-4 haneli dahili arama</strong> gerçekleşir.</li>
                        <li><strong>Gelişmiş Santral Yetenekleri:</strong> Teams istemcileri AiPBX'in çok seviyeli IVR sesli yanıt sistemine, çağrı merkezi ACD kuyruklarına (*22/*23 mola kodları dahil), ses kayıt arşivine ve T.38 dijital faks sunucusuna entegre olur.</li>
                    </ul>
                </div>
                <div data-lang="en">
                    <p>
                        While Microsoft Teams dominates enterprise collaboration, Microsoft's native Calling Plans are notoriously expensive and face severe regulatory and porting restrictions across many regions.
                    </p>
                    <p>
                        <strong>AiPBX Direct Routing Gateway</strong> acts as a certified-compatible <strong>Session Border Controller (SBC)</strong> between Microsoft 365 Phone System and your local telecommunications trunks. Key advantages:
                    </p>
                    <ul>
                        <li><strong>Carrier Freedom:</strong> Retain existing SIP trunks and competitive local telco contracts without vendor lock-in.</li>
                        <li><strong>Up to 70% Cost Reduction:</strong> Eliminate per-user monthly Microsoft Calling Plan fees.</li>
                        <li><strong>Hybrid Intercom:</strong> Seamless 3 or 4-digit extension-to-extension dialing between Teams users, physical desk phones, WebRTC browser softphones, and native mobile clients.</li>
                        <li><strong>Enterprise PBX Features:</strong> Teams users connect directly to AiPBX multi-level IVRs, ACD call center queues (with *22/*23 break codes), encrypted call recording, and T.38 digital faxing.</li>
                    </ul>
                </div>
                <div data-lang="de">
                    <p>
                        Microsoft Teams ist die führende Kollaborationsplattform, aber Microsoft Calling Plans sind teuer und in vielen Ländern stark reglementiert.
                    </p>
                    <p>
                        Mit dem <strong>AiPBX Direct Routing Gateway</strong> fungiert AiPBX als <strong>Session Border Controller (SBC)</strong> zwischen Microsoft 365 und Ihren lokalen Telekommunikationstrunks.
                    </p>
                    <ul>
                        <li><strong>Freie Anbieterwahl:</strong> Behalten Sie bestehende SIP-Trunk-Verträge zu gewohnt günstigen Konditionen.</li>
                        <li><strong>Bis zu 70% Kostensenkung:</strong> Keine teuren monatlichen Microsoft-Calling-Plan-Gebühren pro Benutzer.</li>
                        <li><strong>Hybride Nebenstellen:</strong> Kostenlose interne Kurzwahlen zwischen Teams-Nutzern, Tischtelefonen, WebRTC und Mobil-Apps.</li>
                        <li><strong>Vollwertige PBX-Funktionen:</strong> IVR-Sprachmenüs, ACD-Warteschlangen, Gesprächsaufzeichnung und digitaler Faxserver.</li>
                    </ul>
                </div>

                <!-- Comparison Table -->
                <div class="docs-table-wrapper" style="margin-top: 24px;">
                    <table class="docs-table">
                        <thead>
                            <tr>
                                <th><span data-lang="tr">Karşılaştırma Kriteri</span><span data-lang="en">Feature Matrix</span><span data-lang="de">Funktionsmatrix</span></th>
                                <th><span data-lang="tr">Microsoft Yerel Calling Plan</span><span data-lang="en">Microsoft Native Calling Plan</span><span data-lang="de">Microsoft Native Calling Plan</span></th>
                                <th style="color: #38bdf8;"><span data-lang="tr">AiPBX Direct Routing (Önerilen)</span><span data-lang="en">AiPBX Direct Routing (Recommended)</span><span data-lang="de">AiPBX Direct Routing (Empfohlen)</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong><span data-lang="tr">Aylık Operatör Maliyeti</span><span data-lang="en">Monthly Telecom Cost</span><span data-lang="de">Monatliche Telekom-Kosten</span></strong></td>
                                <td style="color: #f87171;"><span data-lang="tr">Kullanıcı başı 12$ - 24$ / ay</span><span data-lang="en">$12 - $24 per user / month</span><span data-lang="de">12€ - 24€ pro Benutzer / Monat</span></td>
                                <td style="color: #34d399;"><strong><span data-lang="tr">0$ Ek Ücret (Mevcut SIP trunk tarifesi)</span><span data-lang="en">$0 Extra (Uses existing SIP trunk rates)</span><span data-lang="de">0€ Extra (Nutzt bestehende SIP-Tarife)</span></strong></td>
                            </tr>
                            <tr>
                                <td><strong><span data-lang="tr">Dahili Telefon &amp; IP Phone Desteği</span><span data-lang="en">Desk Phone &amp; Intercom</span><span data-lang="de">Tischtelefone &amp; Gegensprechanlage</span></strong></td>
                                <td><span data-lang="tr">Yalnızca pahalı Teams sertifikalı IP telefonlar</span><span data-lang="en">Only expensive Teams-certified phones</span><span data-lang="de">Nur teure Teams-zertifizierte Telefone</span></td>
                                <td style="color: #34d399;"><span data-lang="tr">Tüm standart SIP telefonlar (Yealink, Grandstream, Fanvil vb.) + WebRTC</span><span data-lang="en">All standard SIP hardware (Yealink, Grandstream, etc.) + WebRTC</span><span data-lang="de">Alle Standard-SIP-Telefone + WebRTC</span></td>
                            </tr>
                            <tr>
                                <td><strong><span data-lang="tr">Çağrı Merkezi &amp; Mola Takibi</span><span data-lang="en">Call Center &amp; Agent Breaks</span><span data-lang="de">Callcenter &amp; Pausenerfassung</span></strong></td>
                                <td><span data-lang="tr">Basit kuyruk, mola kodları ve detaylı raporlama yok</span><span data-lang="en">Basic queues, no granular break codes</span><span data-lang="de">Einfache Warteschlangen, keine Pausencodes</span></td>
                                <td style="color: #34d399;"><span data-lang="tr">Tam teşekküllü ACD, *22/*23 mola kodları, SLA wallboard &amp; *90 dinleme</span><span data-lang="en">Full ACD queues, *22/*23 break codes, live wallboard &amp; *90 spy</span><span data-lang="de">Vollwertiges ACD, *22/*23 Pausencodes, Live-Wallboard &amp; *90 Mithören</span></td>
                            </tr>
                            <tr>
                                <td><strong><span data-lang="tr">Gelen &amp; Giden Faks (T.38)</span><span data-lang="en">Inbound/Outbound Fax (T.38)</span><span data-lang="de">Faxempfang/-versand (T.38)</span></strong></td>
                                <td style="color: #f87171;"><span data-lang="tr">Desteklenmiyor</span><span data-lang="en">Not supported</span><span data-lang="de">Nicht unterstützt</span></td>
                                <td style="color: #34d399;"><span data-lang="tr">T.38 ve SpanDSP destekli dahili faks sunucusu</span><span data-lang="en">Full T.38 &amp; SpanDSP digital fax server</span><span data-lang="de">Integrierter T.38 &amp; SpanDSP Faxserver</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Section 2: Architecture & Topology -->
            <section class="docs-section" id="architecture">
                <h2>
                    <span data-lang="tr">2. Mimari Şema &amp; Çalışma Prensibi</span>
                    <span data-lang="en">2. Architecture &amp; Topology</span>
                    <span data-lang="de">2. Architektur &amp; Topologie</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        Microsoft Teams Direct Routing sinyalleşmesi <strong>SIP over TLS (Port 5061)</strong> üzerinden, ses akışları ise <strong>SRTP (Secure Real-time Transport Protocol - SDES)</strong> üzerinden şifreli olarak taşınır. AiPBX sunucusu, Microsoft'un bulut proxy'leri ile yerel ağınız arasında güvenli bir köprü (SBC) oluşturur:
                    </p>
                </div>
                <div data-lang="en">
                    <p>
                        Microsoft Teams Direct Routing mandates <strong>SIP over TLS (Port 5061)</strong> for signaling and <strong>SRTP (SDES encryption)</strong> for audio streams. AiPBX operates as a secure SBC edge gateway interfacing between Microsoft 365 cloud proxies and your enterprise infrastructure:
                    </p>
                </div>
                <div data-lang="de">
                    <p>
                        Microsoft Teams Direct Routing erfordert <strong>SIP über TLS (Port 5061)</strong> für die Signalisierung und <strong>SRTP (SDES-Verschlüsselung)</strong> für Audio. AiPBX fungiert als sicherer SBC-Edge-Knoten:
                    </p>
                </div>

                <!-- Interactive Architecture ASCII / Diagram Box -->
                <div class="docs-code-block" style="background: #070b14; border-color: #243552;">
                    <div class="docs-code-header">
                        <span><span data-lang="tr">Direct Routing Ağ Topolojisi</span><span data-lang="en">Direct Routing Network Topology</span><span data-lang="de">Direct Routing Netzwerk-Topologie</span></span>
                        <span class="tag-badge tag-blue">TLS 1.2 · SRTP · Port 5061</span>
                    </div>
                    <div class="docs-code-body">
<pre style="color: #38bdf8; font-family: 'JetBrains Mono', monospace; font-size: 0.85rem; line-height: 1.45;"><code>┌─────────────────────────────────────────────────────────────────────────────┐
│                    MICROSOFT 365 CLOUD INFRASTRUCTURE                       │
│                                                                             │
│   [ Teams Desktop ]       [ Teams Mobile (iOS/Android) ]      [ Teams Web ]  │
│           │                               │                        │        │
│           └───────────────────────┬───────┴────────────────────────┘        │
│                                   ▼                                         │
│                 Microsoft Teams Phone System Core                           │
│                                   │                                         │
│                sip.pstnhub.microsoft.com (EU / Primary)                     │
│                sip2.pstnhub.microsoft.com (US / Secondary)                  │
│                sip3.pstnhub.microsoft.com (APAC / Tertiary)                 │
└───────────────────────────────────┬─────────────────────────────────────────┘
                                    │
                                    │  SIP over TLS (Port 5061)
                                    │  SRTP Media (UDP 10000-20000)
                                    │  Trusted CA SSL (Let's Encrypt / DigiCert)
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                    AiPBX SESSION BORDER CONTROLLER (SBC)                    │
│                                                                             │
│   FQDN: sbc.yourdomain.com / IP: 212.147.x.x                                │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │ Asterisk 22 PJSIP Signaling & Media Engine                          │   │
│   │  • TLS Transport (Port 5061) - Strict Cipher Suites (AES256/GCM)    │   │
│   │  • SDES-SRTP Audio Transcoding (SILK, Opus, G.711a, G.711u)         │   │
│   │  • Bi-directional SIP OPTIONS Heartbeat Monitoring (60s Keepalive)  │   │
│   │  • E.164 Inbound/Outbound Route Normalization & Dialplan            │   │
│   └──────────────────────────────────┬──────────────────────────────────┘   │
└──────────────────────────────────────┼──────────────────────────────────────┘
                                       │
           ┌───────────────────────────┼───────────────────────────┐
           ▼                           ▼                           ▼
┌─────────────────────┐     ┌─────────────────────┐     ┌─────────────────────┐
│  KURUMSAL DAHİLİLER │     │ ÇAĞRI MERKEZİ & IVR │     │  TELEKOM TRUNK'LAR  │
│  • Yealink/Fanvil   │     │  • ACD Kuyrukları   │     │  • Türk Telekom     │
│  • AiPBX WebRTC Web │     │  • *22/*23 Mola Takip│    │  • Turkcell / Voda  │
│  • Android/iOS Apps │     │  • Ses Kayıt & SLA  │     │  • Alternatif SIP   │
└─────────────────────┘     └─────────────────────┘     └─────────────────────┘</code></pre>
                    </div>
                </div>

                <div class="callout callout-info" style="margin-top: 20px;">
                    <span class="callout-icon">💡</span>
                    <div class="callout-body">
                        <strong data-lang="tr">SIP OPTIONS Nabız (Heartbeat) Mekanizması:</strong>
                        <strong data-lang="en">SIP OPTIONS Heartbeat Mechanism:</strong>
                        <strong data-lang="de">SIP OPTIONS Heartbeat-Mechanismus:</strong>
                        <span data-lang="tr">
                            Microsoft Teams Phone System ve AiPBX, trunk hattının canlı olduğunu teyit etmek için karşılıklı olarak her 60 saniyede bir <code>SIP OPTIONS</code> paketi gönderir. AiPBX PJSIP yapılandırmasında <code>qualify_frequency=60</code> aktif edildiğinde, Microsoft 365 Yönetim Merkezi'nde SBC durumu anında <strong>"Active (Etkin)"</strong> olarak yeşile döner.
                        </span>
                        <span data-lang="en">
                            Microsoft Teams and AiPBX exchange periodic <code>SIP OPTIONS</code> pings every 60 seconds to evaluate trunk reachability. When configured with <code>qualify_frequency=60</code> in Asterisk PJSIP, the SBC status turns <strong>"Active"</strong> in the Teams Admin Center.
                        </span>
                        <span data-lang="de">
                            Microsoft Teams und AiPBX senden alle 60 Sekunden <code>SIP OPTIONS</code>-Pings. Mit <code>qualify_frequency=60</code> in Asterisk PJSIP wechselt der Status im Teams Admin Center auf <strong>"Aktiv"</strong>.
                        </span>
                    </div>
                </div>
            </section>

            <!-- Section 3: Prerequisites -->
            <section class="docs-section" id="prerequisites">
                <h2>
                    <span data-lang="tr">3. Ön Koşullar &amp; Lisans Gereksinimleri</span>
                    <span data-lang="en">3. Prerequisites &amp; Licensing</span>
                    <span data-lang="de">3. Voraussetzungen &amp; Lizenzen</span>
                </h2>
                <div data-lang="tr">
                    <p>AiPBX Microsoft Teams Direct Routing kurulumunu gerçekleştirmeden önce aşağıdaki bileşenlerin hazır olduğundan emin olun:</p>
                    <ol>
                        <li><strong>Microsoft 365 Lisansı:</strong> Arama yapacak her Teams kullanıcısı için <em>Teams Phone Standard</em> (eski adıyla Phone System) eklenti lisansı veya bu özelliği içeren <em>Microsoft 365 E5</em> lisansı gereklidir.</li>
                        <li><strong>Onaylı Alan Adı (FQDN):</strong> Microsoft 365 Tenant'ınızda doğrulanmış bir alan adı altında SBC için özel bir alt alan adı (örneğin <code>sbc.sirketiniz.com</code> veya <code>teams.sirketiniz.com</code>). <em>Not:</em> Alan adı Microsoft 365 yönetim panelinde "Etki Alanları (Domains)" listesinde bulunmalıdır.</li>
                        <li><strong>Güvenilir Genel SSL Sertifikası:</strong> Microsoft Teams doğrudan kendi güven zincirinde bulunan genel CA'leri (Sectigo, DigiCert, GlobalSign veya Let's Encrypt ISRG Root X1) kabul eder. Sertifikanın Ortak Adı (CN) veya SAN kaydı SBC FQDN'inizle birebir eşleşmelidir.</li>
                        <li><strong>Statik Genel IPv4 Adresi:</strong> AiPBX sunucunuzun internete doğrudan açık veya 1:1 NAT ile eşlenmiş sabit bir genel IP adresi bulunmalıdır.</li>
                    </ol>
                </div>
                <div data-lang="en">
                    <p>Ensure the following prerequisites are met prior to configuring AiPBX Direct Routing:</p>
                    <ol>
                        <li><strong>Microsoft 365 Licenses:</strong> A <em>Teams Phone Standard</em> add-on license or an encompassing <em>Microsoft 365 E5</em> license for each user requiring external calling.</li>
                        <li><strong>Verified FQDN:</strong> A dedicated subdomain (e.g. <code>sbc.yourcompany.com</code>) matching a custom domain verified in your Microsoft 365 tenant.</li>
                        <li><strong>Trusted Public SSL Certificate:</strong> Issued by an officially recognized Certificate Authority (DigiCert, Sectigo, GlobalSign, or Let's Encrypt ISRG Root X1). The CN/SAN must match your SBC FQDN.</li>
                        <li><strong>Static Public IPv4:</strong> A persistent static public IP assigned directly or via 1:1 NAT to your AiPBX host.</li>
                    </ol>
                </div>
                <div data-lang="de">
                    <p>Folgende Voraussetzungen müssen erfüllt sein:</p>
                    <ol>
                        <li><strong>Microsoft 365 Lizenzen:</strong> <em>Teams Phone Standard</em> Zusatzlizenz oder <em>Microsoft 365 E5</em> für jeden telefonierenden Benutzer.</li>
                        <li><strong>Verifizierter FQDN:</strong> Eine Subdomain (z.B. <code>sbc.ihrefirma.de</code>) unter einer im M365-Tenant verifizierten Domain.</li>
                        <li><strong>Öffentliches SSL-Zertifikat:</strong> Ausgestellt von einer vertrauenswürdigen Zertifizierungsstelle (DigiCert, Sectigo oder Let's Encrypt).</li>
                        <li><strong>Statische öffentliche IPv4-Adresse:</strong> Feste öffentliche IP-Adresse für AiPBX.</li>
                    </ol>
                </div>
            </section>

            <!-- Section 4: Firewall & Network -->
            <section class="docs-section" id="firewall">
                <h2>
                    <span data-lang="tr">4. Ağ &amp; Güvenlik Duvarı Port Kuralları</span>
                    <span data-lang="en">4. Network &amp; Firewall Rules</span>
                    <span data-lang="de">4. Netzwerk- &amp; Firewall-Regeln</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        Microsoft Teams Direct Routing sinyalleşme ve ses trafiği için AiPBX sunucunuzda aşağıdaki güvenlik duvarı (UFW) kurallarının açık olması gerekir:
                    </p>
                </div>
                <div data-lang="en">
                    <p>
                        Configure your firewall to allow bi-directional traffic between your AiPBX SBC and Microsoft 365 Direct Routing endpoints:
                    </p>
                </div>
                <div data-lang="de">
                    <p>
                        Öffnen Sie folgende Firewall-Ports für den Datenverkehr zwischen AiPBX und Microsoft 365:
                    </p>
                </div>

                <div class="docs-table-wrapper">
                    <table class="docs-table">
                        <thead>
                            <tr>
                                <th><span data-lang="tr">Protokol &amp; Port</span><span data-lang="en">Protocol &amp; Port</span><span data-lang="de">Protokoll &amp; Port</span></th>
                                <th><span data-lang="tr">Kaynak / Hedef IP Aralığı</span><span data-lang="en">Source / Destination</span><span data-lang="de">Quelle / Ziel</span></th>
                                <th><span data-lang="tr">Trafik Tipi &amp; Açıklama</span><span data-lang="en">Traffic Type &amp; Purpose</span><span data-lang="de">Verkehrstyp &amp; Zweck</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>TCP 5061</code></td>
                                <td><code>52.114.0.0/16</code>, <code>52.112.0.0/14</code></td>
                                <td><span data-lang="tr"><strong>SIP over TLS Sinyalleşmesi:</strong> Microsoft PSTN Hub proxy'leri ile iki yönlü SIP çağrı kurulumu ve OPTIONS nabız kontrolleri.</span><span data-lang="en"><strong>SIP over TLS Signaling:</strong> Bi-directional call setup and OPTIONS keep-alive with MS PSTN Hub.</span><span data-lang="de"><strong>SIP über TLS:</strong> Signalisierung und OPTIONS-Keepalive mit MS PSTN Hub.</span></td>
                            </tr>
                            <tr>
                                <td><code>UDP 10000:20000</code></td>
                                <td><code>52.112.0.0/14</code>, <code>52.120.0.0/14</code></td>
                                <td><span data-lang="tr"><strong>SRTP Güvenli Ses Akışı:</strong> Teams kullanıcıları ile AiPBX arasındaki şifreli medya paketleri.</span><span data-lang="en"><strong>SRTP Media Streams:</strong> Encrypted voice payload exchange between Teams clients and PBX.</span><span data-lang="de"><strong>SRTP-Audioströme:</strong> Verschlüsselte Sprachpakete zwischen Teams und PBX.</span></td>
                            </tr>
                            <tr>
                                <td><code>TCP 443</code></td>
                                <td>Genel / Any</td>
                                <td><span data-lang="tr"><strong>HTTPS Webhook &amp; Let's Encrypt:</strong> Sertifika yenileme ve Teams kanallarına giden Adaptive Card bildirimleri.</span><span data-lang="en"><strong>HTTPS Webhook &amp; SSL:</strong> Automated Certbot renewal and outbound Teams channel webhooks.</span><span data-lang="de"><strong>HTTPS-Webhooks &amp; SSL:</strong> Certbot-Erneuerung und ausgehende Teams-Webhooks.</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="docs-code-block" style="margin-top: 16px;">
                    <div class="docs-code-header">
                        <span>bash — Ubuntu UFW Güvenlik Duvarı Kuralları</span>
                        <button class="docs-code-copy">Copy</button>
                    </div>
                    <div class="docs-code-body">
<pre><code># 1. Microsoft Teams SIP TLS (Port 5061) - Microsoft Subnetleri
sudo ufw allow proto tcp from 52.114.0.0/16 to any port 5061 comment 'MS Teams SIP TLS Primary'
sudo ufw allow proto tcp from 52.112.0.0/14 to any port 5061 comment 'MS Teams SIP TLS Secondary'

# 2. Microsoft Teams SRTP Ses Medyası (UDP 10000-20000)
sudo ufw allow proto udp from 52.112.0.0/14 to any port 10000:20000 comment 'MS Teams SRTP Media'
sudo ufw allow proto udp from 52.120.0.0/14 to any port 10000:20000 comment 'MS Teams Media Relays'

# 3. Güvenlik duvarını yeniden yükle ve durumu incele
sudo ufw reload
sudo ufw status numbered</code></pre>
                    </div>
                </div>
            </section>

            <!-- Section 5: Asterisk 22 & PJSIP Configuration -->
            <section class="docs-section" id="asterisk-pjsip">
                <h2>
                    <span data-lang="tr">5. Asterisk 22 &amp; PJSIP Yapılandırması</span>
                    <span data-lang="en">5. Asterisk 22 &amp; PJSIP Configuration</span>
                    <span data-lang="de">5. Asterisk 22 &amp; PJSIP Konfiguration</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        Asterisk 22 PJSIP motoru, Microsoft Teams Direct Routing'in talep ettiği TLS 1.2+ şifreleme, SDES-SRTP medya koruması ve E.164 numara formatlarını yerel olarak destekler. Aşağıdaki konfigürasyon bloklarını <code>/etc/asterisk/pjsip.conf</code> ve <code>/etc/asterisk/extensions.conf</code> dosyalarına ekleyin:
                    </p>
                </div>
                <div data-lang="en">
                    <p>
                        Asterisk 22 PJSIP natively satisfies Microsoft's strict Direct Routing requirements including TLS 1.2+ mutual authentication, SDES-SRTP media encryption, and E.164 normalization. Add the following snippets to your Asterisk configuration:
                    </p>
                </div>
                <div data-lang="de">
                    <p>
                        Asterisk 22 PJSIP erfüllt alle Vorgaben für Microsoft Teams Direct Routing (TLS 1.2+, SDES-SRTP und E.164). Fügen Sie folgende Abschnitte zu Ihrer Konfiguration hinzu:
                    </p>
                </div>

                <h3>
                    <span data-lang="tr">A. <code>/etc/asterisk/pjsip.conf</code> — Trunk &amp; Transport Ayarları</span>
                    <span data-lang="en">A. <code>/etc/asterisk/pjsip.conf</code> — Trunk &amp; Transport Setup</span>
                    <span data-lang="de">A. <code>/etc/asterisk/pjsip.conf</code> — Trunk &amp; Transport-Setup</span>
                </h3>

                <div class="docs-code-block">
                    <div class="docs-code-header">
                        <span>ini — /etc/asterisk/pjsip.conf (Teams Direct Routing)</span>
                        <button class="docs-code-copy">Copy</button>
                    </div>
                    <div class="docs-code-body">
<pre><code>; ====================================================================
; 1. TLS TRANSPORT TANIMI (PORT 5061 - Microsoft Teams SBC)
; ====================================================================
[transport-tls-msteams]
type=transport
protocol=tls
bind=0.0.0.0:5061
cert_file=/etc/letsencrypt/live/sbc.yourdomain.com/fullchain.pem
priv_key_file=/etc/letsencrypt/live/sbc.yourdomain.com/privkey.pem
method=tlsv1_2
cipher=ECDHE-RSA-AES256-GCM-SHA384,ECDHE-RSA-AES128-GCM-SHA256,ECDHE-RSA-AES256-SHA384
verify_client=no
verify_server=yes
require_client_cert=no

; ====================================================================
; 2. MS TEAMS ENDPOINT TANIMI
; ====================================================================
[msteams-trunk]
type=endpoint
transport=transport-tls-msteams
context=from-msteams
disallow=all
allow=alaw,ulaw,opus,g722
direct_media=no
media_encryption=sdes
rtp_symmetric=yes
force_rport=yes
rewrite_contact=yes
trust_id_inbound=yes
send_rpid=yes
send_pai=yes
timers=yes
timers_sess_expires=1800
aors=msteams-aor

; ====================================================================
; 3. MS TEAMS AOR & QUALIFY (OPTIONS Nabız Kontrolü)
; ====================================================================
[msteams-aor]
type=aor
contact=sip:sip.pstnhub.microsoft.com:5061;transport=tls
qualify_frequency=60
qualify_timeout=3.0

; ====================================================================
; 4. IDENTIFY (Gelen Çağrıyı Microsoft IP'leriyle Eşleştirme)
; ====================================================================
[msteams-identify]
type=identify
endpoint=msteams-trunk
match=52.114.148.0, 52.114.132.74, 52.114.75.24, 52.114.76.76, 52.114.7.24, 52.114.14.70, 52.114.16.74, 52.114.20.29, sip.pstnhub.microsoft.com, sip2.pstnhub.microsoft.com, sip3.pstnhub.microsoft.com</code></pre>
                    </div>
                </div>

                <h3>
                    <span data-lang="tr">B. <code>/etc/asterisk/extensions.conf</code> — Çağrı Yönlendirme (Dialplan)</span>
                    <span data-lang="en">B. <code>/etc/asterisk/extensions.conf</code> — Dialplan Routing</span>
                    <span data-lang="de">B. <code>/etc/asterisk/extensions.conf</code> — Wählplan-Routing</span>
                </h3>

                <div class="docs-code-block">
                    <div class="docs-code-header">
                        <span>ini — /etc/asterisk/extensions.conf (from-msteams &amp; to-msteams)</span>
                        <button class="docs-code-copy">Copy</button>
                    </div>
                    <div class="docs-code-body">
<pre><code>; ====================================================================
; 1. MICROSOFT TEAMS'TEN GELEN ÇAĞRILAR (from-msteams)
; ====================================================================
[from-msteams]
; Teams kullanıcısı 3-4 haneli bir dahili çevirdiğinde (Örn: 105)
exten => _[1-9]XX,1,NoOp(Teams -> Dahili Arama: ${CALLERID(num)} -> ${EXTEN})
 same => n,Dial(PJSIP/${EXTEN},30,tTkK)
 same => n,Hangup()

exten => _[1-9]XXX,1,NoOp(Teams -> 4 Haneli Dahili: ${CALLERID(num)} -> ${EXTEN})
 same => n,Dial(PJSIP/${EXTEN},30,tTkK)
 same => n,Hangup()

; Teams kullanıcısı Çağrı Merkezi Kuyruğunu aradığında (Örn: 800)
exten => 800,1,NoOp(Teams -> Destek Kuyrugu)
 same => n,Answer()
 same => n,Queue(destek_kuyrugu,tTkK,,,180)
 same => n,Hangup()

; Teams kullanıcısı PSTN dış hat aradığında (05xx / 02xx / +90...)
exten => _0[2-5]XXXXXXXXX,1,NoOp(Teams -> Telekom Trunk Dis Arama: ${EXTEN})
 same => n,Dial(PJSIP/${EXTEN}@turktelekom-trunk,60,tTkK)
 same => n,Hangup()

; ====================================================================
; 2. AİPBX DAHİLİLERİNDEN TEAMS'E ÇAĞRI GÖNDERME (to-msteams)
; Teams kullanıcılarına tanımlanan E.164 numaralarına rota açılır (+90212XXXXXXX)
; ====================================================================
[to-msteams]
exten => _+.,1,NoOp(AiPBX -> Teams Direct Routing: ${EXTEN})
 same => n,Set(CALLERID(num)=+902129990000) ; Şirket ana numaranız
 same => n,Dial(PJSIP/${EXTEN}@msteams-trunk,60,tTkK)
 same => n,Hangup()

; Kısa kod ile Teams kullanıcısını arama (Örn: 7105 -> +902129997105)
exten => _7XXX,1,NoOp(Dahili -> Teams Kullanicisi 7${EXTEN:1})
 same => n,Dial(PJSIP/+90212999${EXTEN}@msteams-trunk,45,tTkK)
 same => n,Hangup()</code></pre>
                    </div>
                </div>

                <div class="docs-code-block" style="margin-top: 16px;">
                    <div class="docs-code-header">
                        <span>bash — Asterisk Konfigürasyonunu Canlı Yeniden Yükleme</span>
                        <button class="docs-code-copy">Copy</button>
                    </div>
                    <div class="docs-code-body">
<pre><code># Asterisk modüllerini canlı olarak yeniden yükleyin
sudo asterisk -rx "pjsip reload"
sudo asterisk -rx "dialplan reload"

# Teams Trunk durumunu ve OPTIONS nabzını teyit edin
sudo asterisk -rx "pjsip show endpoints"
sudo asterisk -rx "pjsip show aor msteams-aor"</code></pre>
                    </div>
                </div>
            </section>

            <!-- Section 6: Microsoft 365 PowerShell Setup -->
            <section class="docs-section" id="m365-powershell">
                <h2>
                    <span data-lang="tr">6. Microsoft 365 PowerShell Yapılandırması</span>
                    <span data-lang="en">6. Microsoft 365 PowerShell Setup</span>
                    <span data-lang="de">6. Microsoft 365 PowerShell Konfiguration</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        Microsoft Teams Direct Routing kurulumunun Microsoft bulut tarafı resmi <strong>MicrosoftTeams PowerShell</strong> modülü üzerinden birkaç komutla tamamlanır. Yönetici (Global Admin veya Teams Admin) yetkisine sahip bir bilgisayarda aşağıdaki adımları sırasıyla uygulayın:
                    </p>
                </div>
                <div data-lang="en">
                    <p>
                        Configuring Microsoft 365 Direct Routing is completed via the official <strong>MicrosoftTeams PowerShell</strong> module. Execute the following sequence with Teams Administrator privileges:
                    </p>
                </div>
                <div data-lang="de">
                    <p>
                        Die Konfiguration in Microsoft 365 erfolgt über das offizielle <strong>MicrosoftTeams PowerShell</strong>-Modul mit Administratorrechten:
                    </p>
                </div>

                <div class="docs-code-block">
                    <div class="docs-code-header">
                        <span>powershell — Microsoft Teams PowerShell Yapılandırması</span>
                        <button class="docs-code-copy">Copy</button>
                    </div>
                    <div class="docs-code-body">
<pre><code># ====================================================================
# ADIM 1: Modülü Kurun ve Microsoft 365 Tenant'a Bağlanın
# ====================================================================
Install-Module -Name MicrosoftTeams -Force -AllowClobber
Connect-MicrosoftTeams

# ====================================================================
# ADIM 2: AiPBX SBC Gateway'i Sisteme Tanıtın
# ====================================================================
New-CsOnlinePSTNGateway `
    -Fqdn "sbc.yourdomain.com" `
    -SipSignalingPort 5061 `
    -CodecPriorityList SILK,G711A,G711U `
    -MaxConcurrentSessions 100 `
    -Enabled $true

# ====================================================================
# ADIM 3: PSTN Kullanımını ve Ses Rotalarını (Voice Route) Oluşturun
# ====================================================================
# PSTN Usage etiketi tanımlayın
Set-CsOnlinePstnUsage -Identity Global -Usage @{Add="AiPBX-Local"}

# Tüm giden çağrıları AiPBX SBC'ye yönlendiren ses rotası
New-CsOnlineVoiceRoute `
    -Name "AiPBX-PSTN-Route" `
    -NumberPattern ".*" `
    -OnlinePstnGatewayList "sbc.yourdomain.com" `
    -Priority 1 `
    -OnlinePstnUsages "AiPBX-Local"

# Voice Routing Policy (Ses Yönlendirme İlkesi) oluşturun
New-CsOnlineVoiceRoutingPolicy `
    -Identity "AiPBX-Voice-Policy" `
    -OnlinePstnUsages "AiPBX-Local" `
    -Description "AiPBX Direct Routing Santral İlkesi"

# ====================================================================
# ADIM 4: Kullanıcıya Numara Atayın ve İlkeyi Uygulayın
# ====================================================================
# Kullanıcıya E.164 telefon numarası tanımlayın ve Enterprise Voice açın
Set-CsPhoneNumberAssignment `
    -Identity "ahmet.yilmaz@yourdomain.com" `
    -PhoneNumber "+902129991001" `
    -PhoneNumberType DirectRouting

# Kullanıcıya AiPBX ses politikasını bağlayın
Grant-CsOnlineVoiceRoutingPolicy `
    -Identity "ahmet.yilmaz@yourdomain.com" `
    -PolicyName "AiPBX-Voice-Policy"</code></pre>
                    </div>
                </div>

                <div class="callout callout-success" style="margin-top: 20px;">
                    <span class="callout-icon">✅</span>
                    <div class="callout-body">
                        <strong data-lang="tr">Arama Tuş Takımı (Dial Pad) Aktivasyonu:</strong>
                        <strong data-lang="en">Dial Pad Activation:</strong>
                        <strong data-lang="de">Wähltastatur-Aktivierung:</strong>
                        <span data-lang="tr">
                            Yukarıdaki PowerShell adımları tamamlandıktan sonra, Microsoft 365 bulut replikasyonu genellikle 15-30 dakika sürer. Replikasyon bittiğinde kullanıcının Microsoft Teams istemcisindeki "Aramalar (Calls)" sekmesinde otomatik olarak telefon numarası tuş takımı (Dial Pad) belirecektir.
                        </span>
                        <span data-lang="en">
                            Microsoft 365 cloud propagation typically takes 15 to 30 minutes. Once propagated, the native numeric Dial Pad will appear automatically inside the Teams "Calls" tab.
                        </span>
                        <span data-lang="de">
                            Die Synchronisation in Microsoft 365 dauert ca. 15-30 Minuten. Danach erscheint die Wähltastatur automatisch in der Teams-App unter "Anrufe".
                        </span>
                    </div>
                </div>
            </section>

            <!-- Section 7: Enterprise Call Flows & Scenarios -->
            <section class="docs-section" id="call-flows">
                <h2>
                    <span data-lang="tr">7. Çağrı Senaryoları &amp; Kurumsal İş Akışları</span>
                    <span data-lang="en">7. Call Flows &amp; Enterprise Scenarios</span>
                    <span data-lang="de">7. Anrufszenarien &amp; Enterprise-Workflows</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        AiPBX ve Microsoft Teams entegrasyonu tamamlandığında işletmeniz hibrit bir santral yapısına kavuşur. Sistem aşağıdaki senaryoları tam uyumla destekler:
                    </p>
                </div>
                <div data-lang="en">
                    <p>
                        Once deployed, AiPBX delivers seamless hybrid routing scenarios between legacy telephony and Teams cloud:
                    </p>
                </div>
                <div data-lang="de">
                    <p>
                        Nach der Bereitstellung unterstützt AiPBX nahtlose hybride Telefonieszenarien:
                    </p>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 18px; margin-top: 18px;">
                    <div style="background: #0d1524; border: 1px solid var(--border); border-radius: 10px; padding: 20px;">
                        <h4 style="color: #38bdf8; margin-top: 0;">1. Teams ➔ Masa Telefonu / Dahili</h4>
                        <p style="font-size: 0.9rem; color: #cbd5e1;" data-lang="tr">
                            Teams kullanıcısı arama ekranında <code>105</code> tuşladığında çağrı TLS 5061 üzerinden AiPBX'e ulaşır. Asterisk dahiliyi masaüstü IP telefonda ve mobil uygulamada anında çaldırır.
                        </p>
                        <p style="font-size: 0.9rem; color: #cbd5e1;" data-lang="en">
                            Dialing <code>105</code> in Teams routes via TLS 5061 to AiPBX, ringing the physical IP phone and native mobile client simultaneously.
                        </p>
                        <p style="font-size: 0.9rem; color: #cbd5e1;" data-lang="de">
                            Die Wahl von <code>105</code> in Teams leitet den Anruf über TLS 5061 an AiPBX weiter und klingelt Tischtelefon und Mobile-App gleichzeitig.
                        </p>
                    </div>

                    <div style="background: #0d1524; border: 1px solid var(--border); border-radius: 10px; padding: 20px;">
                        <h4 style="color: #34d399; margin-top: 0;">2. PSTN Dış Hat ➔ IVR ➔ Teams</h4>
                        <p style="font-size: 0.9rem; color: #cbd5e1;" data-lang="tr">
                            Müşteri Türk Telekom numaranızı arar. AiPBX IVR menüsü açılır ("Satış için 1'e basın"). Tuşlama yapıldığında çağrı doğrudan ilgili Teams kullanıcısına düşer.
                        </p>
                        <p style="font-size: 0.9rem; color: #cbd5e1;" data-lang="en">
                            Inbound caller dials your company number, hears the AiPBX IVR menu, presses 1, and is forwarded directly to the sales rep on Microsoft Teams.
                        </p>
                        <p style="font-size: 0.9rem; color: #cbd5e1;" data-lang="de">
                            Anrufer wählt die Firmennummer, hört das IVR-Menü und wird direkt zum Mitarbeiter in Microsoft Teams weitergeleitet.
                        </p>
                    </div>

                    <div style="background: #0d1524; border: 1px solid var(--border); border-radius: 10px; padding: 20px;">
                        <h4 style="color: #c084fc; margin-top: 0;">3. Teams ➔ Dış Dünya (PSTN Arama)</h4>
                        <p style="font-size: 0.9rem; color: #cbd5e1;" data-lang="tr">
                            Teams kullanıcısı bir cep telefonu numarasını tuşladığında çağrı AiPBX üzerinden şirketin mevcut SIP trunk operatörüne yönlendirilir ve yerel tarifeden faturalandırılır.
                        </p>
                        <p style="font-size: 0.9rem; color: #cbd5e1;" data-lang="en">
                            Teams user dials a mobile number; the call exits via AiPBX through your existing telco SIP trunk at local corporate rates.
                        </p>
                        <p style="font-size: 0.9rem; color: #cbd5e1;" data-lang="de">
                            Teams-Benutzer ruft eine Mobilnummer an; der Anruf wird über den bestehenden Firmentrunk zu lokalen Tarifen geroutet.
                        </p>
                    </div>

                    <div style="background: #0d1524; border: 1px solid var(--border); border-radius: 10px; padding: 20px;">
                        <h4 style="color: #fbbf24; margin-top: 0;">4. Teams Kullanıcısı Çağrı Kuyruğunda</h4>
                        <p style="font-size: 0.9rem; color: #cbd5e1;" data-lang="tr">
                            Destek ekibindeki Teams kullanıcıları AiPBX ACD kuyruklarına dinamik üye olarak eklenebilir. Gelen müşteri çağrıları sırayla Teams ekranlarına pop-up olarak düşer.
                        </p>
                        <p style="font-size: 0.9rem; color: #cbd5e1;" data-lang="en">
                            Teams agents can join AiPBX ACD queues dynamically, receiving distributed customer calls with full supervisor monitoring.
                        </p>
                        <p style="font-size: 0.9rem; color: #cbd5e1;" data-lang="de">
                            Teams-Benutzer können als Agenten in AiPBX-Kuyruklar aufgenommen werden und Kundenanrufe mit Supervisor-Unterstützung empfangen.
                        </p>
                    </div>
                </div>
            </section>

            <!-- Section 8: Teams Webhook & Channel Notifications -->
            <section class="docs-section" id="webhooks">
                <h2>
                    <span data-lang="tr">8. Microsoft Teams Webhook &amp; Kanal Bildirimleri</span>
                    <span data-lang="en">8. Teams Webhooks &amp; Channel Notifications</span>
                    <span data-lang="de">8. Teams-Webhooks &amp; Kanal-Benachrichtigungen</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        Direct Routing ses entegrasyonuna ek olarak AiPBX; kaçan çağrılar, santral güvenlik uyarıları, gelen faks belgeleri ve çağrı merkezi SLA aşım bildirimlerini Microsoft Teams kanallarına <strong>Adaptive Cards</strong> biçiminde anlık webhook ile gönderebilir.
                    </p>
                </div>
                <div data-lang="en">
                    <p>
                        Beyond voice routing, AiPBX dispatches rich <strong>Adaptive Cards</strong> to Microsoft Teams channels via incoming webhooks for missed calls, new faxes, and SLA breach alerts:
                    </p>
                </div>
                <div data-lang="de">
                    <p>
                        Zusätzlich zum Voice-Routing sendet AiPBX formatierte <strong>Adaptive Cards</strong> über Webhooks in Microsoft Teams-Kanäle:
                    </p>
                </div>

                <div class="docs-code-block">
                    <div class="docs-code-header">
                        <span>bash — Kaçan Çağrı Teams Webhook Gönderimi (cURL &amp; Adaptive Card)</span>
                        <button class="docs-code-copy">Copy</button>
                    </div>
                    <div class="docs-code-body">
<pre><code>curl -X POST -H "Content-Type: application/json" -d '{
  "type": "message",
  "attachments": [
    {
      "contentType": "application/vnd.microsoft.card.adaptive",
      "content": {
        "$schema": "http://adaptivecards.io/schemas/adaptive-card.json",
        "type": "AdaptiveCard",
        "version": "1.4",
        "body": [
          {
            "type": "TextBlock",
            "text": "📞 AiPBX — Kaçan Çağrı Bildirimi",
            "weight": "Bolder",
            "size": "Medium",
            "color": "Attention"
          },
          {
            "type": "FactSet",
            "facts": [
              { "title": "Arayan:", "value": "+90 532 123 45 67" },
              { "title": "Hedef Kuyruk:", "value": "Destek Kuyrugu (*800)" },
              { "title": "Bekleme Süresi:", "value": "48 saniye" },
              { "title": "Zaman:", "value": "2026-09-18 11:42:15" }
            ]
          }
        ],
        "actions": [
          {
            "type": "Action.OpenUrl",
            "title": "Santralde İncele",
            "url": "https://aipbx.bid/cdr-reports"
          }
        ]
      }
    }
  ]
}' "https://yourtenant.webhook.office.com/webhookb2/..."</code></pre>
                    </div>
                </div>
            </section>

            <!-- Section 9: Troubleshooting -->
            <section class="docs-section" id="troubleshooting">
                <h2>
                    <span data-lang="tr">9. Sorun Giderme &amp; Tanı Komutları</span>
                    <span data-lang="en">9. Troubleshooting &amp; Diagnostics</span>
                    <span data-lang="de">9. Fehlerbehebung &amp; Diagnose</span>
                </h2>
                <div data-lang="tr">
                    <p>Direct Routing kurulumunda en sık karşılaşılan sorunlar ve çözüm adımları:</p>
                </div>
                <div data-lang="en">
                    <p>Common issues encountered during Direct Routing configuration and their remedies:</p>
                </div>
                <div data-lang="de">
                    <p>Häufige Probleme bei der Direct Routing-Einrichtung und deren Behebung:</p>
                </div>

                <div class="docs-table-wrapper">
                    <table class="docs-table">
                        <thead>
                            <tr>
                                <th><span data-lang="tr">Hata / Belirti</span><span data-lang="en">Symptom / Error</span><span data-lang="de">Fehler / Symptom</span></th>
                                <th><span data-lang="tr">Olası Neden</span><span data-lang="en">Probable Root Cause</span><span data-lang="de">Ursache</span></th>
                                <th><span data-lang="tr">Çözüm Yolu</span><span data-lang="en">Resolution</span><span data-lang="de">Lösung</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="color: #f87171;"><strong>SIP OPTIONS Yanıtsız / SBC Down</strong></td>
                                <td><span data-lang="tr">Port 5061 güvenlik duvarında kapalı veya TLS sertifika adı uyuşmuyor.</span><span data-lang="en">Port 5061 blocked or FQDN does not match SSL CN/SAN.</span><span data-lang="de">Port 5061 blockiert oder SSL-Zertifikatsname stimmt nicht überein.</span></td>
                                <td><span data-lang="tr"><code>ufw status</code> ile 5061 portunu kontrol edin. Let's Encrypt sertifikasında FQDN'in doğru olduğunu <code>certbot certificates</code> ile teyit edin.</span><span data-lang="en">Check UFW rules and ensure Certbot certificate matches SBC FQDN.</span><span data-lang="de">UFW-Regeln prüfen und FQDN im SSL-Zertifikat validieren.</span></td>
                            </tr>
                            <tr>
                                <td style="color: #f87171;"><strong>488 Not Acceptable Here</strong></td>
                                <td><span data-lang="tr">Codec veya SRTP medya şifreleme uyuşmazlığı.</span><span data-lang="en">Codec mismatch or missing SRTP encryption.</span><span data-lang="de">Codec-Konflikt oder fehlende SRTP-Verschlüsselung.</span></td>
                                <td><span data-lang="tr">PJSIP endpoint ayarlarında <code>media_encryption=sdes</code> ve <code>allow=alaw,ulaw,opus</code> satırlarının varlığından emin olun.</span><span data-lang="en">Verify <code>media_encryption=sdes</code> and G.711/Opus codecs in PJSIP config.</span><span data-lang="de"><code>media_encryption=sdes</code> und G.711/Opus in PJSIP sicherstellen.</span></td>
                            </tr>
                            <tr>
                                <td style="color: #f87171;"><strong>403 Forbidden</strong></td>
                                <td><span data-lang="tr">Microsoft 365 Tenant'ta ses politikası atanmamış veya numara eksik.</span><span data-lang="en">Voice policy or phone assignment missing in M365.</span><span data-lang="de">Fehlende Voice Policy oder Rufnummernzuweisung in M365.</span></td>
                                <td><span data-lang="tr">PowerShell üzerinden <code>Get-CsOnlineUser ahmet@domain.com</code> komutuyla <code>EnterpriseVoiceEnabled: True</code> olduğunu denetleyin.</span><span data-lang="en">Check user status with <code>Get-CsOnlineUser</code> in PowerShell.</span><span data-lang="de">Benutzerstatus mit <code>Get-CsOnlineUser</code> prüfen.</span></td>
                            </tr>
                            <tr>
                                <td style="color: #f87171;"><strong>Tek Taraflı Ses (One-Way Audio)</strong></td>
                                <td><span data-lang="tr">UDP 10000:20000 portları güvenlik duvarında engelli veya NAT external_media_address eksik.</span><span data-lang="en">UDP media ports blocked or NAT external_media_address unset.</span><span data-lang="de">UDP-Mediaports blockiert oder NAT-Adresse fehlt.</span></td>
                                <td><span data-lang="tr">PJSIP transport tanımına <code>external_media_address=YOUR_PUBLIC_IP</code> ekleyin ve UDP portlarını açın.</span><span data-lang="en">Add <code>external_media_address</code> to PJSIP transport and verify UDP range.</span><span data-lang="de"><code>external_media_address</code> in PJSIP definieren und UDP-Ports öffnen.</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="docs-code-block" style="margin-top: 16px;">
                    <div class="docs-code-header">
                        <span>bash — Canlı SIP Trafiğini Hata Ayıklama Modunda İzleme</span>
                        <button class="docs-code-copy">Copy</button>
                    </div>
                    <div class="docs-code-body">
<pre><code># Asterisk konsoluna bağlanın ve PJSIP SIP paket dökümünü açın
sudo asterisk -rvvv
pjsip set logger on

# Yalnızca Microsoft Teams trafiğini filtrelemek için terminalde:
sudo tcpdump -n -i any port 5061 -vv</code></pre>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- Footer -->

    <!-- Universal Footer -->
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
