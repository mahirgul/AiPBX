<?php
/**
 * AiPBX.bid — Comprehensive Telephony Feature Suite
 * Deep technical documentation of all 20 modules, IVR, ALPN, Teams SBC, and Star Codes.
 */
$page = 'features';
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
                    <li><a href="/"><span data-lang="tr">🏠 Genel Bakış (Home)</span><span data-lang="en">🏠 Overview (Home)</span><span data-lang="de">🏠 Übersicht (Home)</span></a></li>
                    <li><a href="/features" class="active"><span data-lang="tr">⚡ 20 Santral Modülü</span><span data-lang="en">⚡ 20 PBX Modules</span><span data-lang="de">⚡ 20 PBX-Module</span></a></li>
                    <li><a href="/tables"><span data-lang="tr">📊 Karşılaştırma &amp; Tablolar</span><span data-lang="en">📊 Tables &amp; Specs</span><span data-lang="de">📊 Tabellen &amp; Matrix</span></a></li>
                    <li><a href="/architecture"><span data-lang="tr">🏗️ Sistem Mimarisi &amp; ALPN</span><span data-lang="en">🏗️ Architecture &amp; ALPN</span><span data-lang="de">🏗️ Architektur &amp; ALPN</span></a></li>
                </ul>
            </div>

            <div class="sidebar-group">
                <div class="sidebar-title">
                    <span data-lang="tr">20 Temel Modül Listesi</span>
                    <span data-lang="en">20 Flagship Modules</span>
                    <span data-lang="de">20 Kernmodule</span>
                </div>
                <ul class="sidebar-menu">
                    <li><a href="#extensions">1. Dahili Hat Yönetimi</a></li>
                    <li><a href="#ivr">2. Çok Seviyeli IVR</a></li>
                    <li><a href="#routing">3. Çağrı Yönlendirme (Routing)</a></li>
                    <li><a href="#webrtc">4. WebRTC Softphone</a></li>
                    <li><a href="#fax">5. Dijital Faks (T.38)</a></li>
                    <li><a href="#feature-codes">6. Yıldız Kodları (* Kodları)</a></li>
                    <li><a href="#passkey">7. Biyometrik Passkey (FIDO2)</a></li>
                    <li><a href="#oauth">8. Google OAuth 2.0 Giriş</a></li>
                    <li><a href="#go-chat">9. Go WebSocket Sohbet</a></li>
                    <li><a href="#call-barring">10. Arama Yetkilendirme</a></li>
                    <li><a href="#alpn">11. Port 443 ALPN Çoklama</a></li>
                    <li><a href="#in-dialer-chat">12. Dahili Dialer İçi Sohbet</a></li>
                    <li><a href="#call-journey">13. Görsel CDR LinkedID Çizelgesi</a></li>
                    <li><a href="#boss-secretary">14. Sekreter-Yönetici &amp; VIP</a></li>
                    <li><a href="#confbridge">15. Sesli Konferans (*8000)</a></li>
                    <li><a href="#voicemail">16. Sesli Posta MP3 (*97)</a></li>
                    <li><a href="#spy-whisper">17. Süpervizör Dinleme (*90)</a></li>
                    <li><a href="#ring-groups">18. Çalma Grupları (Ring Groups)</a></li>
                    <li><a href="#transit-routing">19. Transit Hat Yönlendirme</a></li>
                    <li><a href="#msteams">20. MS Teams Direct Routing SBC</a></li>
                </ul>
            </div>

            <div class="sidebar-group">
                <div class="sidebar-title">
                    <span data-lang="tr">Uygulamalar &amp; Dokümanlar</span>
                    <span data-lang="en">Apps &amp; Guides</span>
                    <span data-lang="de">Apps &amp; Anleitungen</span>
                </div>
                <ul class="sidebar-menu">
                    <li><a href="/mobile-apps"><span data-lang="tr">📱 Mobil Softphone (Android/iOS)</span><span data-lang="en">📱 Mobile Softphone</span><span data-lang="de">📱 Mobil-Softphone</span></a></li>
                    <li><a href="/callcenter"><span data-lang="tr">🎧 Çağrı Merkezi &amp; Mola (*22)</span><span data-lang="en">🎧 Call Center &amp; Breaks</span><span data-lang="de">🎧 Callcenter &amp; Pausen</span></a></li>
                    <li><a href="/installation"><span data-lang="tr">⚙️ Kurulum &amp; Yapılandırma</span><span data-lang="en">⚙️ Installation Guide</span><span data-lang="de">⚙️ Installationsanleitung</span></a></li>
                    <li><a href="/security"><span data-lang="tr">🛡️ Güvenlik &amp; CCIS Gateway</span><span data-lang="en">🛡️ Security &amp; CCIS</span><span data-lang="de">🛡️ Sicherheit &amp; CCIS</span></a></li>
                    <li><a href="/msteams"><span data-lang="tr">🔷 Microsoft Teams Entegrasyonu</span><span data-lang="en">🔷 Microsoft Teams Integration</span><span data-lang="de">🔷 Microsoft Teams</span></a></li>
                    <li><a href="/api-docs"><span data-lang="tr">💻 REST API &amp; WebSocket</span><span data-lang="en">💻 REST API &amp; WebSocket</span><span data-lang="de">💻 REST-API &amp; WebSocket</span></a></li>
                </ul>
            </div>
        </aside>

        <!-- Main Documentation Content -->
        <main class="docs-content">
            <div class="docs-header">
                <div class="breadcrumb">
                    <a href="/">AiPBX</a>
                    <span>/</span>
                    <span data-lang="tr">Özellikler</span>
                    <span data-lang="en">Features</span>
                    <span data-lang="de">Funktionen</span>
                </div>

                <h1 class="docs-title">
                    <span data-lang="tr">Santral Özellikleri &amp; Çekirdek Modüller</span>
                    <span data-lang="en">Enterprise PBX Features &amp; Core Modules</span>
                    <span data-lang="de">Enterprise PBX-Funktionen &amp; Kernmodule</span>
                </h1>

                <p class="lead" data-lang="tr">
                    AiPBX; kısıtlayıcı güvenlik duvarlarını aşan Port 443 ALPN stream çoklama, biyometrik Passkey kimlik doğrulaması, Asterisk 22 çekirdeği, dahili dialer içi anlık mesajlaşma ve Microsoft Teams Direct Routing desteğiyle modern işletmelerin tüm telekomünikasyon ihtiyaçlarını tek çatı altında çözer.
                </p>
                <p class="lead" data-lang="en">
                    AiPBX unites Port 443 ALPN stream multiplexing, FIDO2/WebAuthn biometric passkeys, Asterisk 22 core, integrated in-dialer chat, and turnkey Microsoft Teams Direct Routing into a sovereign, high-concurrency communications platform.
                </p>
                <p class="lead" data-lang="de">
                    AiPBX bündelt Port 443 ALPN-Multiplexing, biometrische Passkeys, Asterisk 22, In-Dialer Chat und Microsoft Teams Direct Routing in einer souveränen, hochskalierbaren Enterprise-Telefonanlage.
                </p>
            </div>

            <section class="docs-section" id="extensions">
                <h2>
                    <span data-lang="tr">1. Dahili (Extension) Yönetimi</span>
                    <span data-lang="en">1. Extension Management</span>
                    <span data-lang="de">1. Nebenstellen-Verwaltung</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        AiPBX dahili sistemi, Asterisk 22 PJSIP yığını üzerine kuruludur. Kullanıcılar ister masalarındaki donanımsal IP telefondan, ister tarayıcı üzerinden, ister mobil cihazlarından aynı dahili numarasıyla görüşme yapabilirler:
                    </p>
                </div>
                <div data-lang="en">
                    <p>
                        AiPBX extensions are powered by Asterisk 22 PJSIP. Users can place and receive calls using their single extension number across physical desk phones, web browsers, and native mobile apps:
                    </p>
                </div>
                <div data-lang="de">
                    <p>
                        AiPBX-Nebenstellen basieren auf Asterisk 22 PJSIP. Benutzer können unter ihrer einheitlichen Durchwahl über Tischtelefone, Web-Browser und native Mobil-Apps telefonieren:
                    </p>
                </div>

                <div class="docs-table-wrapper">
                    <table class="docs-table">
                        <thead>
                            <tr>
                                <th>
                                    <span data-lang="tr">Dahili Tipi</span>
                                    <span data-lang="en">Extension Type</span>
                                    <span data-lang="de">Nebenstellentyp</span>
                                </th>
                                <th>
                                    <span data-lang="tr">Protokol &amp; Taşıyıcı</span>
                                    <span data-lang="en">Protocol &amp; Transport</span>
                                    <span data-lang="de">Protokoll &amp; Transport</span>
                                </th>
                                <th>
                                    <span data-lang="tr">Kullanım Alanı</span>
                                    <span data-lang="en">Target Devices</span>
                                    <span data-lang="de">Zielgeräte</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Hardware SIP Phone</strong></td>
                                <td>SIP / PJSIP (UDP, TCP, TLS)</td>
                                <td>Yealink, Fanvil, Grandstream, Cisco, Snom, Gigaset DECT.</td>
                            </tr>
                            <tr>
                                <td><strong>WebRTC Webphone</strong></td>
                                <td>WSS (Port 443 ALPN) + DTLS-SRTP</td>
                                <td>
                                    <span data-lang="tr">Eklentisiz Chrome, Edge, Firefox, Safari tarayıcı aramaları.</span>
                                    <span data-lang="en">Zero-plugin browser calling in Chrome, Edge, Firefox, Safari.</span>
                                    <span data-lang="de">Plugin-freies Telefonieren im Browser (Chrome, Edge, Firefox, Safari).</span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Mobile Client</strong></td>
                                <td>WSS / TURNS + FCM / APNs Push</td>
                                <td>AiPBX Android (Kotlin) &amp; iOS (Swift) native apps.</td>
                            </tr>
                            <tr>
                                <td><strong>Digital Fax</strong></td>
                                <td>T.38 / Spooler</td>
                                <td>
                                    <span data-lang="tr">Gelen faksları PDF olarak e-postaya ileten dijital faks dahili.</span>
                                    <span data-lang="en">Digital fax extension routing inbound faxes as PDF to email.</span>
                                    <span data-lang="de">Digitale Fax-Nebenstelle zur Weiterleitung eingehender Faxe als PDF.</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Section 2: Çok Seviyeli IVR -->
            <section class="docs-section" id="ivr">
                <h2>
                    <span data-lang="tr">2. Çok Seviyeli Sesli Yanıt Sistemi (IVR)</span>
                    <span data-lang="en">2. Multi-Level Interactive Voice Response (IVR)</span>
                    <span data-lang="de">2. Mehrstufiges Sprachmenü (IVR)</span>
                </h2>
                <div data-lang="tr">
                    <ul>
                        <li><strong>Sonsuz Derinlikte Alt Menüler:</strong> Departmanlara ve dillere göre kırılan çok katmanlı ağaç yapısı.</li>
                        <li><strong>Rakam Tuşlama Zaman Aşımı (Digit Timeout):</strong> Tuşlama yapılması için tanınan bekleme süresi (1–15 saniye) hassasiyetle ayarlanabilir.</li>
                        <li><strong>Doğrudan Dahili Arama (Direct Dial):</strong> Anons çalarken dahiliyi bilen arayan doğrudan tuşlama yaparak hedefe bağlanabilir.</li>
                        <li><strong>Geçersiz Tuşlama &amp; Zaman Aşımı:</strong> Hatalı tuşlamalarda veya yanıtsızlıkta operatöre veya kuyruğa güvenli yönlendirme.</li>
                        <li><strong>Mesai &amp; Tatil Koşulları:</strong> Çalışma saatleri ve resmi tatiller için özel anonslar ve gece nöbetçi yönlendirmeleri.</li>
                    </ul>
                </div>
                <div data-lang="en">
                    <ul>
                        <li><strong>Unlimited Sub-Menus:</strong> Multi-tiered routing tree for departments, languages, and locations.</li>
                        <li><strong>Digit Timeout Parameter:</strong> Configurable wait duration (1–15 seconds) for customer DTMF input.</li>
                        <li><strong>Direct Extension Dialing:</strong> Callers who know their party's extension can dial it at any time during the prompt.</li>
                        <li><strong>Timeout &amp; Invalid Fallbacks:</strong> Safe routing to operators or voicemails on invalid input or timeout.</li>
                        <li><strong>Business Hours &amp; Holidays:</strong> Automated switching between working hours, lunch breaks, and holidays.</li>
                    </ul>
                </div>
                <div data-lang="de">
                    <ul>
                        <li><strong>Unbegrenzte Untermenüs:</strong> Mehrstufiger Verteilungsbaum nach Abteilungen, Sprachen und Standorten.</li>
                        <li><strong>Ziffern-Timeout-Parameter:</strong> Einstellbare Wartezeit (1–15 Sekunden) für DTMF-Tastatureingaben.</li>
                        <li><strong>Direkte Durchwahl:</strong> Anrufer mit bekannter Durchwahlnummer können diese jederzeit direkt eingeben.</li>
                        <li><strong>Timeout- &amp; Fehleingabe-Fallback:</strong> Zuverlässige Weiterleitung an die Zentrale bei Falscheingabe.</li>
                        <li><strong>Geschäftszeiten &amp; Feiertage:</strong> Automatische Umschaltung zwischen Tag-, Nacht- und Feiertagsmodus.</li>
                    </ul>
                </div>
            </section>

            <!-- Section 3: Gelen & Giden Arama Yönlendirme -->
            <section class="docs-section" id="routing">
                <h2>
                    <span data-lang="tr">3. Gelen (DID) ve Giden Arama Yönlendirme</span>
                    <span data-lang="en">3. Inbound (DID) &amp; Outbound Call Routing</span>
                    <span data-lang="de">3. Eingehendes (DID) &amp; Ausgehendes Routing</span>
                </h2>
                <div class="docs-table-wrapper">
                    <table class="docs-table">
                        <thead>
                            <tr>
                                <th>
                                    <span data-lang="tr">Modül</span>
                                    <span data-lang="en">Routing Tier</span>
                                    <span data-lang="de">Routing-Ebene</span>
                                </th>
                                <th>
                                    <span data-lang="tr">Yetenekler &amp; Filtreler</span>
                                    <span data-lang="en">Capabilities &amp; Logic</span>
                                    <span data-lang="de">Funktionsumfang</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Inbound DID Routes</strong></td>
                                <td>
                                    <span data-lang="tr">Türk Telekom, Netgsm vb. dış hat numaralarını Regex ile eşler; IVR, kuyruk veya doğrudan dahiliye yönlendirir.</span>
                                    <span data-lang="en">Regex pattern matching for external telecom provider DIDs; routes to IVRs, queues, or ring groups.</span>
                                    <span data-lang="de">Regex-Musterabgleich für Telefonnummern (DIDs); leitet an IVR, Warteschlangen oder Gruppen weiter.</span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Outbound Routes</strong></td>
                                <td>
                                    <span data-lang="tr">Dahili ve departman bazlı giden çağrı kuralları (Şehiriçi, GSM, Uluslararası yetki ayrımı).</span>
                                    <span data-lang="en">Department-level outbound dial rules (Local, Mobile, International permissions).</span>
                                    <span data-lang="de">Abteilungsbezogene Wahlregeln (Ortsnetz, Mobilfunk, Auslandssperren).</span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Trunk Failover</strong></td>
                                <td>
                                    <span data-lang="tr">Birincil hatta arıza veya kesinti olduğunda otomatik olarak 2. ve 3. yedek trunk'a geçiş yapar.</span>
                                    <span data-lang="en">Automatic failover to secondary and tertiary SIP trunks if primary trunk is unavailable.</span>
                                    <span data-lang="de">Automatischer Wechsel zu sekundären SIP-Trunks bei Leitungsstörungen.</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Section 4: WebRTC Softphone -->
            <section class="docs-section" id="webrtc">
                <h2>
                    <span data-lang="tr">4. WebRTC Tarayıcı Telefonu (Softphone)</span>
                    <span data-lang="en">4. WebRTC In-Browser Softphone</span>
                    <span data-lang="de">4. WebRTC Browser-Softphone</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        AiPBX web yönetim paneline gömülü WebRTC telefonu ile ek hiçbir uygulama yüklemeden doğrudan görüşme yapabilirsiniz:
                    </p>
                    <ul>
                        <li><strong>Opus HD Ses:</strong> Bant genişliği dalgalanmalarında bile yüksek kaliteli insan sesi.</li>
                        <li><strong>DTMF Tuş Takımı:</strong> Sesli yanıtlarda anında tuşlama.</li>
                        <li><strong>Gelişmiş Kontroller:</strong> Bekletme (Hold), Sessize alma (Mute), Aktarma (Blind &amp; Attended Transfer).</li>
                        <li><strong>Ses Donanımı Seçimi:</strong> Kulaklık ve mikrofon aygıtlarını tarayıcı içinden değiştirme.</li>
                    </ul>
                </div>
                <div data-lang="en">
                    <p>
                        A native WebRTC softphone is integrated directly into the web management portal:
                    </p>
                    <ul>
                        <li><strong>Opus HD Audio:</strong> Crystal-clear voice quality adapting dynamically to network conditions.</li>
                        <li><strong>DTMF Keypad:</strong> Instant in-call touch-tone dialing.</li>
                        <li><strong>Call Controls:</strong> Hold, Mute, Blind Transfer, and Attended (Warm) Transfer.</li>
                        <li><strong>Audio Device Selector:</strong> Select headset, microphone, and speakers dynamically.</li>
                    </ul>
                </div>
                <div data-lang="de">
                    <p>
                        Ein voll ausgestattetes WebRTC-Softphone ist direkt im Web-Management-Portal integriert:
                    </p>
                    <ul>
                        <li><strong>Opus HD Audio:</strong> Erstklassige Sprachqualität mit automatischer Bitraten-Anpassung.</li>
                        <li><strong>DTMF-Tastatur:</strong> Sofortige Tonwahl für Sprachmenüs.</li>
                        <li><strong>Gesprächssteuerung:</strong> Halten, Stummschalten, Weiterleiten (Direkt &amp; mit Rücksprache).</li>
                        <li><strong>Audiogeräteauswahl:</strong> Dynamische Auswahl von Headset, Mikrofon und Lautsprecher.</li>
                    </ul>
                </div>
            </section>

            <!-- Section 5: Dijital Faks -->
            <section class="docs-section" id="fax">
                <h2>
                    <span data-lang="tr">5. Dijital Faks Sunucusu (FoIP / T.38)</span>
                    <span data-lang="en">5. Digital Fax Server (FoIP / T.38)</span>
                    <span data-lang="de">5. Digitaler Faxserver (FoIP / T.38)</span>
                </h2>
                <div data-lang="tr">
                    <ul>
                        <li><strong>Gelen Faks (Fax-to-Email):</strong> Gelen fakslar otomatik olarak PDF'e dönüştürülür ve e-posta adreslerine iletilir.</li>
                        <li><strong>Giden Faks (Web-to-Fax):</strong> Web panelinden PDF yüklenip numara girilerek otomatik gönderim sağlanır.</li>
                    </ul>
                </div>
                <div data-lang="en">
                    <ul>
                        <li><strong>Inbound Fax (Fax-to-Email):</strong> Incoming T.38 faxes are digitized into PDF documents and delivered via email.</li>
                        <li><strong>Outbound Fax (Web-to-Fax):</strong> Upload PDF documents via web dashboard; Asterisk spools and transmits with delivery receipts.</li>
                    </ul>
                </div>
                <div data-lang="de">
                    <ul>
                        <li><strong>Eingehendes Fax (Fax-to-Email):</strong> Eingehende T.38-Faxe werden als PDF digitalisiert und per E-Mail zugestellt.</li>
                        <li><strong>Ausgehendes Fax (Web-to-Fax):</strong> PDF-Upload im Web-Dashboard zum automatischen Faxversand mit Sendeprotokoll.</li>
                    </ul>
                </div>
            </section>

            <!-- Section 6: Yıldız Kodlar Tablosu -->
            <section class="docs-section" id="feature-codes">
                <h2>
                    <span data-lang="tr">6. Santral Özellik Kodları (Feature Codes / Yıldız Kodlar)</span>
                    <span data-lang="en">6. Feature Codes (Star Codes Directory)</span>
                    <span data-lang="de">6. Leistungsmerkmale (Stern-Codes)</span>
                </h2>
                <div class="docs-table-wrapper">
                    <table class="docs-table">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>
                                    <span data-lang="tr">Özellik Adı</span>
                                    <span data-lang="en">Feature Name</span>
                                    <span data-lang="de">Funktion</span>
                                </th>
                                <th>
                                    <span data-lang="tr">Roller</span>
                                    <span data-lang="en">Allowed Roles</span>
                                    <span data-lang="de">Erlaubte Rollen</span>
                                </th>
                                <th>
                                    <span data-lang="tr">Açıklama</span>
                                    <span data-lang="en">Description</span>
                                    <span data-lang="de">Beschreibung</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="code-pill">*78</span></td>
                                <td>DND Enable</td>
                                <td>All / Tümü</td>
                                <td>
                                    <span data-lang="tr">Rahatsız Etmeyin modunu açar.</span>
                                    <span data-lang="en">Enables Do Not Disturb.</span>
                                    <span data-lang="de">Aktiviert 'Bitte nicht stören'.</span>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="code-pill">*79</span></td>
                                <td>DND Disable</td>
                                <td>All / Tümü</td>
                                <td>
                                    <span data-lang="tr">Rahatsız Etmeyin modunu kapatır.</span>
                                    <span data-lang="en">Disables Do Not Disturb.</span>
                                    <span data-lang="de">Deaktiviert 'Bitte nicht stören'.</span>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="code-pill">*72&lt;number&gt;</span></td>
                                <td>Call Forward</td>
                                <td>All / Tümü</td>
                                <td>
                                    <span data-lang="tr">Gelen aramaları girilen numaraya yönlendirir.</span>
                                    <span data-lang="en">Forwards calls to the dialed number.</span>
                                    <span data-lang="de">Leitet Anrufe an die gewählte Nummer weiter.</span>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="code-pill">*73</span></td>
                                <td>Cancel Forward</td>
                                <td>All / Tümü</td>
                                <td>
                                    <span data-lang="tr">Aktif çağrı yönlendirmesini iptal eder.</span>
                                    <span data-lang="en">Cancels active call forwarding.</span>
                                    <span data-lang="de">Hebt aktive Anrufweiterschaltung auf.</span>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="code-pill">*8</span></td>
                                <td>Group Pickup</td>
                                <td>All / Tümü</td>
                                <td>
                                    <span data-lang="tr">Grupta çalan çağrıyı kendi telefonuna çeker.</span>
                                    <span data-lang="en">Picks up a ringing call within your pickup group.</span>
                                    <span data-lang="de">Nimmt einen Anruf innerhalb der Anrufgruppe heran.</span>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="code-pill">*21&lt;ext&gt;</span></td>
                                <td>Directed Pickup</td>
                                <td>All / Tümü</td>
                                <td>
                                    <span data-lang="tr">Belirtilen dahilide çalan çağrıyı çeker.</span>
                                    <span data-lang="en">Picks up a ringing call on a specific extension.</span>
                                    <span data-lang="de">Nimmt den Anruf einer bestimmten Nebenstelle heran.</span>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="code-pill">*90</span></td>
                                <td>Call Spy</td>
                                <td>admin, cc_manager</td>
                                <td>
                                    <span data-lang="tr">Aktif görüşmeyi süpervizör olarak dinler.</span>
                                    <span data-lang="en">Enters ChanSpy audio monitoring mode.</span>
                                    <span data-lang="de">Schaltet Supervisor-Mithören auf ein Gespräch.</span>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="code-pill">*81&lt;qid&gt;</span></td>
                                <td>Queue Login</td>
                                <td>admin, cc_manager, cc_agent</td>
                                <td>
                                    <span data-lang="tr">Dinamik temsilci kuyruğa giriş yapar.</span>
                                    <span data-lang="en">Logs dynamic agent into queue.</span>
                                    <span data-lang="de">Meldet dynamischen Agenten an Warteschlange an.</span>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="code-pill">*80&lt;qid&gt;</span></td>
                                <td>Queue Logout</td>
                                <td>admin, cc_manager, cc_agent</td>
                                <td>
                                    <span data-lang="tr">Dinamik temsilci kuyruktan çıkar.</span>
                                    <span data-lang="en">Logs dynamic agent out of queue.</span>
                                    <span data-lang="de">Meldet dynamischen Agenten von Warteschlange ab.</span>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="code-pill">*22&lt;reason&gt;</span></td>
                                <td>Queue Pause</td>
                                <td>admin, cc_manager, cc_agent, user</td>
                                <td>
                                    <span data-lang="tr">Mola durumuna geçer (Çift bip sesi).</span>
                                    <span data-lang="en">Pauses agent across all queues (Double beep).</span>
                                    <span data-lang="de">Pausiert Agenten in allen Warteschlangen (Doppel-Piep).</span>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="code-pill">*23</span></td>
                                <td>Queue Unpause</td>
                                <td>admin, cc_manager, cc_agent, user</td>
                                <td>
                                    <span data-lang="tr">Moladan çıkar, çağrı almaya açılır (Tek bip).</span>
                                    <span data-lang="en">Unpauses agent, ready for calls (Single beep).</span>
                                    <span data-lang="de">Beendet Pause, Agent wieder anrufbereit (Einzel-Piep).</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Section 7: Biyometrik Passkey (WebAuthn / FIDO2) -->
            <section class="docs-section" id="passkey">
                <h2>
                    <span data-lang="tr">7. Biyometrik Passkey (WebAuthn / FIDO2) &amp; 2FA</span>
                    <span data-lang="en">7. Biometric Passkey (WebAuthn / FIDO2) &amp; 2FA</span>
                    <span data-lang="de">7. Biometrische Passkeys (WebAuthn / FIDO2) &amp; 2FA</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        AiPBX v1.0.34, kurumsal santral yönetim paneline <strong>FIDO2 / WebAuthn</strong> standardında şifresiz biyometrik oturum açma yeteneği kazandırmıştır. Bu sayede klasik şifre çalınması, kaba kuvvet (brute-force) veya oltalama (phishing) saldırıları matematiksel olarak engellenir:
                    </p>
                    <ul>
                        <li><strong>Platform Doğrulayıcılar:</strong> Apple Touch ID / Face ID, Windows Hello parmak izi/yüz tanıma, Android Biyometrik Kilit.</li>
                        <li><strong>Dolaşabilir Donanım Anahtarları:</strong> YubiKey, Google Titan ve FIDO2 sertifikalı USB/NFC güvenlik anahtarları.</li>
                        <li><strong>TOTP Çift Kademeli Savunma:</strong> Google Authenticator, Microsoft Authenticator ve 1Password ile 6 haneli zaman damgalı tek kullanımlık kodlar.</li>
                    </ul>
                </div>
                <div data-lang="en">
                    <p>
                        AiPBX v1.0.34 introduces phishing-resistant, passwordless authentication governed by the <strong>FIDO2 / WebAuthn</strong> W3C standard. Credential stuffing and brute-force attacks are rendered cryptographically impossible:
                    </p>
                    <ul>
                        <li><strong>Platform Authenticators:</strong> Apple Touch ID / Face ID, Windows Hello biometric sensor, Android Fingerprint/Face unlock.</li>
                        <li><strong>Roaming Security Keys:</strong> YubiKey, Google Titan, and standard FIDO2 USB/NFC hardware tokens.</li>
                        <li><strong>TOTP Two-Factor Backup:</strong> Time-based one-time password fallback via Google Authenticator, Microsoft Authenticator, or Bitwarden.</li>
                    </ul>
                </div>
                <div data-lang="de">
                    <p>
                        AiPBX v1.0.34 bietet passwortlose, phishing-resistente Authentifizierung nach dem <strong>FIDO2 / WebAuthn</strong>-Standard für das Webportal und Mobilgeräte:
                    </p>
                    <ul>
                        <li><strong>Plattform-Authentifikatoren:</strong> Apple Touch ID / Face ID, Windows Hello und Android-Biometrie.</li>
                        <li><strong>Hardware-Sicherheitsschlüssel:</strong> YubiKey, Google Titan und standardisierte FIDO2 USB/NFC-Tokens.</li>
                        <li><strong>TOTP Zwei-Faktor-Absicherung:</strong> Zeitbasierte 6-stellige Einmalcodes über Authenticator-Apps.</li>
                    </ul>
                </div>
            </section>

            <!-- Section 8: Google OAuth 2.0 -->
            <section class="docs-section" id="oauth">
                <h2>
                    <span data-lang="tr">8. Google OAuth 2.0 Tek Tıkla Kurumsal Giriş</span>
                    <span data-lang="en">8. Google OAuth 2.0 Enterprise Single Sign-On</span>
                    <span data-lang="de">8. Google OAuth 2.0 Enterprise Single Sign-On</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        Google Workspace kullanan kurumlar için personellerin tek tek şifre ezberlemesine gerek kalmaz. Dahili üzerinde tanımlı kurumsal e-posta adresi (@sirket.com) Google hesabıyla eşleştiği anda Web Portal, Android ve iOS softphone istemcilerinde tek tıkla oturum açılabilir.
                    </p>
                </div>
                <div data-lang="en">
                    <p>
                        Organizations utilizing Google Workspace can authenticate seamlessly. Whenever an extension's assigned email address matches an active corporate Google account, users log into the Web Portal and mobile apps with a single click.
                    </p>
                </div>
                <div data-lang="de">
                    <p>
                        Für Unternehmen mit Google Workspace entfällt das Verwalten separater SIP-Passwörter. Stimmt die Nebenstellen-E-Mail mit dem Firmen-Google-Konto überein, genügt ein Klick zum Login im Webportal sowie in Mobil-Apps.
                    </p>
                </div>
            </section>

            <!-- Section 9: Go Chat Engine -->
            <section class="docs-section" id="go-chat">
                <h2>
                    <span data-lang="tr">9. Go Anlık Sohbet &amp; Mesajlaşma Motoru (aipbx-chat)</span>
                    <span data-lang="en">9. Go WebSocket Real-Time Chat Engine (aipbx-chat)</span>
                    <span data-lang="de">9. Go WebSocket Echtzeit-Chat-Engine (aipbx-chat)</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        AiPBX mimarisine entegre edilen bağımsız Go mikroservisi (<code>aipbx-chat</code>), TCP Port 8090 üzerinden yüksek eşzamanlılıklı kurumsal mesajlaşma sunar:
                    </p>
                    <ul>
                        <li><strong>Goroutine Eşzamanlılığı:</strong> Minimum RAM tüketimi ile on binlerce anlık WebSocket bağlantısını yönetir.</li>
                        <li><strong>1'e 1 ve Grup Sohbetleri:</strong> Departman ve proje bazlı 256 kişiye kadar şifreli sohbet odaları.</li>
                        <li><strong>Medya &amp; Ek Dosya Desteği:</strong> Resim sıkıştırma, sesli mesaj kayıtları ve belge paylaşımı.</li>
                        <li><strong>FCM / APNs Entegrasyonu:</strong> Uygulama kapalıyken bile anında mobil uyandırma ve push bildirimi iletimi.</li>
                    </ul>
                </div>
                <div data-lang="en">
                    <p>
                        Engineered in Go, the standalone <code>aipbx-chat</code> daemon runs on TCP Port 8090 delivering high-concurrency instant messaging:
                    </p>
                    <ul>
                        <li><strong>Goroutine Scalability:</strong> Multiplexes tens of thousands of active WebSockets with lightweight memory consumption.</li>
                        <li><strong>Direct &amp; Group Channels:</strong> 1-on-1 private messaging and department group channels up to 256 users.</li>
                        <li><strong>Attachments &amp; Voice Notes:</strong> Automated client-side media compression and document transfers.</li>
                        <li><strong>FCM / APNs Delivery:</strong> Instant push wakeup when recipient apps are backgrounded or killed.</li>
                    </ul>
                </div>
                <div data-lang="de">
                    <p>
                        Der in Go geschriebene <code>aipbx-chat</code>-Dienst auf TCP Port 8090 bietet sicheres, hochskalierbares Messaging im Firmennetzwerk:
                    </p>
                    <ul>
                        <li><strong>Goroutine-Effizienz:</strong> Tausende gleichzeitige WebSocket-Verbindungen bei minimalem Arbeitsspeicher.</li>
                        <li><strong>1-zu-1 &amp; Gruppenräume:</strong> Verschlüsselte Direktnachrichten und Gruppen bis 256 Teilnehmer.</li>
                        <li><strong>Dateiversand &amp; Sprachmemos:</strong> Automatische Medienkomprimierung und Dateianhänge.</li>
                        <li><strong>Push-Integration:</strong> Zuverlässiges Wecken der Mobilgeräte über FCM und Apple APNs.</li>
                    </ul>
                </div>
            </section>

            <!-- Section 10: Call Barring & JSON Table CTA -->
            <section class="docs-section" id="call-barring">
                <h2>
                    <span data-lang="tr">10. Dinamik Arama Yetkilendirme (Call Barring) &amp; Karşılaştırma Matrisi</span>
                    <span data-lang="en">10. Granular Outbound Call Barring &amp; Feature Matrix</span>
                    <span data-lang="de">10. Wählberechtigungsgruppen &amp; Funktionsmatrix</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        AiPBX, santral dahililerini 6 kademeli yetki gruplarına (Dahili, Acil, Şehir İçi, Şehirlerarası, GSM, Uluslararası) ayırarak şirket bütçesini suiistimallere karşı korur. Detaylı teknik özellikleri, ağ port haritasını ve Cisco/Avaya karşılaştırmasını görmek için interaktif JSON tablosunu inceleyin:
                    </p>
                    <p style="margin-top: 14px;">
                        <a href="/tables" class="btn-primary" style="display:inline-flex; align-items:center; gap:8px;">
                            <i class="fa-solid fa-table-list"></i>
                            <span>AiPBX İnteraktif JSON Tablo Sistemi &amp; Matrisi Aç →</span>
                        </a>
                    </p>
                </div>
                <div data-lang="en">
                    <p>
                        AiPBX enforces 6-tier outbound dialing policies to protect telecom budgets. Explore the interactive JSON table system for side-by-side matrices, network port breakdowns, and hardware sizing:
                    </p>
                    <p style="margin-top: 14px;">
                        <a href="/tables" class="btn-primary" style="display:inline-flex; align-items:center; gap:8px;">
                            <i class="fa-solid fa-table-list"></i>
                            <span>Open Full Interactive JSON Table System &amp; Matrix →</span>
                        </a>
                    </p>
                </div>
                <div data-lang="de">
                    <p>
                        AiPBX sichert Ihre Telefonie durch 6 Wählberechtigungsstufen vor Gebührenmissbrauch. Öffnen Sie unser interaktives JSON-Tabellensystem für den vollständigen Vergleich mit klassischen Anlagen:
                    </p>
                    <p style="margin-top: 14px;">
                        <a href="/tables" class="btn-primary" style="display:inline-flex; align-items:center; gap:8px;">
                            <i class="fa-solid fa-table-list"></i>
                            <span>Interaktives JSON-Tabellensystem öffnen →</span>
                        </a>
                    </p>
                </div>
            </section>

            
            <!-- Section 11: Port 443 Nginx L4 Stream & ALPN Multiplexing -->
            <section class="docs-section" id="alpn">
                <h2>
                    <span data-lang="tr">11. Port 443 Nginx L4 Stream &amp; ALPN Protokol Çoklama</span>
                    <span data-lang="en">11. Port 443 Nginx L4 Stream &amp; ALPN Multiplexing</span>
                    <span data-lang="de">11. Port 443 Nginx L4 Stream &amp; ALPN-Multiplexing</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        Kısıtlayıcı kurumsal güvenlik duvarlarında (otel, hastane, havalimanı ve banka ağları) dışa doğru yalnızca TCP 443 (HTTPS) portuna izin verilir; standart SIP 5060/5061 portları engellenir. AiPBX, Nginx L4 stream modülü ve <code>ssl_preread</code> direktifi ile TLS el sıkışmasındaki ALPN (Application-Layer Protocol Negotiation) parametresini deşifre etmeden okur. Tek bir TCP 443 portundan web arayüzünü (Apache :8443), WebRTC WSS sinyalleşmesini (Asterisk :8089) ve SIP TLS trafiğini (PJSIP :5061) sıfır gecikmeyle dinamik olarak ilgili arka uçlara yönlendirir.
                    </p>
                </div>
                <div data-lang="en">
                    <p>
                        Strict enterprise firewalls and public guest networks routinely block VoIP ports (SIP 5060/5061) while permitting outbound TCP 443. AiPBX employs Nginx layer-4 stream with <code>ssl_preread</code> to inspect the TLS ClientHello ALPN token without terminating TLS. A single TCP port 443 handles HTTPS web traffic (:8443), Asterisk WebRTC WSS signaling (:8089), and encrypted SIP TLS (:5061) seamlessly.
                    </p>
                </div>
                <div data-lang="de">
                    <p>
                        Restriktive Firmenfirewalls und Gäste-Netzwerke sperren standardmäßig SIP-Ports und erlauben nur TCP 443. AiPBX nutzt das Nginx L4-Stream-Modul mit <code>ssl_preread</code>, um das ALPN-Feld im TLS ClientHello zu analysieren. Ein einziger TCP-Port 443 bedient das Web-Portal (:8443), Asterisk WebRTC WSS (:8089) und SIP TLS (:5061) ohne TLS-Terminierungs-Overhead.
                    </p>
                </div>

                <div class="docs-code-box">
                    <div class="docs-code-header">
                        <span>/etc/nginx/nginx.conf — Nginx L4 ALPN Stream Configuration</span>
                        <button class="docs-code-copy" type="button">Copy</button>
                    </div>
                    <div class="docs-code-body">
                        <pre><code>stream {
    map $ssl_preread_alpn_protocols $upstream_backend {
        "http/1.1"        127.0.0.1:8443;   # Apache Web Portal
        "h2"              127.0.0.1:8443;   # HTTP/2 Web Portal
        "webrtc"          127.0.0.1:8089;   # Asterisk WebRTC WSS
        "sip"             127.0.0.1:5061;   # PJSIP TLS SIP Core
        default           127.0.0.1:8443;   # Standard HTTPS Fallback
    }

    server {
        listen 443;
        listen [::]:443;
        proxy_pass $upstream_backend;
        ssl_preread on;
    }
}</code></pre>
                    </div>
                </div>

                <ul class="docs-list">
                    <li>
                        <strong data-lang="tr">Sıfır Port Kirliliği:</strong>
                        <strong data-lang="en">Zero Port Sprawl:</strong>
                        <strong data-lang="de">Minimale Firewall-Regeln:</strong>
                        <span data-lang="tr">Dış dünyaya yalnızca tek bir TCP 443 portu açılarak saldırı yüzeyi %80 azaltılır.</span>
                        <span data-lang="en">Exposes only TCP port 443, drastically shrinking public attack surface.</span>
                        <span data-lang="de">Nur ein einziger TCP-Port 443 im Internet exponiert.</span>
                    </li>
                    <li>
                        <strong data-lang="tr">Donanım Hızında Geçiş:</strong>
                        <strong data-lang="en">Line-Rate Throughput:</strong>
                        <strong data-lang="de">Kernel-Level TCP Forwarding:</strong>
                        <span data-lang="tr">TLS paketleri çözülmediği için CPU yükü sıfıra yakındır ve uçtan uca sertifika doğrulaması korunur.</span>
                        <span data-lang="en">Pass-through routing without decryption retains end-to-end TLS integrity with zero CPU tax.</span>
                        <span data-lang="de">Keine CPU-Last für TLS-Terminierung; End-to-End-Verschlüsselung bleibt gewahrt.</span>
                    </li>
                </ul>
            </section>

            <!-- Section 12: In-Dialer Chat & Mobile Softphone v1.0.35 -->
            <section class="docs-section" id="in-dialer-chat">
                <h2>
                    <span data-lang="tr">12. Dahili Dialer İçi Sohbet Odası &amp; Mobil Softphone (v1.0.35)</span>
                    <span data-lang="en">12. Integrated In-Dialer Chat &amp; Mobile Softphone (v1.0.35)</span>
                    <span data-lang="de">12. In-Dialer Chatraum &amp; Mobile Softphones (v1.0.35)</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        AiPBX yerel Android (Kotlin) ve iOS (Swift) uygulamaları, arama ekranı içine yerleştirilmiş gerçek zamanlı dahili sohbet odası sunar. Kullanıcı telefon görüşmesi yaparken aynı anda arama ekranından ayrılmadan veya çağrıyı bekletmeye almadan karşı tarafa anlık metin mesajı, adres, müşteri kodu veya görsel aktarabilir. Arka planda bağımsız Go WebSocket mikroservisi çalıştığı için ses kanalında (WebRTC Opus) en ufak bir takılma veya gecikme yaşanmaz.
                    </p>
                </div>
                <div data-lang="en">
                    <p>
                        AiPBX native Android (Kotlin) and iOS (Swift) clients v1.0.35 feature an in-dialer collaborative chatroom. While on an active voice call, agents and team members can exchange instant messages, clipboard data, customer account IDs, and photos without leaving the dialer or placing the call on hold. Powered by an isolated Go WebSocket daemon with sub-millisecond dispatch.
                    </p>
                </div>
                <div data-lang="de">
                    <p>
                        Die nativen AiPBX Mobil-Apps für Android und iOS bieten einen integrierten Chat-Tab direkt in der Wähltastatur. Während eines aktiven Gesprächs können Mitarbeiter Textnachrichten, Tickets, Links oder Fotos austauschen, ohne das Gespräch zu unterbrechen. Gestützt auf den eigenständigen Go-WebSocket-Dienst.
                    </p>
                </div>

                <div class="docs-code-box">
                    <div class="docs-code-header">
                        <span>Go WebSocket In-Dialer Chat Protocol Payload</span>
                        <button class="docs-code-copy" type="button">Copy</button>
                    </div>
                    <div class="docs-code-body">
                        <pre><code>{
  "type": "dialer_chat",
  "call_id": "c7f918bc-21a4-4e78-9e12-38d58c89b210",
  "sender_ext": "1002",
  "receiver_ext": "1005",
  "timestamp": 1727173200,
  "payload": {
    "message": "Müşterinin fatura numarası: #TR-99412. Siparişi onaylıyorum.",
    "attachments": []
  }
}</code></pre>
                    </div>
                </div>
            </section>

            <!-- Section 13: Visual CDR LinkedID Call Journey Timeline -->
            <section class="docs-section" id="call-journey">
                <h2>
                    <span data-lang="tr">13. Görsel CDR LinkedID Çağrı Yolculuğu (Call Journey Timeline)</span>
                    <span data-lang="en">13. Visual CDR LinkedID Call Journey Timeline</span>
                    <span data-lang="de">13. Visuelle CDR LinkedID Gesprächsverlauf-Zeitleiste</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        Geleneksel santrallerde bir arama aktarıldığında veya kuyruğa girdiğinde CDR kayıtları parçalanır ve aramanın baştan sona serüvenini takip etmek imkansızlaşır. AiPBX, Asterisk <code>LinkedID</code> mimarisi ile bir çağrının tüm bacaklarını (DID girişi, IVR menü tuşlaması, çalma grubu, transfer edilen dahili, bekletme süresi ve sonlandırma nedeni) tek bir görsel zaman çizelgesinde (Interactive Timeline) haritalandırır.
                    </p>
                </div>
                <div data-lang="en">
                    <p>
                        Legacy PBXs fragment CDR records whenever a call is transferred, parked, or queued. AiPBX groups all call segments under Asterisk's unique <code>LinkedID</code>, rendering an end-to-end interactive timeline: ingress trunk, IVR navigation, queue wait duration, agent answer, consultative transfer, and hangup cause.
                    </p>
                </div>
                <div data-lang="de">
                    <p>
                        Herkömmliche TK-Anlagen zersplittern CDR-Daten bei Weiterleitungen. AiPBX bündelt alle Anrufsegmente unter der Asterisk-<code>LinkedID</code> in einer übersichtlichen Zeitleiste: DID-Eingang, IVR-Auswahl, Warteschlangenzeit, Agentenannahme, Rückfrage und Auflegen.
                    </p>
                </div>

                <div class="docs-code-box">
                    <div class="docs-code-header">
                        <span>MariaDB SQL — LinkedID Call Journey Aggregation Query</span>
                        <button class="docs-code-copy" type="button">Copy</button>
                    </div>
                    <div class="docs-code-body">
                        <pre><code>SELECT 
    c.uniqueid, c.linkedid, c.calldate, c.src, c.dst, 
    c.duration, c.billsec, c.disposition, c.lastapp, c.lastdata
