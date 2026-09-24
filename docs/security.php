<?php
/**
 * AiPBX.bid — Santral Güvenliği & CCIS Gateway — Fail2ban, TLS 1.3, SRTP Şifreleme | AiPBX
 */
$page = 'security';
require_once __DIR__ . '/includes/data.php';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($LANG) ?>" data-lang="<?= htmlspecialchars($LANG) ?>">
<head>
    <?php include __DIR__ . '/includes/head.php'; ?>
    <title>Santral Güvenliği & CCIS Gateway — Fail2ban, TLS 1.3, SRTP Şifreleme | AiPBX</title>
    <meta name="description" content="AiPBX güvenlik savunma katmanları: Fail2ban kaba kuvvet saldırı koruması, TLS 1.3 ve SRTP ses şifreleme, NEC UNIVERGE SV8100/SV8300/SV8500 CCIS protokol gateway entegrasyonu.">
    <link rel="canonical" href="https://aipbx.bid/security.html">
    <link rel="alternate" hreflang="tr" href="https://aipbx.bid/security.html">
    <link rel="alternate" hreflang="en" href="https://aipbx.bid/security.html?lang=en">
    <link rel="alternate" hreflang="de" href="https://aipbx.bid/security.html?lang=de">
    <link rel="alternate" hreflang="x-default" href="https://aipbx.bid/security.html">
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
              "name": "Ana Sayfa",
              "item": "https://aipbx.bid/"
            },
            {
              "@type": "ListItem",
              "position": 2,
              "name": "Güvenlik",
              "item": "https://aipbx.bid/security.html"
            }
          ]
        },
        {
          "@type": "TechArticle",
          "headline": "AiPBX Telephony Security Hardening and Microsoft Teams Direct Routing",
          "description": "Multi-layer VoIP security defense: Fail2ban brute-force protection, TLS 1.3/SRTP encryption, and Microsoft Teams Direct Routing integration.",
          "url": "https://aipbx.bid/security.html",
          "author": {
            "@type": "Person",
            "name": "Mahir Gül",
            "url": "https://www.mhrgl.com"
          },
          "publisher": {
            "@type": "Organization",
            "name": "AiPBX",
            "url": "https://aipbx.bid/"
          }
        }
      ]
    }
    </script>
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
                        <a href="index.html">
                            <span data-lang="tr">🏠 Genel Bakış (Overview)</span>
                            <span data-lang="en">🏠 Overview (Home)</span>
                            <span data-lang="de">🏠 Übersicht (Home)</span>
                        </a>
                    </li>
                    <li>
                        <a href="architecture.html" class="">
                            <span data-lang="tr">🏗️ Sistem Mimarisi &amp; ALPN</span>
                            <span data-lang="en">🏗️ Architecture &amp; ALPN</span>
                            <span data-lang="de">🏗️ Architektur &amp; ALPN</span>
                        </a>
                    </li>
                    <li>
                        <a href="features.html" class="">
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
                        <a href="callcenter.html" class="">
                            <span data-lang="tr">🎧 Kuyruk Yönetimi</span>
                            <span data-lang="en">🎧 Queue Operations</span>
                            <span data-lang="de">🎧 Warteschlangen-Betrieb</span>
                        </a>
                    </li>
                    <li>
                        <a href="callcenter.html#break-codes" class="">
                            <span data-lang="tr">⏸️ Mola Kodları (*22/*23) <span class="sidebar-badge">Yeni</span></span>
                            <span data-lang="en">⏸️ Break Codes (*22/*23) <span class="sidebar-badge">New</span></span>
                            <span data-lang="de">⏸️ Pausencodes (*22/*23) <span class="sidebar-badge">Neu</span></span>
                        </a>
                    </li>
                    <li>
                        <a href="callcenter.html#wallboard">
                            <span data-lang="tr">📊 Canlı Wallboard &amp; SLA</span>
                            <span data-lang="en">📊 Live Wallboard &amp; SLA</span>
                            <span data-lang="de">📊 Live-Wallboard &amp; SLA</span>
                        </a>
                    </li>
                    <li>
                        <a href="callcenter.html#supervisor">
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
                        <a href="mobile-apps.html" class="">
                            <span data-lang="tr">📱 Android &amp; iOS İstemcileri</span>
                            <span data-lang="en">📱 Android &amp; iOS Clients</span>
                            <span data-lang="de">📱 Android &amp; iOS Clients</span>
                        </a>
                    </li>
                    <li>
                        <a href="mobile-apps.html#downloads">
                            <span data-lang="tr">⬇️ APK / IPA / Simulator</span>
                            <span data-lang="en">⬇️ APK / IPA / Simulator</span>
                            <span data-lang="de">⬇️ APK / IPA / Simulator</span>
                        </a>
                    </li>
                    <li>
                        <a href="mobile-apps.html#android">
                            <span data-lang="tr">🤖 Kotlin Native &amp; FCM Push</span>
                            <span data-lang="en">🤖 Kotlin Native &amp; FCM Push</span>
                            <span data-lang="de">🤖 Kotlin Native &amp; FCM-Push</span>
                        </a>
                    </li>
                    <li>
                        <a href="mobile-apps.html#ios">
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
                        <a href="installation.html" class="">
                            <span data-lang="tr">🚀 Hızlı Kurulum (install.sh)</span>
                            <span data-lang="en">🚀 Quick Install (install.sh)</span>
                            <span data-lang="de">🚀 Schnellinstallation (install.sh)</span>
                        </a>
                    </li>
                    <li>
                        <a href="installation.html#ports">
                            <span data-lang="tr">🌐 Port &amp; Güvenlik Duvarı</span>
                            <span data-lang="en">🌐 Ports &amp; Firewall</span>
                            <span data-lang="de">🌐 Ports &amp; Firewall</span>
                        </a>
                    </li>
                    <li>
                        <a href="security.html" class="active">
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
                        <a href="api-docs.html" class="">
                            <span data-lang="tr">🔌 REST API Referansı</span>
                            <span data-lang="en">🔌 REST API Reference</span>
                            <span data-lang="de">🔌 REST-API Referenz</span>
                        </a>
                    </li>
                    <li>
                        <a href="api-docs.html#websocket">
                            <span data-lang="tr">💬 Go WebSocket Protokolü</span>
                            <span data-lang="en">💬 Go WebSocket Protocol</span>
                            <span data-lang="de">💬 Go WebSocket Protokoll</span>
                        </a>
                    </li>
                    <li>
                        <a href="api-docs.html#tests">
                            <span data-lang="tr">🧪 PHPUnit Testleri (142 Test)</span>
                            <span data-lang="en">🧪 PHPUnit Test Suite</span>
                            <span data-lang="de">🧪 PHPUnit Test-Suite</span>
                        </a>
                    </li>
                </ul>
            </div>
        </aside>

        <main class="docs-content">
            <div class="docs-breadcrumb">
                <a href="index.html">
                    <span data-lang="tr">Ana Sayfa</span><span data-lang="en">Home</span><span data-lang="de">Startseite</span>
                </a> / 
                <a href="security.html">
                    <span data-lang="tr">Güvenlik</span><span data-lang="en">Security</span><span data-lang="de">Sicherheit</span>
                </a> / 
                <span>
                    <span data-lang="tr">Sertleştirme</span><span data-lang="en">Hardening</span><span data-lang="de">Härtung</span>
                </span>
            </div>

            <div class="docs-header">
                <h1>
                </h1>
                <p class="lead" data-lang="tr">
                    AiPBX; kurumsal VoIP ağlarının karşılaştığı SIP brute-force saldırılarını ve dinleme tehditlerini bertaraf eden çok katmanlı savunma mekanizmalarına sahiptir. Ayrıca Microsoft Teams Direct Routing entegrasyonu ile modern iş iletişimi platformlarıyla sorunsuz bağlantı sağlar.
                </p>
                <p class="lead" data-lang="en">
                    AiPBX incorporates multi-tier defense mechanisms against brute-force VoIP scanners and packet sniffers. It also features Microsoft Teams Direct Routing integration for seamless enterprise unified communications.
                </p>
                <p class="lead" data-lang="de">
                    AiPBX bietet mehrschichtige Abwehrmechanismen gegen Brute-Force-Scans und Abhörversuche. Zudem integriert es Microsoft Teams Direct Routing für nahtlose Unternehmenskommunikation.
                </p>
            </div>

            <section class="docs-section" id="defense">
                <h2>
                    <span data-lang="tr">1. Çok Katmanlı Savunma Mimarisi (Defense-in-Depth)</span>
                    <span data-lang="en">1. Defense-in-Depth Architecture</span>
                    <span data-lang="de">1. Mehrstufige Sicherheitsarchitektur</span>
                </h2>
                <div class="docs-table-wrapper">
                    <table class="docs-table">
                        <thead>
                            <tr>
                                <th>
                                    <span data-lang="tr">Katman</span><span data-lang="en">Security Tier</span><span data-lang="de">Sicherheits-Ebene</span>
                                </th>
                                <th>
                                    <span data-lang="tr">Uygulanan Güvenlik Politikası</span><span data-lang="en">Enforced Policy</span><span data-lang="de">Angewandte Richtlinie</span>
                                </th>
                                <th>
                                    <span data-lang="tr">Sağlanan Koruma</span><span data-lang="en">Mitigated Threat</span><span data-lang="de">Schutzwirkung</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Edge L4 Stream</strong></td>
                                <td>Nginx L4 Stream + ALPN Inspection</td>
                                <td>
                                    <span data-lang="tr">Gereksiz portları kapatır, rastgele port taramalarını boşa çıkarır.</span>
                                    <span data-lang="en">Minimizes attack surface by tunneling over port 443; drops unauthorized probes.</span>
                                    <span data-lang="de">Minimiert Angriffsfläche durch Bündelung auf Port 443.</span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Transport Encryption</strong></td>
                                <td>TLS 1.3, DTLS-SRTP, SDES-SRTP, TURNS</td>
                                <td>
                                    <span data-lang="tr">Paket dinleme (Sniffing, Wireshark) saldırılarında sesin dinlenmesini önler.</span>
                                    <span data-lang="en">Prevents audio eavesdropping and credential sniffing across public networks.</span>
                                    <span data-lang="de">Verhindert Abhören von Sprachdaten und Zugangsdaten im Netzwerk.</span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Rate Limiting &amp; Jails</strong></td>
                                <td>Fail2ban PBX Jails + Nginx Leaky Bucket</td>
                                <td>
                                    <span data-lang="tr">SIP brute-force botlarını ve şifre deneyen tarayıcıları anında banlar.</span>
                                    <span data-lang="en">Instantly bans SIP brute-force bots and aggressive scanners (sipvicious).</span>
                                    <span data-lang="de">Sperrt SIP-Brute-Force-Bots und Scanner automatisch via iptables.</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="docs-section" id="fail2ban">
                <h2>
                    <span data-lang="tr">2. Fail2ban PBX Jails &amp; Otomatik Engelleme</span>
                    <span data-lang="en">2. Fail2ban PBX Jails &amp; Automated Banning</span>
                    <span data-lang="de">2. Fail2ban PBX-Jails &amp; Automatische Sperren</span>
                </h2>
                <div class="docs-code-box">
                    <div class="docs-code-header">
                        <span>/etc/fail2ban/jail.d/asterisk.conf</span>
                    </div>
                    <div class="docs-code-body">
<pre><code>[asterisk-pjsip]
enabled  = true
filter   = asterisk-pjsip
action   = iptables-allports[name=ASTERISK, protocol=all]
logpath  = /var/log/asterisk/messages
maxretry = 5
findtime = 600
bantime  = 86400</code></pre>
                    </div>
                </div>
            </section>


            <div class="docs-pagination">
                <a href="installation.html" class="pagination-btn">
                    <span class="pagination-label">
                        <span data-lang="tr">← Önceki</span>
                        <span data-lang="en">← Previous</span>
                        <span data-lang="de">← Zurück</span>
                    </span>
                    <span class="pagination-title">
                        <span data-lang="tr">Kurulum Rehberi</span>
                        <span data-lang="en">Install Guide</span>
                        <span data-lang="de">Installationsanleitung</span>
                    </span>
                </a>
                <a href="api-docs.html" class="pagination-btn next">
                    <span class="pagination-label">
                        <span data-lang="tr">Sonraki →</span>
                        <span data-lang="en">Next →</span>
                        <span data-lang="de">Weiter →</span>
                    </span>
                    <span class="pagination-title">
                        <span data-lang="tr">REST API & Dev</span>
                        <span data-lang="en">REST API & Dev</span>
                        <span data-lang="de">REST-API & Dev</span>
                    </span>
                </a>
            </div>
    
        </main>

        <div class="docs-toc">
            <div class="toc-title">
                <span data-lang="tr">Bu Sayfada</span><span data-lang="en">On This Page</span><span data-lang="de">Auf dieser Seite</span>
            </div>
            <ul class="toc-list">
                <li><a href="#defense">1. Defense-in-Depth</a></li>
                <li><a href="#fail2ban">2. Fail2ban Jails</a></li>
            </ul>
        </div>
    </div>

    <!-- Universal Footer -->

    <!-- Universal Footer -->
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
