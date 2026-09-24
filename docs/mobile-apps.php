<?php
/**
 * AiPBX.bid — Mobil Softphone Uygulamaları — Android APK & iOS WebRTC PBX | AiPBX
 */
$page = 'mobile-apps';
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
                        <a href="mobile-apps.html" class="active">
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
                        <a href="security.html" class="">
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
                <a href="mobile-apps.html">
                    <span data-lang="tr">Mobil</span><span data-lang="en">Mobile</span><span data-lang="de">Mobil</span>
                </a> / 
                <span>
                    <span data-lang="tr">Uygulamalar &amp; İndir</span><span data-lang="en">Apps &amp; Downloads</span><span data-lang="de">Apps &amp; Downloads</span>
                </span>
            </div>

            <div class="docs-header">
                <h1>
                    <span data-lang="tr">📱 Kurumsal Mobil İstemciler (Android &amp; iOS)</span>
                    <span data-lang="en">📱 Enterprise Mobile Clients (Android &amp; iOS)</span>
                    <span data-lang="de">📱 Enterprise Mobil-Clients (Android &amp; iOS)</span>
                </h1>
                <p class="lead" data-lang="tr">
                    AiPBX; saha çalışanları, uzaktan çalışanlar ve yöneticiler için harici bulut bağımlılığı olmayan, güvenli, WebRTC tabanlı yerel (native) Android ve iOS kurumsal iletişim uygulamaları sunar.
                </p>
                <p class="lead" data-lang="en">
                    AiPBX delivers native Android and iOS apps for field teams, remote employees, and executives: zero cloud reliance, direct PBX peer-to-peer encryption, and system-level incoming call wake-up.
                </p>
                <p class="lead" data-lang="de">
                    AiPBX bietet native Android- und iOS-Apps für Außendienst, Remote-Mitarbeiter und Führungskräfte: Ohne Cloud-Abhängigkeit, mit direkter Verschlüsselung und nativer System-Anrufintegration.
                </p>
            </div>

            <section class="docs-section" id="downloads">
                <h2>
                    <span data-lang="tr">1. Doğrudan Kurulum Paketleri</span>
                    <span data-lang="en">1. Direct Installer Packages</span>
                    <span data-lang="de">1. Direkte Installationspakete</span>
                </h2>

                <div class="mobile-dl-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin: 24px 0;">
                    <div style="background: #0d1524; border: 1px solid var(--border); border-radius: 12px; padding: 24px; display: flex; flex-direction: column; gap: 14px;">
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <span style="font-size: 2rem;">🤖</span>
                            <span class="tag-badge tag-green">v1.0.32 · Build 33</span>
                        </div>
                        <h3 style="margin: 0; color: #fff;">AiPBX Android (APK)</h3>
                        <p style="font-size: 0.88rem; color: #94a3b8; margin: 0; flex: 1;" data-lang="tr">
                            Android 8.0 - 16 arası tüm telefon ve tabletlerle uyumlu, imzalanmış resmi APK paketi.
                        </p>
                        <p style="font-size: 0.88rem; color: #94a3b8; margin: 0; flex: 1;" data-lang="en">
                            Official signed APK package, compatible with all Android smartphones and tablets (Android 8.0 to 16).
                        </p>
                        <p style="font-size: 0.88rem; color: #94a3b8; margin: 0; flex: 1;" data-lang="de">
                            Offiziell signiertes APK-Paket, kompatibel mit Android 8.0 bis 16 auf Smartphones und Tablets.
                        </p>
                        <div style="font-size: 0.8rem; color: #cbd5e1; font-family: monospace;">
                            Size: 2.8 MB · Package: com.mhrgl.AiPBX
                        </div>
                        <a href="downloads/aipbx-latest.apk" class="btn-primary" style="justify-content: center; background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                            <span data-lang="tr">⬇️ Doğrudan APK İndir</span>
                            <span data-lang="en">⬇️ Download Signed APK</span>
                            <span data-lang="de">⬇️ Signiertes APK Laden</span>
                        </a>
                    </div>

                    <div style="background: #0d1524; border: 1px solid var(--border); border-radius: 12px; padding: 24px; display: flex; flex-direction: column; gap: 14px;">
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <span style="font-size: 2rem;">🍏</span>
                            <span class="tag-badge tag-blue">iOS 16.0+</span>
                        </div>
                        <h3 style="margin: 0; color: #fff;">AiPBX iOS (IPA)</h3>
                        <p style="font-size: 0.88rem; color: #94a3b8; margin: 0; flex: 1;" data-lang="tr">
                            Gerçek iPhone ve iPad cihazlar için derlenmiş IPA paketi (TrollStore, AltStore, Sideloadly veya MDM).
                        </p>
                        <p style="font-size: 0.88rem; color: #94a3b8; margin: 0; flex: 1;" data-lang="en">
                            Compiled IPA package for physical iPhone and iPad devices (TrollStore, AltStore, Sideloadly, MDM).
                        </p>
                        <p style="font-size: 0.88rem; color: #94a3b8; margin: 0; flex: 1;" data-lang="de">
                            Kompiliertes IPA-Paket für physische iPhones und iPads (TrollStore, AltStore, Sideloadly oder MDM).
                        </p>
                        <div style="font-size: 0.8rem; color: #cbd5e1; font-family: monospace;">
                            Size: 500 KB · Bundle: com.mhrgl.AiPBX
                        </div>
                        <a href="downloads/AiPBX-unsigned.ipa" class="btn-primary" style="justify-content: center;">
                            <span data-lang="tr">⬇️ iOS IPA Paketi İndir</span>
                            <span data-lang="en">⬇️ Download iOS IPA</span>
                            <span data-lang="de">⬇️ iOS IPA Herunterladen</span>
                        </a>
                    </div>

                    <div style="background: #0d1524; border: 1px solid var(--border); border-radius: 12px; padding: 24px; display: flex; flex-direction: column; gap: 14px;">
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <span style="font-size: 2rem;">💻</span>
                            <span class="tag-badge tag-purple">Xcode Simulator</span>
                        </div>
                        <h3 style="margin: 0; color: #fff;">Xcode Simulator (.app)</h3>
                        <p style="font-size: 0.88rem; color: #94a3b8; margin: 0; flex: 1;" data-lang="tr">
                            macOS M1/M2/M3 işlemcili Mac'lerde Xcode Simulator'e sürükle-bırak yapılarak çalıştırılan simülatör paketi.
                        </p>
                        <p style="font-size: 0.88rem; color: #94a3b8; margin: 0; flex: 1;" data-lang="en">
                            Simulator build for Apple Silicon Macs; drag and drop directly onto Xcode Simulator window.
                        </p>
                        <p style="font-size: 0.88rem; color: #94a3b8; margin: 0; flex: 1;" data-lang="de">
                            Simulator-Build für Apple Silicon Macs; einfach per Drag-and-Drop in den Xcode-Simulator ziehen.
                        </p>
                        <div style="font-size: 0.8rem; color: #cbd5e1; font-family: monospace;">
                            Size: 1.3 MB · Format: .zip
                        </div>
                        <a href="downloads/AiPBX-Simulator.zip" class="btn-secondary" style="justify-content: center;">
                            <span data-lang="tr">⬇️ Simulator .zip İndir</span>
                            <span data-lang="en">⬇️ Download Simulator ZIP</span>
                            <span data-lang="de">⬇️ Simulator ZIP Laden</span>
                        </a>
                    </div>
                </div>
            </section>

            <section class="docs-section" id="screens">
                <h2>
                    <span data-lang="tr">2. İnteraktif Ekran İncelemesi</span>
                    <span data-lang="en">2. Interactive Mobile Screen Switcher</span>
                    <span data-lang="de">2. Interaktive App-Vorschau</span>
                </h2>

                <div class="phone-showcase-container" style="background: #090e17; border: 1px solid var(--border); border-radius: 16px; padding: 30px; margin: 24px 0;">
                    <div class="screen-tabs-nav" style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 24px;">
                        <button class="screen-tab-btn active" onclick="switchScreen(0)">
                            <span data-lang="tr">📞 Tuş Takımı</span><span data-lang="en">📞 HD Dialer</span><span data-lang="de">📞 Wähltastatur</span>
                        </button>
                        <button class="screen-tab-btn" onclick="switchScreen(1)">
                            <span data-lang="tr">💬 Grup Sohbeti</span><span data-lang="en">💬 Group Chat</span><span data-lang="de">💬 Gruppen-Chat</span>
                        </button>
                        <button class="screen-tab-btn" onclick="switchScreen(2)">
                            <span data-lang="tr">👥 Kurumsal Rehber</span><span data-lang="en">👥 Directory</span><span data-lang="de">👥 Verzeichnis</span>
                        </button>
                        <button class="screen-tab-btn" onclick="switchScreen(3)">
                            <span data-lang="tr">📊 Çağrı Geçmişi</span><span data-lang="en">📊 Call History</span><span data-lang="de">📊 Anrufhistorie</span>
                        </button>
                        <button class="screen-tab-btn" onclick="switchScreen(4)">
                            <span data-lang="tr">⚙️ Giriş &amp; Keşif</span><span data-lang="en">⚙️ Zero-Config Login</span><span data-lang="de">⚙️ Zero-Config Login</span>
                        </button>
                    </div>

                    <div style="display: grid; grid-template-columns: minmax(260px, 320px) 1fr; gap: 36px; align-items: center;">
                        <div style="text-align: center;">
                            <img id="activePhoneImg" src="img/app_dialer.jpg" alt="AiPBX WebRTC Softphone Ekran Görüntüsü — Android ve iOS HD Dialer" style="width: 100%; max-width: 290px; border-radius: 24px; border: 4px solid #1e293b; box-shadow: 0 20px 40px rgba(0,0,0,0.8); transition: opacity 0.2s ease;">
                        </div>

                        <div>
                            <span class="screen-meta-badge" id="activeScreenBadge" style="display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: 700; margin-bottom: 12px; background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #34d399;">
                                🟢 Live PBX Integration
                            </span>
                            <h3 id="activeScreenTitle" style="font-size: 1.5rem; color: #fff; margin-bottom: 12px;">WebRTC HD Dialer</h3>
                            <p id="activeScreenDesc" style="color: #94a3b8; font-size: 0.95rem; line-height: 1.6; margin-bottom: 18px;">
                                Extension 19000 registered live. Crystal-clear internal and external calls powered by low-latency DTLS-SRTP encrypted Opus WebRTC audio engine.
                            </p>
                            <ul class="screen-meta-bullets" id="activeScreenBullets" style="list-style: none; padding: 0; display: flex; flex-direction: column; gap: 10px;">
                                <li class="screen-meta-bullet" style="display: flex; gap: 10px; font-size: 0.9rem; color: #cbd5e1;">
                                    <span style="color: #34d399;">✔</span>
                                    <span><strong>Speed Dial &amp; DTMF:</strong> Instant in-call touch-tone dialing.</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>

            <section class="docs-section" id="android">
                <h2>
                    <span data-lang="tr">3. Android İstemci Mimarisi (Kotlin Native)</span>
                    <span data-lang="en">3. Android Client Architecture (Kotlin Native)</span>
                    <span data-lang="de">3. Android-Client-Architektur (Kotlin Native)</span>
                </h2>
                <div data-lang="tr">
                    <ul>
                        <li><strong>Saf Kotlin &amp; Jetpack:</strong> %100 yerel Android mimari bileşenleri (Coroutines, ViewModel, Room, LiveData).</li>
                        <li><strong>Başsız (Headless) WebRTC Motoru:</strong> Android donanım hızlandırmalı AudioTrack ve Opus kodek entegrasyonu.</li>
                        <li><strong>FCM Yüksek Öncelikli Push:</strong> Telefon kilitliyken veya derin uyku (Doze Mode) modundayken pili tüketmeden anında uyandırma.</li>
                        <li><strong>Kilit Ekranında Arama:</strong> <code>FullScreenIntent</code> ve <code>CallStyle</code> ile gelen aramada GSM çağrısı gibi tam ekran bildirim.</li>
                    </ul>
                </div>
                <div data-lang="en">
                    <ul>
                        <li><strong>Pure Kotlin &amp; Jetpack:</strong> 100% native Android architecture components (Coroutines, ViewModel, Room, LiveData).</li>
                        <li><strong>Headless WebRTC Engine:</strong> Direct hardware-accelerated AudioTrack and Opus codec pipeline.</li>
                        <li><strong>FCM High-Priority Data Push:</strong> Wakes the device instantly from Doze Mode without battery drain.</li>
                        <li><strong>Lock Screen Call Notification:</strong> <code>FullScreenIntent</code> and <code>CallStyle</code> trigger a native full-screen incoming call UI.</li>
                    </ul>
                </div>
                <div data-lang="de">
                    <ul>
                        <li><strong>Reines Kotlin &amp; Jetpack:</strong> 100 % native Android-Architekturkomponenten (Coroutines, ViewModel, Room, LiveData).</li>
                        <li><strong>Headless WebRTC-Engine:</strong> Hardwarebeschleunigtes AudioTrack und Opus-Codec-Verarbeitung.</li>
                        <li><strong>FCM High-Priority Push:</strong> Weckt das Smartphone zuverlässig aus dem Doze-Modus ohne Akkubelastung.</li>
                        <li><strong>Sperrbildschirm-Anruf:</strong> <code>FullScreenIntent</code> und <code>CallStyle</code> aktivieren die native Vollbild-Anrufoberfläche.</li>
                    </ul>
                </div>
            </section>

            <section class="docs-section" id="ios">
                <h2>
                    <span data-lang="tr">4. iOS İstemci Mimarisi (Swift &amp; CallKit)</span>
                    <span data-lang="en">4. iOS Client Architecture (Swift &amp; CallKit)</span>
                    <span data-lang="de">4. iOS-Client-Architektur (Swift &amp; CallKit)</span>
                </h2>
                <div data-lang="tr">
                    <ul>
                        <li><strong>Apple CallKit:</strong> Gelen çağrılar Apple'ın yerel arama ekranını açar ve sistem çağrı geçmişine kaydedilir.</li>
                        <li><strong>PushKit VoIP Push:</strong> Standart bildirimler yerine Apple'ın kurumsal VoIP uyandırma kanalı kullanılır.</li>
                        <li><strong>SwiftUI &amp; Combine:</strong> Akıcı arayüz animasyonları ve reaktif veri akışı.</li>
                    </ul>
                </div>
                <div data-lang="en">
                    <ul>
                        <li><strong>Apple CallKit Integration:</strong> Incoming calls display Apple's native lock screen interface and integrate into the system Recents log.</li>
                        <li><strong>PushKit VoIP Push:</strong> Uses Apple's dedicated VoIP push channel for zero-delay background wake-up.</li>
                        <li><strong>SwiftUI &amp; Combine:</strong> Smooth animations, Dark Mode support, and reactive state management.</li>
                    </ul>
                </div>
                <div data-lang="de">
                    <ul>
                        <li><strong>Apple CallKit Integration:</strong> Eingehende Anrufe nutzen die native iOS-Anrufoberfläche und erscheinen in der System-Historie.</li>
                        <li><strong>PushKit VoIP-Push:</strong> Spezieller Apple VoIP-Kanal für verzögerungsfreie Hintergrund-Aufweckung.</li>
                        <li><strong>SwiftUI &amp; Combine:</strong> Flüssige Animationen, Dark-Mode-Unterstützung und reaktive Datenflüsse.</li>
                    </ul>
                </div>
            </section>

            <div class="docs-pagination">
                <a href="features.html" class="pagination-btn">
                    <span class="pagination-label">
                        <span data-lang="tr">← Önceki</span>
                        <span data-lang="en">← Previous</span>
                        <span data-lang="de">← Zurück</span>
                    </span>
                    <span class="pagination-title">
                        <span data-lang="tr">Santral Özellikleri</span>
                        <span data-lang="en">PBX Features</span>
                        <span data-lang="de">PBX-Funktionen</span>
                    </span>
                </a>
                <a href="installation.html" class="pagination-btn next">
                    <span class="pagination-label">
                        <span data-lang="tr">Sonraki →</span>
                        <span data-lang="en">Next →</span>
                        <span data-lang="de">Weiter →</span>
                    </span>
                    <span class="pagination-title">
                        <span data-lang="tr">Kurulum Rehberi</span>
                        <span data-lang="en">Installation Guide</span>
                        <span data-lang="de">Installationsanleitung</span>
                    </span>
                </a>
            </div>
    
        </main>

        <div class="docs-toc">
            <div class="toc-title">
                <span data-lang="tr">Bu Sayfada</span><span data-lang="en">On This Page</span><span data-lang="de">Auf dieser Seite</span>
            </div>
            <ul class="toc-list">
                <li><a href="#downloads">1. Direct Downloads</a></li>
                <li><a href="#screens">2. Screen Switcher</a></li>
                <li><a href="#android">3. Android Architecture</a></li>
                <li><a href="#ios">4. iOS Architecture</a></li>
            </ul>
        </div>
    </div>

    <!-- Universal Footer -->

    <!-- Universal Footer -->
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