FROM cdr c
WHERE c.linkedid = '1727171420.482'
ORDER BY c.calldate ASC;</code></pre>
                    </div>
                </div>
            </section>

            <!-- Section 14: Boss-Secretary Mode & VIP Whitelist -->
            <section class="docs-section" id="boss-secretary">
                <h2>
                    <span data-lang="tr">14. Sekreter-Yönetici Modu &amp; VIP Filtreleme (Screening)</span>
                    <span data-lang="en">14. Boss-Secretary Screening &amp; VIP Whitelist</span>
                    <span data-lang="de">14. Chef-Sekretärin-Schaltung &amp; VIP-Ausnahmeliste</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        Üst düzey yöneticilerin doğrudan rahatsız edilmesini önleyen çift aşamalı çağrı filtreleme sistemi. Yönetici dahili numarasına gelen tüm aramalar otomatik olarak yetkili asistan veya sekreterin softphone'una yönlendirilir. Sekreter çağrıyı karşılayıp danışıklı aktarma (*21) ile yöneticiye iletir. Yönetici panelinden tanımlanan VIP numaralar (Yönetim Kurulu, Aile, Kritik Ortaklar) ise sekreter filtresini doğrudan atlayarak yöneticinin telefonunu doğrudan çaldırır.
                    </p>
                </div>
                <div data-lang="en">
                    <p>
                        Executive call screening intercepts all inbound calls to C-level extensions, diverting them to an authorized executive assistant. Assistants screen and bridge callers using attended transfer (*21). VIP whitelist entries (Board members, strategic clients) automatically bypass the assistant filter and ring the executive's deskphone and mobile app directly.
                    </p>
                </div>
                <div data-lang="de">
                    <p>
                        Zweistufige Anruffilterung für Führungskräfte: Eingehende Anrufe werden primär an das Vorzimmer/Sekretariat weitergeleitet. Nach Vorabprüfung vermittelt die Assistenz den Anrufer weiter. Hinterlegte VIP-Rufnummern umgehen den Filter automatisch.
                    </p>
                </div>

                <div class="docs-code-box">
                    <div class="docs-code-header">
                        <span>/etc/asterisk/extensions_boss_secretary.conf</span>
                        <button class="docs-code-copy" type="button">Copy</button>
                    </div>
                    <div class="docs-code-body">
                        <pre><code>[sub-boss-screening]
