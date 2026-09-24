<?php
/**
 * AiPBX.bid — REST API & WebSocket Dokümantasyonu — Geliştirici Kılavuzu & Entegrasyon | AiPBX
 */
$page = 'api-docs';
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
                        <a href="/api-docs" class="active">
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
                <a href="/api-docs">
                    <span data-lang="tr">Geliştirici</span><span data-lang="en">Developer</span><span data-lang="de">Entwickler</span>
                </a> / 
                <span>
                    <span data-lang="tr">REST API &amp; SDK</span><span data-lang="en">REST API &amp; SDK</span><span data-lang="de">REST-API &amp; SDK</span>
                </span>
            </div>

            <div class="docs-header">
                <h1>
                    <span data-lang="tr">🔌 REST API &amp; Geliştirici Kılavuzu</span>
                    <span data-lang="en">🔌 REST API &amp; Developer Reference</span>
                    <span data-lang="de">🔌 REST-API &amp; Entwickler-Referenz</span>
                </h1>
                <p class="lead" data-lang="tr">
                    AiPBX; CRM sistemleri, ERP yazılımları, mobil istemciler ve harici çağrı merkezi panelleri ile çift yönlü entegrasyon kurabilen modern REST API ve WebSocket arayüzleri sunar.
                </p>
                <p class="lead" data-lang="en">
                    AiPBX exposes structured REST endpoints and WebSocket protocols for bidirectional integration with CRMs, ERPs, call center wallboards, and mobile softphones.
                </p>
                <p class="lead" data-lang="de">
                    AiPBX stellt strukturierte REST-Endpunkte und WebSocket-Protokolle für die bidirektionale Anbindung an CRMs, ERP-Systeme, Callcenter-Wallboards und Mobil-Softphones bereit.
                </p>
            </div>

            <section class="docs-section" id="architecture-conventions">
                <h2>
                    <span data-lang="tr">1. Kod Tabanı &amp; Mimari Standartları</span>
                    <span data-lang="en">1. Codebase &amp; Architectural Standards</span>
                    <span data-lang="de">1. Codebasis- &amp; Architekturstandards</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        AiPBX web katmanı, harici framework'lere bağımlı olmaksızın saf bir <strong>PHP 8 MVC</strong> mimarisi uygular:
                    </p>
                    <ul>
                        <li><strong>Beyaz Liste Yönlendirici (Whitelist Router):</strong> <code>index.php</code> dosyasındaki <code>$ROUTES</code> dizisinde tanımlanmamış hiçbir URL çalıştırılamaz.</li>
                        <li><strong>src/controllers/:</strong> Yalnızca yetki denetimi, HTTP girdi doğrulama ve yanıt orkestrasyonu yapar.</li>
                        <li><strong>src/services/:</strong> İş kurallarını (business logic) işletir, veritabanına yazar ve Asterisk senkronizasyonunu tetikler.</li>
                        <li><strong>src/sync/:</strong> Değişiklikleri Asterisk <code>.conf</code> dosyalarına dönüştürür ve geri alma (rollback) güvencesiyle Asterisk'i yeniden yükler.</li>
                    </ul>
                </div>
                <div data-lang="en">
                    <p>
                        AiPBX web core runs on a clean, dependency-free <strong>PHP 8 MVC</strong> pattern:
                    </p>
                    <ul>
                        <li><strong>Whitelist Front Router:</strong> Only routes defined in <code>index.php</code>'s <code>$ROUTES</code> are reachable; unknown paths trigger an instant 404.</li>
                        <li><strong>src/controllers/:</strong> Authentication checks, input sanitization, and response orchestration only.</li>
                        <li><strong>src/services/:</strong> Business logic, database mutations, and triggering Asterisk synchronization.</li>
                        <li><strong>src/sync/:</strong> Generates modular Asterisk configuration with automatic <code>.bak</code> rollback safety.</li>
                    </ul>
                </div>
                <div data-lang="de">
                    <p>
                        Der AiPBX-Webkern basiert auf einem sauberen, schlanken <strong>PHP 8 MVC</strong>-Muster:
                    </p>
                    <ul>
                        <li><strong>Whitelist-Front-Router:</strong> Nur in <code>index.php</code> (<code>$ROUTES</code>) definierte Pfade sind erreichbar; alle anderen erzeugen 404.</li>
                        <li><strong>src/controllers/:</strong> Zugriffskontrolle, Eingabeprüfung und Orchestrierung.</li>
                        <li><strong>src/services/:</strong> Geschäftslogik, Datenbankoperationen und Asterisk-Synchronisation.</li>
                        <li><strong>src/sync/:</strong> Erzeugt modulare Asterisk-Konfigurationsdateien mit automatischer <code>.bak</code>-Rollback-Sicherheit.</li>
                    </ul>
                </div>
            </section>

            <section class="docs-section" id="rest-endpoints">
                <h2>
                    <span data-lang="tr">2. REST API Uç Noktaları (Endpoints)</span>
                    <span data-lang="en">2. REST API Endpoints</span>
                    <span data-lang="de">2. REST-API-Endpunkte</span>
                </h2>
                <div class="docs-table-wrapper">
                    <table class="docs-table">
                        <thead>
                            <tr>
                                <th>Method</th>
                                <th>Endpoint</th>
                                <th>
                                    <span data-lang="tr">Açıklama</span><span data-lang="en">Description</span><span data-lang="de">Beschreibung</span>
                                </th>
                                <th>Auth</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="tag-badge tag-green">POST</span></td>
                                <td><code>/api/auth/login</code></td>
                                <td>
                                    <span data-lang="tr">Dahili kimlik doğrulama ve oturum açma</span>
                                    <span data-lang="en">Extension credentials authentication</span>
                                    <span data-lang="de">Authentifizierung &amp; Session-Start</span>
                                </td>
                                <td>Public</td>
                            </tr>
                            <tr>
                                <td><span class="tag-badge tag-blue">GET</span></td>
                                <td><code>/api/mobile/contacts.php</code></td>
                                <td>
                                    <span data-lang="tr">Kurumsal dahili rehberi ve anlık çevrimiçi varlık</span>
                                    <span data-lang="en">Corporate directory with real-time presence</span>
                                    <span data-lang="de">Unternehmensverzeichnis mit Live-Präsenz</span>
                                </td>
                                <td>User</td>
                            </tr>
                            <tr>
                                <td><span class="tag-badge tag-blue">GET</span></td>
                                <td><code>/api/mobile/history.php</code></td>
                                <td>
                                    <span data-lang="tr">Arama geçmişi ve net konuşma süreleri (CDR)</span>
                                    <span data-lang="en">Call detail history and talk durations (CDR)</span>
                                    <span data-lang="de">Anrufhistorie und Gesprächsdauern (CDR)</span>
                                </td>
                                <td>User</td>
                            </tr>
                            <tr>
                                <td><span class="tag-badge tag-green">POST</span></td>
                                <td><code>/api/cc_actions/agent.php</code></td>
                                <td>
                                    <span data-lang="tr">Temsilci mola (*22/*23) ve kuyruk giriş/çıkış kontrolü</span>
                                    <span data-lang="en">Agent pause/unpause (*22/*23) and queue state</span>
                                    <span data-lang="de">Agenten-Pause (*22/*23) und Warteschlangen-Status</span>
                                </td>
                                <td>Agent</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="docs-section" id="tests">
                <h2>
                    <span data-lang="tr">3. Otomatik Test Paketi (PHPUnit CI/CD)</span>
                    <span data-lang="en">3. Automated Test Suite (PHPUnit CI/CD)</span>
                    <span data-lang="de">3. Automatisierte Test-Suite (PHPUnit CI/CD)</span>
                </h2>
                <div class="docs-code-box">
                    <div class="docs-code-header">
                        <span>bash — Unit Tests</span>
                        <button class="docs-code-copy">Copy</button>
                    </div>
                    <div class="docs-code-body">
<pre><code># Prepare test database
bash bin/setup-test-db.sh

# Execute PHPUnit suite (142 tests, 350 assertions)
./vendor/bin/phpunit</code></pre>
                    </div>
                </div>
            </section>

            <div class="docs-pagination">
                <a href="/security" class="pagination-btn">
                    <span class="pagination-label">
                        <span data-lang="tr">← Önceki</span>
                        <span data-lang="en">← Previous</span>
                        <span data-lang="de">← Zurück</span>
                    </span>
                    <span class="pagination-title">
                        <span data-lang="tr">Güvenlik</span>
                        <span data-lang="en">Security</span>
                        <span data-lang="de">Sicherheit</span>
                    </span>
                </a>
                <a href="/" class="pagination-btn next">
                    <span class="pagination-label">
                        <span data-lang="tr">Sonraki →</span>
                        <span data-lang="en">Next →</span>
                        <span data-lang="de">Weiter →</span>
                    </span>
                    <span class="pagination-title">
                        <span data-lang="tr">Genel Bakış (Home)</span>
                        <span data-lang="en">Overview (Home)</span>
                        <span data-lang="de">Übersicht (Home)</span>
                    </span>
                </a>
            </div>
    
        </main>

        <div class="docs-toc">
            <div class="toc-title">
                <span data-lang="tr">Bu Sayfada</span><span data-lang="en">On This Page</span><span data-lang="de">Auf dieser Seite</span>
            </div>
            <ul class="toc-list">
                <li><a href="#architecture-conventions">1. Architecture Standards</a></li>
                <li><a href="#rest-endpoints">2. REST Endpoints</a></li>
                <li><a href="#tests">3. PHPUnit Tests</a></li>
            </ul>
        </div>
    </div>

    <!-- Universal Footer -->

    <!-- Universal Footer -->
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
