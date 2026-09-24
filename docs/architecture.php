<?php
/**
 * AiPBX.bid — Sistem Mimarisi & ALPN Çoklama — Asterisk 22, Nginx L4, WebRTC | AiPBX
 */
$page = 'architecture';
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
                        <a href="/architecture" class="active">
                            <span data-lang="tr">🏗️ Sistem Mimarisi &amp; ALPN</span>
                            <span data-lang="en">🏗️ Architecture &amp; ALPN</span>
                            <span data-lang="de">🏗️ Architektur &amp; ALPN</span>
                        </a>
                    </li>
                    <li>
                        <a href="/features" class="">
                            <span data-lang="tr">⚡ Santral Modülleri</span>
                            <span data-lang="en">⚡ PBX Modules</span>
                            <span data-lang="de">⚡ PBX-Module</span>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="sidebar-group">
                <div class="sidebar-title">
                    <span data-lang="tr">Çağrı Merkezi &amp; Kuyruk</span>
                    <span data-lang="en">Call Center &amp; Queues</span>
                    <span data-lang="de">Callcenter &amp; Warteschlangen</span>
                </div>
                <ul class="sidebar-menu">
                    <li>
                        <a href="/callcenter" class="">
                            <span data-lang="tr">🎧 Kuyruk Yönetimi</span>
                            <span data-lang="en">🎧 Queue Operations</span>
                            <span data-lang="de">🎧 Warteschlangen-Betrieb</span>
                        </a>
                    </li>
                    <li>
                        <a href="/callcenter#break-codes" class="">
                            <span data-lang="tr">⏸️ Mola Kodları (*22/*23) <span class="sidebar-badge">Yeni</span></span>
                            <span data-lang="en">⏸️ Break Codes (*22/*23) <span class="sidebar-badge">New</span></span>
                            <span data-lang="de">⏸️ Pausencodes (*22/*23) <span class="sidebar-badge">Neu</span></span>
                        </a>
                    </li>
                    <li>
                        <a href="/callcenter#wallboard">
                            <span data-lang="tr">📊 Canlı Wallboard &amp; SLA</span>
                            <span data-lang="en">📊 Live Wallboard &amp; SLA</span>
                            <span data-lang="de">📊 Live-Wallboard &amp; SLA</span>
                        </a>
                    </li>
                    <li>
                        <a href="/callcenter#supervisor">
                            <span data-lang="tr">👁️ Süpervizör &amp; Spy (*90)</span>
                            <span data-lang="en">👁️ Supervisor &amp; Spy (*90)</span>
                            <span data-lang="de">👁️ Supervisor &amp; Spy (*90)</span>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="sidebar-group">
                <div class="sidebar-title">
                    <span data-lang="tr">Mobil İstemciler</span>
                    <span data-lang="en">Mobile Clients</span>
                    <span data-lang="de">Mobile Clients</span>
                </div>
                <ul class="sidebar-menu">
                    <li>
                        <a href="/mobile-apps" class="">
                            <span data-lang="tr">📱 Android &amp; iOS İstemcileri</span>
                            <span data-lang="en">📱 Android &amp; iOS Clients</span>
                            <span data-lang="de">📱 Android &amp; iOS Clients</span>
                        </a>
                    </li>
                    <li>
                        <a href="/mobile-apps#downloads">
                            <span data-lang="tr">⬇️ APK / IPA / Simulator</span>
                            <span data-lang="en">⬇️ APK / IPA / Simulator</span>
                            <span data-lang="de">⬇️ APK / IPA / Simulator</span>
                        </a>
                    </li>
                    <li>
                        <a href="/mobile-apps#android">
                            <span data-lang="tr">🤖 Kotlin Native &amp; FCM Push</span>
                            <span data-lang="en">🤖 Kotlin Native &amp; FCM Push</span>
                            <span data-lang="de">🤖 Kotlin Native &amp; FCM-Push</span>
                        </a>
                    </li>
                    <li>
                        <a href="/mobile-apps#ios">
                            <span data-lang="tr">🍏 Swift &amp; CallKit</span>
                            <span data-lang="en">🍏 Swift &amp; CallKit</span>
                            <span data-lang="de">🍏 Swift &amp; CallKit</span>
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
                        <a href="/installation" class="">
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
                        <a href="/security" class="">
                            <span data-lang="tr">🛡️ Güvenlik &amp; Fail2ban</span>
                            <span data-lang="en">🛡️ Security &amp; Fail2ban</span>
                            <span data-lang="de">🛡️ Sicherheit &amp; Fail2ban</span>
                        </a>
                    </li>
                    <li>
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
                        <a href="/api-docs" class="">
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
                    <li>
                        <a href="/api-docs#tests">
                            <span data-lang="tr">🧪 PHPUnit Testleri (142 Test)</span>
                            <span data-lang="en">🧪 PHPUnit Test Suite</span>
                            <span data-lang="de">🧪 PHPUnit Test-Suite</span>
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
                <a href="/architecture">
                    <span data-lang="tr">Mimari</span>
                    <span data-lang="en">Architecture</span>
                    <span data-lang="de">Architektur</span>
                </a> / 
                <span>
                    <span data-lang="tr">Sistem Tasarımı</span>
                    <span data-lang="en">System Design</span>
                    <span data-lang="de">Systementwurf</span>
                </span>
            </div>

            <div class="docs-header">
                <h1>
                    <span data-lang="tr">🏗️ Sistem Mimarisi &amp; Mühendislik Rasyoneli</span>
                    <span data-lang="en">🏗️ System Architecture &amp; Engineering Rationale</span>
                    <span data-lang="de">🏗️ Systemarchitektur &amp; Technische Rationale</span>
                </h1>
                <p class="lead" data-lang="tr">
                    AiPBX; kurumsal ağlardaki sıkı güvenlik duvarı engellerini, WebRTC medya aktarım zorluklarını, çoklu cihaz karmaşasını ve yüksek eşzamanlı anlık mesajlaşma ihtiyaçlarını çözmek için özel olarak mimarilendirilmiştir.
                </p>
                <p class="lead" data-lang="en">
                    AiPBX is purposefully engineered to eliminate corporate firewall blockades, solve WebRTC NAT traversal challenges, streamline multi-device identity, and handle high-concurrency instant messaging.
                </p>
                <p class="lead" data-lang="de">
                    AiPBX wurde zielgerichtet entwickelt, um restriktive Unternehmens-Firewalls zu überwinden, WebRTC-NAT-Herausforderungen zu lösen, Multi-Geräte-Identitäten zu vereinfachen und hochparalleles Instant Messaging zu bewältigen.
                </p>
            </div>

            <!-- Section 1: Katmanlı Mimari -->
            <section class="docs-section" id="overview">
                <h2>
                    <span data-lang="tr">1. Bütünleşik Katmanlı Mimari (Layered Topology)</span>
                    <span data-lang="en">1. Unified Layered Architecture</span>
                    <span data-lang="de">1. Einheitliche Schichtenarchitektur</span>
                </h2>
                <p data-lang="tr">
                    AiPBX, modüler ve gevşek bağlı (loosely coupled) çok katmanlı bir yapı üzerine inşa edilmiştir. İstemcilerden gelen tüm trafik tek bir noktada karşılanır ve protokol seviyesinde alt servislere yönlendirilir:
                </p>
                <p data-lang="en">
                    AiPBX is built on a modular, loosely coupled multi-tier architecture. All ingress traffic is multiplexed through a single entry point and routed cleanly at the protocol level:
                </p>
                <p data-lang="de">
                    AiPBX basiert auf einer modularen, lose gekoppelten Mehrschichtenarchitektur. Der gesamte eingehende Datenverkehr wird über einen einzigen Einstiegspunkt gebündelt und auf Protokollebene weitergeleitet:
                </p>

                <div class="docs-code-box">
                    <div class="docs-code-header">
                        <span data-lang="tr">ASCII Mimari Topolojisi</span>
                        <span data-lang="en">ASCII Architectural Topology</span>
                        <span data-lang="de">ASCII Architektur-Topologie</span>
                    </div>
                    <div class="docs-code-body">
<pre><code>┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                                      CLIENT LAYER                                       │
│   ┌───────────────────────────────┐               ┌─────────────────────────────────┐   │
│   │     Android Mobile App        │               │      Web Browser Client         │   │
│   │     (Kotlin + WebRTC/SIP)     │               │   (WebRTC Softphone + Portal)   │   │
│   └───────────────┬───────────────┘               └────────────────┬────────────────┘   │
└───────────────────┼────────────────────────────────────────────────┼────────────────────┘
                    │ HTTPS / WSS / TURNS (Single Port: 443)         │ HTTPS / WSS / TURNS
                    ▼                                                ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                   EDGE INGRESS: NGINX PORT 443 ALPN MULTIPLEXER (L4)                    │
│   Nginx TCP Stream module (ssl_preread on) — Transparent proxy evaluating ALPN bytes:   │
│                                                                                         │
│   ├── [ ALPN Present ] (http/1.1, h2: Web Browsers, Mobile REST API, Management)        │
│   │     └──► Streamed to Apache 2.4 TLS Termination (127.0.0.1:8443)                    │
│   │                                                                                     │
│   └── [ ALPN Empty / None ] (WebRTC TURNS media relay behind restrictive firewalls)     │
│         └──► Streamed to Coturn TURNS Service (127.0.0.1:5349)                          │
└───────────────────────────────────┬────────────────────────────────┬────────────────────┘
                                    │                                │
                     [ ALPN Present ]                                [ No ALPN ]
                                    ▼                                │
┌───────────────────────────────────────────────────────┐            │
│          APPLICATION &amp; WEBSOCKET PROXY TIER           │            ▼
│  Apache 2.4 (127.0.0.1:8443 with TLS Termination)     │ ┌─────────────────────────────┐
│   ├── /                 ──► PHP 8 MVC Web Portal      │ │        COTURN RELAY         │
│   ├── /ws               ──► Asterisk WebRTC (8088/ws) │ │  TURNS Server (Port 5349)  │
│   └── /chat/ws          ──► Go Chat Engine (8086/ws)  │ │  Zero Media Loss for        │
│                                                       │ │  Symmetric NAT Traversal    │
│  Port 80: HTTP Redirect &amp; Let's Encrypt ACME HTTP-01  │ └─────────────────────────────┘
└──────────┬────────────────────────┬───────────────────┘
           │                        │
           ▼                        ▼
┌─────────────────────┐  ┌─────────────────────┐
│      PHP 8 MVC      │  │     Asterisk 22     │
│     Web Portal      │  │     VoIP Engine     │
│  (Hardened Runtime) │  │    (PJSIP/WebRTC)   │
└──────────┬──────────┘  └──────────┬──────────┘
           │                        │
           │     AMI (Port 5038)    │
           ├────────────────────────┘
           │
           ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                                       DATA LAYER                                        │
│  MariaDB 11 (utf8mb4_unicode_ci) — Two-Tier Privilege Security Model:                   │
│   ├── aipbx_portal   (Runtime DML: SELECT, INSERT, UPDATE, DELETE only)                 │
│   └── aipbx_migrator (Schema DDL: Phinx Migrations only)                                │
└─────────────────────────────────────────────────────────────────────────────────────────┘</code></pre>
                    </div>
                </div>
            </section>

            <!-- Section 2: Port 443 ALPN Multiplexing -->
            <section class="docs-section" id="alpn">
                <h2>
                    <span data-lang="tr">2. Neden Port 443 ALPN Stream Çoklama?</span>
                    <span data-lang="en">2. Why Port 443 ALPN Stream Multiplexing?</span>
                    <span data-lang="de">2. Warum Port 443 ALPN-Stream-Multiplexing?</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        Geleneksel VoIP santral kurulumlarında SIP sinyalleşmesi için UDP 5060, WebRTC WSS için 8089, STUN/TURN için 3478 veya 5349 gibi çok sayıda harici port açılması zorunludur. Ancak bankalar, hastaneler, üniversiteler ve kurumsal plaza ağları <strong>Port 80 ve 443 dışındaki tüm giden portları katı güvenlik duvarları (Firewall) ile bloke eder</strong>.
                    </p>
                    <div class="callout callout-success">
                        <span class="callout-icon">💡</span>
                        <div class="callout-body">
                            <strong>AiPBX Çözümü:</strong> Nginx Layer 4 (L4) Stream modülü ve <code>ssl_preread on</code> direktifi sayesinde, TLS el sıkışmasındaki (ClientHello) <em>Application-Layer Protocol Negotiation (ALPN)</em> baytlarını inceler. SSL sertifikasını çözmeden şifreli trafiği alt servislere dağıtır.
                        </div>
                    </div>
                </div>

                <div data-lang="en">
                    <p>
                        Traditional PBX setups require opening numerous external ports: UDP 5060 for SIP, 8089 for WebRTC WSS, and 3478/5349 for STUN/TURN. However, modern corporate networks (banks, hospitals, universities, government offices) <strong>enforce strict firewalls that block all outbound traffic except ports 80 and 443</strong>.
                    </p>
                    <div class="callout callout-success">
                        <span class="callout-icon">💡</span>
                        <div class="callout-body">
                            <strong>The AiPBX Solution:</strong> Nginx Layer 4 (L4) Stream module with <code>ssl_preread on</code> inspects the unencrypted <em>Application-Layer Protocol Negotiation (ALPN)</em> extension in the initial TLS ClientHello packet. It transparently multiplexes traffic without needing to decrypt the TLS session at the edge.
                        </div>
                    </div>
                </div>

                <div data-lang="de">
                    <p>
                        Klassische Telefonanlagen erfordern viele offene Ports: UDP 5060 für SIP, 8089 für WebRTC WSS und 3478/5349 für STUN/TURN. Moderne Unternehmensnetzwerke (Banken, Kliniken, Behörden) <strong>blockieren jedoch strikt alle ausgehenden Ports außer 80 und 443</strong>.
                    </p>
                    <div class="callout callout-success">
                        <span class="callout-icon">💡</span>
                        <div class="callout-body">
                            <strong>Die AiPBX-Lösung:</strong> Das Nginx Layer-4 (L4) Stream-Modul mit <code>ssl_preread on</code> liest die <em>Application-Layer Protocol Negotiation (ALPN)</em> im TLS-ClientHello aus. Es leitet den Datenverkehr transparent weiter, ohne die SSL-Sitzung am Edge entschlüsseln zu müssen.
                        </div>
                    </div>
                </div>

                <h3>
                    <span data-lang="tr">Nginx L4 Stream Yapılandırması:</span>
                    <span data-lang="en">Nginx L4 Stream Configuration:</span>
                    <span data-lang="de">Nginx L4 Stream-Konfiguration:</span>
                </h3>
                <div class="docs-code-box">
                    <div class="docs-code-header">
                        <span>/etc/nginx/nginx.conf (Stream Multiplexer)</span>
                        <button class="docs-code-copy">Copy</button>
                    </div>
                    <div class="docs-code-body">
<pre><code>stream {
    map $ssl_preread_alpn_protocols $upstream_backend {
        # Standard web browsers and mobile REST API clients
        "~*http"        apache_https;
        "~*h2"          apache_https;
        
        # WebRTC TURNS media relay packets (send empty ALPN)
        default         coturn_turns;
    }

    upstream apache_https {
        server 127.0.0.1:8443;
    }

    upstream coturn_turns {
        server 127.0.0.1:5349;
    }

    server {
        listen 443;
        proxy_pass $upstream_backend;
        ssl_preread on;
        proxy_connect_timeout 5s;
        proxy_timeout 3600s;
    }
}</code></pre>
                    </div>
                </div>
            </section>

            <!-- Section 3: Dual-Endpoint PJSIP -->
            <section class="docs-section" id="dual-endpoint">
                <h2>
                    <span data-lang="tr">3. Dual-Endpoint PJSIP ve WebRTC Mimarisi</span>
                    <span data-lang="en">3. Dual-Endpoint PJSIP &amp; WebRTC Architecture</span>
                    <span data-lang="de">3. Dual-Endpoint PJSIP &amp; WebRTC Architektur</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        Piyasadaki birçok hazır PBX dağıtımı (FreePBX, Issabel vb.) tek bir dahili için aynı anda hem donanımsal masa telefonunu hem de WebRTC tarayıcıyı bağlamakta zorlanır. NAT arkasındaki masa telefonu ile WebRTC'nin şifreleme ve medya taşıma gereksinimleri taban tabana zıttır:
                    </p>
                    <ul>
                        <li><strong>WebRTC (Tarayıcı &amp; Mobil):</strong> WSS taşıyıcı, DTLS-SRTP şifreleme, ICE adayları, AVPF profil ve Opus ses kodeği gerektirir.</li>
                        <li><strong>Masa Telefonu (IP Telefon / ATA):</strong> UDP veya TLS taşıyıcı, SDES-SRTP veya şifresiz RTP, SAVP/RTP profil ve G.711u/a kodeği kullanır.</li>
                    </ul>
                </div>
                <div data-lang="en">
                    <p>
                        Many legacy PBX solutions struggle to connect both a physical hardware phone and a WebRTC browser/mobile client to the same extension. Their transport and encryption profiles are mutually exclusive:
                    </p>
                    <ul>
                        <li><strong>WebRTC (Browser &amp; Mobile):</strong> Mandatory WSS transport, DTLS-SRTP encryption, ICE candidate negotiation, AVPF profile, and Opus audio codec.</li>
                        <li><strong>Hardware Phone (Desktop IP Phone / ATA):</strong> Standard UDP/TLS transport, SDES-SRTP or plain RTP, SAVP profile, and G.711u/a or G.722 codecs.</li>
                    </ul>
                </div>
                <div data-lang="de">
                    <p>
                        Viele herkömmliche PBX-Distributionen scheitern daran, ein Tischtelefon und einen WebRTC-Browserclient gleichzeitig an einer einzigen Nebenstelle zu betreiben. Die Transport- und Verschlüsselungsprofile sind gegensätzlich:
                    </p>
                    <ul>
                        <li><strong>WebRTC (Browser &amp; Mobil):</strong> WSS-Transport, DTLS-SRTP-Verschlüsselung, ICE-Kandidaten, AVPF-Profil und Opus-Codec.</li>
                        <li><strong>Tischtelefon (IP-Telefon / ATA):</strong> UDP/TLS-Transport, SDES-SRTP oder unverschlüsseltes RTP, SAVP-Profil und G.711/G.722-Codecs.</li>
                    </ul>
                </div>

                <div class="docs-table-wrapper">
                    <table class="docs-table">
                        <thead>
                            <tr>
                                <th>Endpoint ID</th>
                                <th>
                                    <span data-lang="tr">Kullanım Amacı</span>
                                    <span data-lang="en">Target Client</span>
                                    <span data-lang="de">Zielgerät</span>
                                </th>
                                <th>
                                    <span data-lang="tr">Taşıyıcı &amp; Port</span>
                                    <span data-lang="en">Transport &amp; Port</span>
                                    <span data-lang="de">Transport &amp; Port</span>
                                </th>
                                <th>
                                    <span data-lang="tr">Şifreleme &amp; Kodek</span>
                                    <span data-lang="en">Encryption &amp; Codec</span>
                                    <span data-lang="de">Verschlüsselung &amp; Codec</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="code-pill">1000</span></td>
                                <td>
                                    <span data-lang="tr">Donanım IP Telefon / Masaüstü SIP</span>
                                    <span data-lang="en">Hardware IP Phone / Desktop SIP</span>
                                    <span data-lang="de">Hardware-IP-Telefon / Tischapparat</span>
                                </td>
                                <td>UDP / TLS (Port 5060/5061)</td>
                                <td>SDES-SRTP / G.711a, G.722, G.729</td>
                            </tr>
                            <tr>
                                <td><span class="code-pill">1000-webrtc</span></td>
                                <td>
                                    <span data-lang="tr">Web Tarayıcı &amp; Native Mobil İstemci</span>
                                    <span data-lang="en">Web Browser &amp; Native Mobile App</span>
                                    <span data-lang="de">Web-Browser &amp; Native Mobil-App</span>
                                </td>
                                <td>WSS (Port 443 ALPN / 8089)</td>
                                <td>DTLS-SRTP + ICE / Opus (HD Audio)</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="callout callout-info">
                    <span class="callout-icon">📞</span>
                    <div class="callout-body">
                        <div data-lang="tr">
                            <strong>Çatal Zil (Forked Ringing):</strong> Dahili 1000 arandığında Asterisk dialplanı <code>Dial(PJSIP/1000&amp;PJSIP/1000-webrtc,30)</code> komutuyla masadaki donanım telefonu ile cepteki mobil uygulamayı aynı anda çaldırır. İlk cevaplanan çağrıyı alır, diğeri sessizce kapanır.
                        </div>
                        <div data-lang="en">
                            <strong>Forked Ringing:</strong> When extension 1000 is dialed, Asterisk triggers <code>Dial(PJSIP/1000&amp;PJSIP/1000-webrtc,30)</code>, ringing both the desk phone and the mobile smartphone simultaneously. Whichever picks up first takes the call; the other cancels silently.
                        </div>
                        <div data-lang="de">
                            <strong>Parallelruf (Forked Ringing):</strong> Wird Nebenstelle 1000 angerufen, führt Asterisk <code>Dial(PJSIP/1000&amp;PJSIP/1000-webrtc,30)</code> aus. Tischtelefon und Smartphone klingeln gleichzeitig. Das zuerst angenommene Gerät übernimmt das Gespräch.
                        </div>
                    </div>
                </div>
            </section>

            <!-- Section 4: Go WebSocket Chat Motoru -->
            <section class="docs-section" id="go-chat">
                <h2>
                    <span data-lang="tr">4. Bağımsız Go Real-Time WebSocket Servisi</span>
                    <span data-lang="en">4. Standalone Go Real-Time WebSocket Service</span>
                    <span data-lang="de">4. Eigenständiger Go-Echtzeit-WebSocket-Dienst</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        PHP; kısa ömürlü HTTP istek-yanıt döngüleri için mükemmeldir, ancak binlerce açık WebSocket bağlantısını yönetmek için optimize edilmemiştir. AiPBX, santral içi anlık mesajlaşma ve çok katılımcılı grup sohbet motorunu saf <strong>Go (Golang)</strong> ile geliştirmiştir (<code>/opt/aipbx/bin/aipbx-chat</code>):
                    </p>
                    <ul>
                        <li><strong>Mikro Bellek Tüketimi:</strong> 10.000 aktif eşzamanlı WebSocket bağlantısı yalnızca ~28 MB RAM tüketir.</li>
                        <li><strong>Goroutine Kanalları:</strong> Her bağlantı için ağır sistem iş parçacığı yerine hafif Go kanalları kullanılır.</li>
                        <li><strong>Sıfır Dış Bağımlılık:</strong> Tek bir statik ikili dosya olarak derlenir, ek kütüphane gerektirmez.</li>
                        <li><strong>Varlık (Presence) &amp; Yazıyor Bildirimi:</strong> Çevrimiçi/çevrimdışı durumu ve yazıyor bilgisi milisaniyeler içinde dağıtılır.</li>
                    </ul>
                </div>
                <div data-lang="en">
                    <p>
                        PHP is fantastic for short-lived HTTP request-response lifecycles, but inefficient for sustaining tens of thousands of idle WebSocket connections. AiPBX offloads instant messaging and group channels to a compiled <strong>Go</strong> service (<code>/opt/aipbx/bin/aipbx-chat</code>):
                    </p>
                    <ul>
                        <li><strong>Micro Memory Footprint:</strong> 10,000 active concurrent WebSocket clients consume only ~28 MB of RAM.</li>
                        <li><strong>Goroutine Concurrency:</strong> Channels handle message fanout asynchronously without OS thread overhead.</li>
                        <li><strong>Zero External Dependencies:</strong> Single self-contained binary running as an isolated systemd daemon.</li>
                        <li><strong>Presence &amp; Typing Signals:</strong> Real-time typing indicators, read receipts, and online status distributed in milliseconds.</li>
                    </ul>
                </div>
                <div data-lang="de">
                    <p>
                        PHP eignet sich hervorragend für kurzlebige HTTP-Zyklen, ist jedoch ungeeignet für zehntausende dauerhafte WebSocket-Verbindungen. AiPBX nutzt für Instant Messaging und Gruppenkanäle einen kompakten <strong>Go</strong>-Dienst (<code>/opt/aipbx/bin/aipbx-chat</code>):
                    </p>
                    <ul>
                        <li><strong>Minimaler RAM-Verbrauch:</strong> 10.000 aktive Verbindungen belegen lediglich ca. 28 MB Arbeitsspeicher.</li>
                        <li><strong>Goroutine-Kanäle:</strong> Asynchrone Nachrichtenverteilung ohne Belastung durch Betriebssystem-Threads.</li>
                        <li><strong>Keine externen Abhängigkeiten:</strong> Eine einzige statische Binärdatei, betrieben als systemd-Dienst.</li>
                        <li><strong>Echtzeit-Präsenz &amp; Tipp-Status:</strong> Tipp-Indikatoren, Lesebestätigungen und Online-Status in Millisekunden.</li>
                    </ul>
                </div>
            </section>

            <!-- Section 5: Asterisk Sync Döngüsü -->
            <section class="docs-section" id="sync-pipeline">
                <h2>
                    <span data-lang="tr">5. Asterisk Yapılandırma Senkronizasyon Döngüsü</span>
                    <span data-lang="en">5. Asterisk Configuration Sync Pipeline</span>
                    <span data-lang="de">5. Asterisk Konfigurations-Synchronisation</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        AiPBX'te Asterisk yapılandırma dosyaları asla elle düzenlenmez. Veritabanı (MariaDB), sistemin tek gerçeklik kaynağıdır (Single Source of Truth). Bir ayar değiştirildiğinde aşağıdaki atomik döngü işletilir:
                    </p>
                </div>
                <div data-lang="en">
                    <p>
                        Asterisk configuration files in AiPBX are never edited by hand. The MariaDB database is the single source of truth. Whenever changes occur, an atomic generation and rollback pipeline executes:
                    </p>
                </div>
                <div data-lang="de">
                    <p>
                        Asterisk-Konfigurationsdateien werden in AiPBX niemals manuell editiert. Die MariaDB-Datenbank ist die zentrale Informationsquelle. Bei Änderungen greift ein atomarer Generierungs- und Rollback-Zyklus:
                    </p>
                </div>

                <div class="docs-code-box">
                    <div class="docs-code-header">
                        <span data-lang="tr">Yapılandırma &amp; Otomatik Geri Alma Akışı</span>
                        <span data-lang="en">Config Generation &amp; Automatic Rollback Flow</span>
                        <span data-lang="de">Konfigurations- &amp; Rollback-Ablauf</span>
                    </div>
                    <div class="docs-code-body">
<pre><code>Web UI / REST API Request
        │
        ▼
Controller Validation &amp; Auth Check
        │
        ▼
Service writes to MariaDB (DML)
        │
        ▼
Sync Generator (src/sync/*.php)
        ├── 1. Backs up existing file to .bak
        ├── 2. Queries DB and generates modular files under /etc/asterisk/pbx/*.conf
        └── 3. Issues Asterisk reload command (dialplan reload, queue reload...)
                │
                ├── [ SUCCESS ] ──► Updates audit log, removes .bak cleanly.
                └── [ FAILURE ] ──► AUTOMATIC ROLLBACK: Restores .bak immediately,
                                    prevents downtime, and logs error report.</code></pre>
                    </div>
                </div>
            </section>

            <!-- Section 6: İki Kademeli Veritabanı -->
            <section class="docs-section" id="db-security">
                <h2>
                    <span data-lang="tr">6. İki Kademeli MariaDB Güvenlik Modeli</span>
                    <span data-lang="en">6. Two-Tier MariaDB Privilege Security Model</span>
                    <span data-lang="de">6. Zweistufiges MariaDB-Berechtigungsmodell</span>
                </h2>
                <div class="docs-table-wrapper">
                    <table class="docs-table">
                        <thead>
                            <tr>
                                <th>
                                    <span data-lang="tr">Kullanıcı Adı</span>
                                    <span data-lang="en">User Account</span>
                                    <span data-lang="de">Benutzerkonto</span>
                                </th>
                                <th>
                                    <span data-lang="tr">Kullanım Alanı</span>
                                    <span data-lang="en">Runtime Scope</span>
                                    <span data-lang="de">Einsatzbereich</span>
                                </th>
                                <th>
                                    <span data-lang="tr">SQL Yetkileri</span>
                                    <span data-lang="en">Granted SQL Privileges</span>
                                    <span data-lang="de">Erlaubte SQL-Rechte</span>
                                </th>
                                <th>
                                    <span data-lang="tr">Kısıtlanan Yetkiler</span>
                                    <span data-lang="en">Restricted Privileges</span>
                                    <span data-lang="de">Eingeschränkte Rechte</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="code-pill">aipbx_portal</span></td>
                                <td>
                                    <span data-lang="tr">PHP Web Paneli &amp; REST API</span>
                                    <span data-lang="en">PHP Web Portal &amp; REST API</span>
                                    <span data-lang="de">PHP-Webportal &amp; REST-API</span>
                                </td>
                                <td><span class="tag-badge tag-green">SELECT, INSERT, UPDATE, DELETE</span></td>
                                <td>DROP, ALTER, CREATE, GRANT (Forbidden)</td>
                            </tr>
                            <tr>
                                <td><span class="code-pill">aipbx_migrator</span></td>
                                <td>
                                    <span data-lang="tr">Phinx Veritabanı Göçleri (CLI)</span>
                                    <span data-lang="en">Phinx Database Migrations (CLI)</span>
                                    <span data-lang="de">Phinx Datenbank-Migrationen (CLI)</span>
                                </td>
                                <td><span class="tag-badge tag-blue">CREATE, ALTER, INDEX, DROP</span></td>
                                <td>Local CLI only during schema migrations</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>


            <div class="docs-pagination">
                <a href="/" class="pagination-btn">
                    <span class="pagination-label">
                        <span data-lang="tr">← Önceki</span>
                        <span data-lang="en">← Previous</span>
                        <span data-lang="de">← Zurück</span>
                    </span>
                    <span class="pagination-title">
                        <span data-lang="tr">Genel Bakış</span>
                        <span data-lang="en">Overview</span>
                        <span data-lang="de">Übersicht</span>
                    </span>
                </a>
                <a href="/callcenter" class="pagination-btn next">
                    <span class="pagination-label">
                        <span data-lang="tr">Sonraki →</span>
                        <span data-lang="en">Next →</span>
                        <span data-lang="de">Weiter →</span>
                    </span>
                    <span class="pagination-title">
                        <span data-lang="tr">Çağrı Merkezi & Mola</span>
                        <span data-lang="en">Call Center & Breaks</span>
                        <span data-lang="de">Callcenter & Pausen</span>
                    </span>
                </a>
            </div>
    
        </main>

        <!-- Right Sticky Table of Contents -->
        <div class="docs-toc">
            <div class="toc-title">
                <span data-lang="tr">Bu Sayfada</span>
                <span data-lang="en">On This Page</span>
                <span data-lang="de">Auf dieser Seite</span>
            </div>
            <ul class="toc-list">
                <li><a href="#overview">1. Layered Architecture</a></li>
                <li><a href="#alpn">2. Port 443 ALPN</a></li>
                <li><a href="#dual-endpoint">3. Dual-Endpoint PJSIP</a></li>
                <li><a href="#go-chat">4. Go WebSocket Chat</a></li>
                <li><a href="#sync-pipeline">5. Asterisk Sync Pipeline</a></li>
                <li><a href="#db-security">6. MariaDB Security</a></li>
            </ul>
        </div>
    </div>

    <!-- Universal Footer -->

    <!-- Universal Footer -->
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