exten => _100[1-5],1,NoOp(Boss Secretary Screening for ${EXTEN})
 same => n,Set(IS_VIP=${DB(vip_whitelist/${CALLERID(num)})})
 same => n,GotoIf($["${IS_VIP}" = "1"]?direct_boss)
 same => n,Dial(PJSIP/2000,25,tT) ; Ring Executive Assistant
 same => n,Hangup()
 same => n(direct_boss),Dial(PJSIP/${EXTEN},30,tTkK)
 same => n,Hangup()</code></pre>
                    </div>
                </div>
            </section>

            <!-- Section 15: Dynamic Voice Conferences & Web Moderator -->
            <section class="docs-section" id="confbridge">
                <h2>
                    <span data-lang="tr">15. Dinamik Sesli Konferans Odaları &amp; Canlı Web Moderatör (*8000)</span>
                    <span data-lang="en">15. Dynamic Voice Conferences &amp; Web Moderator (*8000)</span>
                    <span data-lang="de">15. Dynamische Sprachkonferenzräume &amp; Web-Moderator (*8000)</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        Asterisk <code>app_confbridge</code> mimarisiyle güçlendirilen donanım hızlandırmalı sesli toplantı odaları. Dahili veya harici kullanıcılar <code>*8000</code> veya ilgili oda numarasını tuşlayarak PIN doğrulamasıyla konferansa katılır. Web yönetim panelindeki Canlı Moderatör ekranı üzerinden kimin konuştuğu canlı olarak izlenebilir, tek tıkla tüm katılımcılar sessize alınabilir (Mute All) veya istenmeyen numaralar odadan atılabilir (Kick).
                    </p>
                </div>
                <div data-lang="en">
                    <p>
                        High-capacity voice conferencing powered by Asterisk <code>app_confbridge</code> supporting 100+ participants per bridge with DSP hardware acceleration. Extensions and external callers dial <code>*8000</code>, enter a secure PIN, and join immediately. The real-time Web Moderator console displays active speaker meters, one-click global mute, and individual kick controls.
                    </p>
                </div>
                <div data-lang="de">
                    <p>
                        Skalierbare Sprachkonferenzen über Asterisk <code>app_confbridge</code> mit PIN-Authentifizierung über <code>*8000</code>. Die intuitive Web-Moderatorkonsole bietet Pegelanzeigen für aktive Sprecher, globale Stummschaltung (Mute-All) und Teilnehmerverwaltung.
                    </p>
                </div>
            </section>

            <!-- Section 16: Advanced Voicemail-to-Email MP3 & Web Player -->
            <section class="docs-section" id="voicemail">
                <h2>
                    <span data-lang="tr">16. Gelişmiş Sesli Posta (Voicemail-to-Email MP3 &amp; Web Player *97)</span>
                    <span data-lang="en">16. Advanced Voicemail-to-Email MP3 &amp; Web Player (*97)</span>
                    <span data-lang="de">16. Voicemail-to-Email MP3 &amp; Web-Audioplayer (*97)</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        Kullanıcı meşgul, cevapsız veya çevrimdışı olduğunda arayan kişi kişiselleştirilmiş sesli mesaja yönlendirilir. Kaydedilen mesaj arka planda otomatik olarak hafif, yüksek kaliteli MP3 formatına dönüştürülür ve arayanın numarası, arama tarihi ve süresiyle birlikte personelin kurumsal e-posta adresine gönderilir. Ayrıca kullanıcılar telefonlarından <code>*97</code> tuşlayarak veya web portalındaki ses dalgası (waveform) oynatıcısından mesajlarını anında dinleyebilir.
                    </p>
                </div>
                <div data-lang="en">
                    <p>
                        When an extension is unanswered, busy, or unreachable, callers are guided to an interactive mailbox. Audio recordings are immediately encoded into compact MP3s and dispatched via SMTP with caller ID, timestamp, and duration. Extensions can inspect and play voicemails directly via <code>*97</code> IVR or the in-browser audio player.
                    </p>
                </div>
                <div data-lang="de">
                    <p>
                        Hinterlassene Sprachnachrichten werden automatisch als MP3 kodiert und inklusive Rufnummer, Datum und Gesprächsdauer per E-Mail an den Empfänger geschickt. Nebenstellen können Nachrichten über die Kurzwahl <code>*97</code> oder direkt im Web-Portal über den integrierten Waveform-Player abhören.
                    </p>
                </div>
            </section>

            <!-- Section 17: Supervisor Call Spy, Whisper & Barge-in (*90) -->
            <section class="docs-section" id="spy-whisper">
                <h2>
                    <span data-lang="tr">17. Süpervizör Çağrı Dinleme, Fısıldama ve Müdahale (*90)</span>
                    <span data-lang="en">17. Supervisor Call Spy, Whisper &amp; Barge-in (*90)</span>
                    <span data-lang="de">17. Supervisor Mithören, Flüstern &amp; Aufschalten (*90)</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        Çağrı merkezi yöneticileri ve eğitim süpervizörleri için 3 kademeli canlı görüşme denetimi:
                        <br>1. <strong>Gizli Dinleme (Spy):</strong> Süpervizör <code>*90&lt;dahili&gt;</code> tuşlayarak canlı görüşmeyi hiçbir ses veya sinyal vermeden tamamen gizlice dinler.
                        <br>2. <strong>Temsilciye Fısıldama (Whisper):</strong> DTMF <code>5</code> tuşuna basıldığında süpervizör yalnızca müşteri temsilcisine konuşur; müşteri süpervizörün sesini kesinlikle duymaz.
                        <br>3. <strong>Çağrıya Dahil Olma (Barge-in):</strong> DTMF <code>6</code> tuşuna basıldığında çağrı anında 3'lü konferansa dönüşür ve süpervizör hem müşteriyle hem temsilciyle görüşmeye müdahale edebilir.
                    </p>
                </div>
                <div data-lang="en">
                    <p>
                        Enterprise call center quality assurance with 3 dynamic modes accessible via Asterisk <code>ChanSpy</code>:
                        <br>1. <strong>Silent Monitoring:</strong> Dial <code>*90&lt;ext&gt;</code> to listen undetected without beeps or artifacts.
                        <br>2. <strong>Whisper Coaching:</strong> Press <code>5</code> to talk directly into the agent's earpiece; the caller hears nothing.
                        <br>3. <strong>3-Way Barge-in:</strong> Press <code>6</code> to convert the active call into a 3-party conference for direct escalation handling.
                    </p>
                </div>
                <div data-lang="de">
                    <p>
                        Drei professionelle Qualitätsmanagement-Stufen über Asterisk <code>ChanSpy</code>:
                        <br>1. <strong>Stummes Mithören:</strong> <code>*90&lt;Nebenstelle&gt;</code> wählt sich lautlos in das Live-Gespräch ein.
                        <br>2. <strong>Flüstermodus (Coaching):</strong> Taste <code>5</code> aktiviert die Sprachverbindung nur zum Agenten (Kunde hört nichts).
                        <br>3. <strong>Aufschalten (Barge-in):</strong> Taste <code>6</code> schaltet das Gespräch in eine 3er-Konferenz für sofortige Deeskalation um.
                    </p>
                </div>

                <div class="docs-code-box">
                    <div class="docs-code-header">
                        <span>/etc/asterisk/extensions_chanspy.conf</span>
                        <button class="docs-code-copy" type="button">Copy</button>
                    </div>
                    <div class="docs-code-body">
                        <pre><code>; *90<ext> — Supervisor Live Monitor & Coaching
