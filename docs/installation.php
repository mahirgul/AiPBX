<?php
/**
 * AiPBX.bid — Kurulum ve Yönetim Rehberi — Debian & Ubuntu Asterisk PBX Dağıtımı | AiPBX
 */
$page = 'installation';
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
                        <a href="/architecture" class="">
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
                        <a href="/installation" class="active">
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

        <main class="docs-content">
            <div class="docs-breadcrumb">
                <a href="/">
                    <span data-lang="tr">Ana Sayfa</span><span data-lang="en">Home</span><span data-lang="de">Startseite</span>
                </a> / 
                <a href="/installation">
                    <span data-lang="tr">Yönetim</span><span data-lang="en">Admin</span><span data-lang="de">Admin</span>
                </a> / 
                <span>
                    <span data-lang="tr">Kurulum Kılavuzu</span><span data-lang="en">Install Guide</span><span data-lang="de">Installationsanleitung</span>
                </span>
            </div>

            <div class="docs-header">
                <h1>
                    <span data-lang="tr">🚀 Kurulum &amp; Sistem Yönetim Kılavuzu</span>
                    <span data-lang="en">🚀 Installation &amp; Administration Guide</span>
                    <span data-lang="de">🚀 Installations- &amp; Administrationshandbuch</span>
                </h1>
                <p class="lead" data-lang="tr">
                    AiPBX; Asterisk 22, MariaDB 11, Nginx L4, Apache 2.4, Go Chat motoru ve Coturn TURNS servisini tek bir otomatik betikle (<code>install.sh</code>) 5 dakikada kurup çalışır hale getirir.
                </p>
                <p class="lead" data-lang="en">
                    AiPBX automates the entire stack (Asterisk 22, MariaDB 11, Nginx L4, Apache 2.4, Go Chat, Coturn TURNS) with zero hardcoded credentials in under 5 minutes via <code>install.sh</code>.
                </p>
                <p class="lead" data-lang="de">
                    AiPBX automatisiert den gesamten Software-Stack (Asterisk 22, MariaDB 11, Nginx L4, Apache 2.4, Go-Chat, Coturn TURNS) ohne feste Passwörter in unter 5 Minuten mit <code>install.sh</code>.
                </p>
            </div>

            <section class="docs-section" id="requirements">
                <h2>
                    <span data-lang="tr">1. Sistem Gereksinimleri &amp; Donanım Boyutlandırma</span>
                    <span data-lang="en">1. System Requirements &amp; Hardware Sizing</span>
                    <span data-lang="de">1. Systemanforderungen &amp; Hardware-Dimensionierung</span>
                </h2>
                <div class="docs-table-wrapper">
                    <table class="docs-table">
                        <thead>
                            <tr>
                                <th>Param</th>
                                <th>
                                    <span data-lang="tr">Minimum (Test / Geliştirme)</span>
                                    <span data-lang="en">Minimum Specs (Dev / Test)</span>
                                    <span data-lang="de">Minimalanforderung (Test)</span>
                                </th>
                                <th>
                                    <span data-lang="tr">Önerilen Prodüksiyon (50-200 Dahili)</span>
                                    <span data-lang="en">Production Recommended (50-200 Ext)</span>
                                    <span data-lang="de">Produktion (50-200 Nebenstellen)</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>OS</strong></td>
                                <td>Ubuntu Server 22.04 / 24.04 LTS (x86_64)</td>
                                <td>Ubuntu 24.04 LTS or RHEL / Rocky Linux 9</td>
                            </tr>
                            <tr>
                                <td><strong>CPU</strong></td>
                                <td>2 vCPU</td>
                                <td>4 vCPU+ (Intel Xeon / AMD EPYC / Ryzen)</td>
                            </tr>
                            <tr>
                                <td><strong>RAM</strong></td>
                                <td>2 GB</td>
                                <td>4 GB – 8 GB RAM</td>
                            </tr>
                            <tr>
                                <td><strong>Storage</strong></td>
                                <td>15 GB SSD</td>
                                <td>50 GB+ NVMe SSD (for CDR Call Recordings)</td>
                            </tr>
                            <tr>
                                <td><strong>Network</strong></td>
                                <td>Static Local IP</td>
                                <td>Static Public IPv4 &amp; Pointed FQDN Domain</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="docs-section" id="quick-install">
                <h2>
                    <span data-lang="tr">2. Anahtar Teslim Otomatik Kurulum (Turnkey Install)</span>
                    <span data-lang="en">2. Turnkey Automated Installation</span>
                    <span data-lang="de">2. Schlüsselfertige automatische Installation</span>
                </h2>
                <div class="docs-code-box">
                    <div class="docs-code-header">
                        <span>bash — Ubuntu Server</span>
                        <button class="docs-code-copy">Copy</button>
                    </div>
                    <div class="docs-code-body">