exten => _*90XXXX,1,NoOp(ChanSpy initiated by ${CALLERID(num)} on ${EXTEN:3})
 same => n,Authenticate(9512) ; Supervisor PIN
 same => n,ChanSpy(PJSIP/${EXTEN:3},qwB)
 same => n,Hangup()</code></pre>
                    </div>
                </div>
            </section>

            <!-- Section 18: Ring Groups (Ring-All, Round-Robin, Hunt) -->
            <section class="docs-section" id="ring-groups">
                <h2>
                    <span data-lang="tr">18. Çalma Grupları (Ring Groups: Ring-All, Round-Robin, Hunt)</span>
                    <span data-lang="en">18. Enterprise Ring Groups (Ring-All, Round-Robin, Hunt)</span>
                    <span data-lang="de">18. Erweiterte Rufgruppen (Ring-All, Round-Robin, Kaskade)</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        Departmanlara (Satış, Destek, Muhasebe) gelen çağrıların adil ve kesintisiz dağıtımı için 4 esnek strateji:
                        <br>• <strong>Ring-All (Tümünü Çaldır):</strong> Gruptaki tüm telefonlar aynı anda çalar, ilk açan çağrıyı alır.
                        <br>• <strong>Round-Robin (Sıralı Eşit Dağıtım):</strong> Aramalar temsilcilere sırayla eşit olarak iletilir.
                        <br>• <strong>Linear Hunt (Öncelik Sıralı):</strong> Önceden belirlenen sıra listesine göre (örn. Kıdemli Uzman &gt; Yedek Temsilci) çağrı iletilir.
                        <br>• <strong>Memory Hunt (Kademeli Ekleme):</strong> İlk 10 saniye 1. dahili çalar, yanıtlanmazsa 1. çalmaya devam ederken 2. dahili de çalmaya başlar.
                    </p>
                </div>
                <div data-lang="en">
                    <p>
                        4 intelligent call distribution strategies engineered for departmental inbound routing:
                        <br>• <strong>Ring-All:</strong> Rings all group members simultaneously; first pickup claims the call.
                        <br>• <strong>Round-Robin:</strong> Cyclically balances incoming call volume evenly across available agents.
                        <br>• <strong>Linear Hunt:</strong> Traverses extensions sequentially in configured priority order.
                        <br>• <strong>Memory Hunt:</strong> Rings tier 1, and if unanswered, continues ringing tier 1 while escalating to tier 2.
                    </p>
                </div>
                <div data-lang="de">
                    <p>
                        Vier flexible Rufgruppenstrategien für Vertrieb, Support und Verwaltung:
                        <br>• <strong>Ring-All:</strong> Gleichzeitiger Ruf aller Nebenstellen.
                        <br>• <strong>Round-Robin:</strong> Zyklische Gleichverteilung der Anruflast.
                        <br>• <strong>Linear Hunt:</strong> Feste Prioritätenreihenfolge.
                        <br>• <strong>Memory Hunt:</strong> Kaskadierende Hinzunahme weiterer Apparate bei Nichtannahme.
                    </p>
                </div>
            </section>

            <!-- Section 19: Trunk-to-Trunk Transit Routing & DID Trimming -->
            <section class="docs-section" id="transit-routing">
                <h2>
                    <span data-lang="tr">19. Hatlar Arası Transit Yönlendirme &amp; DID Normalizasyonu</span>
                    <span data-lang="en">19. Trunk-to-Trunk Transit Routing &amp; DID Normalization</span>
                    <span data-lang="de">19. Trunk-to-Trunk Transit-Routing &amp; DID-Normalisierung</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        Farklı telekom operatörleri, şubeler arası IP tünelleri ve harici PBX sistemleri arasında köprü görevi gören taşıyıcı sınıfı yönlendirme mimarisi. Gelen DID numarasındaki ülke kodları (+90, 0090, 0), operatör prefiksleri regex ifadeleriyle kırpılır veya standart E.164 uluslararası biçimine dönüştürülür. Gelen çağrı santralde sonlanmadan başka bir SIP Trunk üzerinden anında harici GSM veya şube santraline yönlendirilebilir.
                    </p>
                </div>
                <div data-lang="en">
                    <p>
                        Carrier-grade transit switching interconnecting disparate SIP trunks, branch office gateways, and external legacy PBXs. Powerful regular expressions strip or prepend regional dial prefixes (e.g. leading zeros, country codes) enforcing strict E.164 compliance before handing calls off to downstream transit trunks.
                    </p>
                </div>
                <div data-lang="de">
                    <p>
                        Hochperformantes Transit-Switching zwischen unterschiedlichen SIP-Trunk-Providern und Niederlassungen. Regex-basierte Bereinigung von Ländervorwahlen (+49, 0049, 0) und Normalisierung auf E.164 vor der Weiterleitung an Partnersysteme.
                    </p>
                </div>
            </section>

            <!-- Section 20: Microsoft Teams Direct Routing TLS/SRTP SBC Gateway -->
            <section class="docs-section" id="msteams">
                <h2>
                    <span data-lang="tr">20. Microsoft Teams Direct Routing TLS/SRTP SBC Ağ Geçidi</span>
                    <span data-lang="en">20. Microsoft Teams Direct Routing TLS/SRTP SBC Gateway</span>
                    <span data-lang="de">20. Microsoft Teams Direct Routing TLS/SRTP SBC-Gateway</span>
                </h2>
                <div data-lang="tr">
                    <p>
                        AiPBX, ek bir pahalı harici SBC (Session Border Controller) donanımına gerek duymadan Microsoft 365 Teams Direct Routing entegrasyonu sağlar. SIP TLS Port 5061, Microsoft güvenilir kök sertifikaları (DigiCert / Baltimore) ve SRTP AES_CM_128 medya şifreleme gereksinimlerini doğrudan Asterisk 22 PJSIP çekirdeğinde karşılar. Teams masaüstü ve mobil kullanıcıları kurumsal santral dahilileriyle ve telekom dış hatlarıyla kesintisiz görüşebilir.
                    </p>
                </div>
                <div data-lang="en">
                    <p>
                        AiPBX provides a native, hardware-free Microsoft 365 Teams Direct Routing SBC interface. Asterisk 22 PJSIP natively terminates Microsoft Teams SIP signaling over TLS 5061 with trusted CA chains and encrypts media using SRTP AES_CM_128. Enables Teams desktop and mobile users to place and receive corporate PBX calls effortlessly.
                    </p>
                </div>
                <div data-lang="de">
                    <p>
                        Direkte Anbindung an Microsoft 365 Teams ohne teure Drittanbieter-SBC-Hardware. Vollständige Unterstützung von SIP TLS auf Port 5061, Microsoft Root-CAs und SRTP AES_CM_128 Sprachverschlüsselung für nahtlose Telefonie im Teams-Client.
                    </p>
                </div>

                <div class="docs-code-box">
                    <div class="docs-code-header">
                        <span>PowerShell — Microsoft Teams PSTN Gateway Provisioning</span>
                        <button class="docs-code-copy" type="button">Copy</button>
                    </div>
                    <div class="docs-code-body">
                        <pre><code># 1. Register AiPBX SBC Gateway in Microsoft 365 Tenant