<pre><code># 1. Clone repository
git clone https://github.com/mahirgul/AiPBX.git /opt/aipbx
cd /opt/aipbx

# 2. Run automated installer as root
sudo bash install.sh</code></pre>
                    </div>
                </div>

                <h3>
                    <span data-lang="tr">Kurulum Betiğinin Otomatik Yaptığı İşlemler:</span>
                    <span data-lang="en">What the Installer Automates:</span>
                    <span data-lang="de">Automatisierte Installationsschritte:</span>
                </h3>
                <div data-lang="tr">
                    <ol>
                        <li><strong>Paket Kurulumları:</strong> Asterisk 22, Apache 2.4, Nginx L4 Stream, MariaDB 11, PHP 8 ve tüm uzantıları, Go, Coturn ve Fail2ban kurulur.</li>
                        <li><strong>Dinamik Kriptografik Parolalar:</strong> Kodda hiçbir sabit parola bırakılmaz; veritabanı kullanıcıları, Asterisk AMI ve coturn için 32 karakterlik rastgele güçlü şifreler üretilir.</li>
                        <li><strong>Otomatik SSL Sertifikası:</strong> Alan adınız (FQDN) girildiğinde Let's Encrypt Certbot ile gerçek SSL sertifikası alınır veya SAN yerel SSL üretilir.</li>
                        <li><strong>Veritabanı Şeması:</strong> Phinx migration ve seed tabloları yüklenerek varsayılan yönetici hesabı oluşturulur.</li>
                        <li><strong>Parola Kasası:</strong> Üretilen tüm şifreler yalnızca root'un okuyabileceği <code>/root/aipbx-credentials.txt</code> (chmod 600) dosyasına kaydedilir.</li>
                    </ol>
                </div>
                <div data-lang="en">
                    <ol>
                        <li><strong>Stack Provisioning:</strong> Installs Asterisk 22, Apache 2.4, Nginx L4 Stream, MariaDB 11, PHP 8 with extensions, Go compiler, Coturn, and Fail2ban.</li>
                        <li><strong>Dynamic Cryptographic Passwords:</strong> Generates unique 32-character random secrets for MariaDB users, AMI, coturn, and admin portal.</li>
                        <li><strong>Automated TLS Provisioning:</strong> Interactively prompts for FQDN domain and obtains free Let's Encrypt certificates via Certbot (or generates self-signed SAN SSL).</li>
                        <li><strong>Schema Migrations:</strong> Executes Phinx database migrations and seeds initial admin accounts.</li>
                        <li><strong>Credential Safe:</strong> Prints complete terminal summary and writes protected credentials to <code>/root/aipbx-credentials.txt</code> (chmod 600).</li>
                    </ol>
                </div>
                <div data-lang="de">
                    <ol>
                        <li><strong>Software-Pakete:</strong> Installiert Asterisk 22, Apache 2.4, Nginx L4 Stream, MariaDB 11, PHP 8 mit Erweiterungen, Go, Coturn und Fail2ban.</li>
                        <li><strong>Dynamische Passwörter:</strong> Erzeugt sichere 32-stellige Zufallspasswörter für Datenbank, AMI, Coturn und Webportal.</li>
                        <li><strong>Automatische SSL-Zertifikate:</strong> Richtet Let's Encrypt via Certbot für Ihre Domain ein (oder generiert SAN-Self-Signed SSL).</li>
                        <li><strong>Datenbank-Migrationen:</strong> Führt Phinx-Migrationen aus und richtet Standard-Benutzer ein.</li>
                        <li><strong>Passwort-Tresor:</strong> Speichert alle Zugangsdaten geschützt in <code>/root/aipbx-credentials.txt</code> (chmod 600).</li>
                    </ol>
                </div>
            </section>

            <section class="docs-section" id="ports">
                <h2>
                    <span data-lang="tr">3. Ağ ve Güvenlik Duvarı Port Tablosu</span>
                    <span data-lang="en">3. Network Ports &amp; Firewall Table</span>
                    <span data-lang="de">3. Netzwerk-Ports &amp; Firewall-Tabelle</span>
                </h2>
                <div class="docs-table-wrapper">
                    <table class="docs-table">
                        <thead>
                            <tr>
                                <th>Port</th>
                                <th>Protocol</th>
                                <th>
                                    <span data-lang="tr">Kullanım Amacı</span><span data-lang="en">Service / Purpose</span><span data-lang="de">Dienst / Verwendungszweck</span>
                                </th>
                                <th>
                                    <span data-lang="tr">Dışa Açılmalı mı?</span><span data-lang="en">Inbound WAN Access</span><span data-lang="de">WAN-Freigabe</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="code-pill">443</span></td>
                                <td>TCP</td>
                                <td>Nginx L4 ALPN Stream (Web, WebRTC WSS, Coturn TURNS)</td>
                                <td><span class="tag-badge tag-green">Required / Zorunlu</span></td>
                            </tr>
                            <tr>
                                <td><span class="code-pill">80</span></td>
                                <td>TCP</td>
                                <td>HTTP -&gt; HTTPS Redirect &amp; Let's Encrypt ACME HTTP-01</td>
                                <td><span class="tag-badge tag-green">Required / Zorunlu</span></td>
                            </tr>
                            <tr>
                                <td><span class="code-pill">10000 - 20000</span></td>
                                <td>UDP</td>
                                <td>Asterisk RTP Voice Streams (Audio Packets)</td>
                                <td><span class="tag-badge tag-green">Required / Zorunlu</span></td>
                            </tr>
                            <tr>
                                <td><span class="code-pill">5060</span></td>
                                <td>UDP / TCP</td>
                                <td>SIP Hardware Desk Phones &amp; Telecom Trunks</td>
                                <td>Optional (If using hardware SIP)</td>
                            </tr>
                            <tr>
                                <td><span class="code-pill">5061</span></td>
                                <td>TCP</td>
                                <td>SIP TLS (Encrypted desk phones)</td>
                                <td>Optional</td>
                            </tr>
                            <tr>
                                <td><span class="code-pill">5038</span></td>
                                <td>TCP</td>
                                <td>Asterisk Manager Interface (AMI - Web Portal)</td>
                                <td><span class="tag-badge tag-red">NO (127.0.0.1 Local Only)</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="docs-pagination">
                <a href="/mobile-apps" class="pagination-btn">
                    <span class="pagination-label">
                        <span data-lang="tr">← Önceki</span>
                        <span data-lang="en">← Previous</span>
                        <span data-lang="de">← Zurück</span>
                    </span>
                    <span class="pagination-title">
                        <span data-lang="tr">Mobil Uygulamalar</span>
                        <span data-lang="en">Mobile Apps</span>
                        <span data-lang="de">Mobil-Apps</span>
                    </span>
                </a>
                <a href="/security" class="pagination-btn next">
                    <span class="pagination-label">
                        <span data-lang="tr">Sonraki →</span>
                        <span data-lang="en">Next →</span>
                        <span data-lang="de">Weiter →</span>
                    </span>
                    <span class="pagination-title">
                        <span data-lang="tr">Güvenlik</span>
                        <span data-lang="en">Security</span>
                        <span data-lang="de">Sicherheit</span>
                    </span>
                </a>
            </div>
    
        </main>

        <div class="docs-toc">
            <div class="toc-title">
                <span data-lang="tr">Bu Sayfada</span><span data-lang="en">On This Page</span><span data-lang="de">Auf dieser Seite</span>
            </div>
            <ul class="toc-list">
                <li><a href="#requirements">1. System Requirements</a></li>
                <li><a href="#quick-install">2. Automated Install</a></li>
                <li><a href="#ports">3. Ports &amp; Firewall</a></li>
            </ul>
        </div>
    </div>

    <!-- Universal Footer -->

    <!-- Universal Footer -->
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