New-CsOnlinePSTNGateway -Fqdn "sbc.aipbx.bid" -SipSignalingPort 5061 `
    -MaxConcurrentSessions 100 -Enabled $true

# 2. Assign Voice Routing Policy to Teams User
Set-CsPhoneNumberAssignment -Identity "user@company.com" -PhoneNumber "+902129990001" -PhoneNumberType DirectRouting
Grant-CsOnlineVoiceRoutingPolicy -Identity "user@company.com" -PolicyName "AiPBX-PSTN-Policy"</code></pre>
                    </div>
                </div>
            </section>


            <!-- Pagination -->
            <div class="docs-pagination">
                <a href="/" class="pagination-btn">
                    <span class="pagination-label">
                        <span data-lang="tr">Önceki Sayfa</span>
                        <span data-lang="en">Previous</span>
                        <span data-lang="de">Vorherige</span>
                    </span>
                    <span class="pagination-title">
                        <span data-lang="tr">🏠 Genel Bakış (Home)</span>
                        <span data-lang="en">🏠 Overview (Home)</span>
                        <span data-lang="de">🏠 Übersicht (Home)</span>
                    </span>
                </a>
                <a href="/tables" class="pagination-btn next">
                    <span class="pagination-label">
                        <span data-lang="tr">Sonraki Sayfa</span>
                        <span data-lang="en">Next</span>
                        <span data-lang="de">Nächste</span>
                    </span>
                    <span class="pagination-title">
                        <span data-lang="tr">📊 Karşılaştırma &amp; Tablolar</span>
                        <span data-lang="en">📊 Tables &amp; Specs</span>
                        <span data-lang="de">📊 Tabellen &amp; Matrix</span>
                    </span>
                </a>
            </div>
        </main>

        <!-- Right Sticky Table of Contents -->
        <div class="docs-toc">
            <div class="toc-title">
                <span data-lang="tr">Bu Sayfada (20 Modül)</span>
                <span data-lang="en">On This Page (20 Modules)</span>
                <span data-lang="de">Auf dieser Seite (20 Module)</span>
            </div>
            <ul class="toc-list">
                <li><a href="#extensions">1. Extension Management</a></li>
                <li><a href="#ivr">2. Multi-Level IVR</a></li>
                <li><a href="#routing">3. Call Routing</a></li>
                <li><a href="#webrtc">4. WebRTC Softphone</a></li>
                <li><a href="#fax">5. Digital Fax</a></li>
                <li><a href="#feature-codes">6. Feature Codes (* Codes)</a></li>
                <li><a href="#passkey">7. Biometric Passkeys</a></li>
                <li><a href="#oauth">8. Google OAuth 2.0</a></li>
                <li><a href="#go-chat">9. Go Real-Time Chat</a></li>
                <li><a href="#call-barring">10. Call Barring</a></li>
                <li><a href="#alpn">11. Port 443 ALPN Stream</a></li>
                <li><a href="#in-dialer-chat">12. In-Dialer Chat Room</a></li>
                <li><a href="#call-journey">13. Visual CDR LinkedID</a></li>
                <li><a href="#boss-secretary">14. Boss-Secretary &amp; VIP</a></li>
                <li><a href="#confbridge">15. Voice Conferences (*8000)</a></li>
                <li><a href="#voicemail">16. Voicemail MP3 (*97)</a></li>
                <li><a href="#spy-whisper">17. Supervisor Spy (*90)</a></li>
                <li><a href="#ring-groups">18. Ring Groups</a></li>
                <li><a href="#transit-routing">19. Transit Routing</a></li>
                <li><a href="#msteams">20. MS Teams Direct Routing</a></li>
            </ul>
        </div>
    </div>

    <!-- Universal Footer -->
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
