/**
 * AI PBX Documentation & Portal Main JavaScript
 * Handles:
 * - Multi-Language (i18n) Switcher (TR / EN / DE) with Auto-Detection
 * - Mobile Drawer Navigation
 * - Code Block Copy to Clipboard
 * - Sidebar Search / Filter
 * - Table of Contents Scrollspy
 * - Trilingual Interactive Screen Switcher
 */

// 1. Interactive Screen Switcher Multilingual Data
const screensData = [
    {
        img: 'img/app_dialer.jpg',
        tr: {
            badge: '🟢 Canlı Santral Entegrasyonu',
            badgeColor: 'rgba(16, 185, 129, 0.15)',
            badgeBorder: 'rgba(16, 185, 129, 0.3)',
            badgeText: '#34d399',
            title: 'WebRTC HD Tuş Takımı (Dialer)',
            desc: 'Dahili 19000 anlık çevrimiçi kayıtlı. DTLS-SRTP şifreli WebRTC Opus ses motoru ile düşük gecikmeli, kristal netliğinde kurum içi ve dış hat görüşmeleri.',
            bullets: [
                '<strong>Hızlı Arama &amp; DTMF:</strong> 0-9, *, # tuşları ile sesli yanıt (IVR) sistemlerinde anında tuşlama yapın.',
                '<strong>Canlı Durum Göstergesi:</strong> Bağlantı durumunu (Çevrimiçi, Bağlanıyor, Çevrimdışı) üst çubukta anında görün.',
                '<strong>Sıfır Çökme / Hafif Mimari:</strong> Ağır C++ kütüphaneleri yerine standart WebRTC motoru ile sadece 2.8 MB APK.'
            ]
        },
        en: {
            badge: '🟢 Live PBX Integration',
            badgeColor: 'rgba(16, 185, 129, 0.15)',
            badgeBorder: 'rgba(16, 185, 129, 0.3)',
            badgeText: '#34d399',
            title: 'WebRTC HD Keypad (Dialer)',
            desc: 'Extension 19000 registered live. Crystal-clear internal and external calls powered by low-latency DTLS-SRTP encrypted Opus WebRTC audio engine.',
            bullets: [
                '<strong>Speed Dial &amp; DTMF:</strong> Instant in-call touch-tone dialing for IVR menus with 0-9, *, #.',
                '<strong>Live Status Indicator:</strong> Real-time connection feedback (Online, Connecting, Offline) in the top bar.',
                '<strong>Ultra-Lightweight Core:</strong> Clean WebRTC stack without bloated C++ wrappers—just 2.8 MB APK.'
            ]
        },
        de: {
            badge: '🟢 Live-PBX-Integration',
            badgeColor: 'rgba(16, 185, 129, 0.15)',
            badgeBorder: 'rgba(16, 185, 129, 0.3)',
            badgeText: '#34d399',
            title: 'WebRTC HD Wähltastatur (Dialer)',
            desc: 'Nebenstelle 19000 live registriert. Kristallklare interne und externe Gespräche dank DTLS-SRTP verschlüsseltem Opus WebRTC Audio-Engine mit geringer Latenz.',
            bullets: [
                '<strong>Kurzwahl &amp; DTMF:</strong> Sofortige DTMF-Tonwahl für IVR-Sprachmenüs mit 0-9, *, #.',
                '<strong>Live-Statusanzeige:</strong> Echtzeit-Verbindungsstatus (Online, Verbinden, Offline) in der oberen Leiste.',
                '<strong>Schlanke Architektur:</strong> Moderner WebRTC-Stack ohne Ballast—nur 2.8 MB APK-Dateigröße.'
            ]
        }
    },
    {
        img: 'img/app_chat.jpg',
        tr: {
            badge: '💬 Go WebSocket Chat Hub · Grup Sohbeti',
            badgeColor: 'rgba(0, 143, 208, 0.15)',
            badgeBorder: 'rgba(0, 143, 208, 0.3)',
            badgeText: '#38bdf8',
            title: 'Bireysel & Çok Katılımcılı Grup Sohbeti',
            desc: 'Santral kullanıcıları arasında 1-e-1 ve çok kullanıcılı grup sohbeti. Go aipbx-chat WebSocket motoru ile sıfır gecikmeli mesajlaşma, filtreleme çipleri ve rol yönetimi.',
            bullets: [
                '<strong>Grup Kanalları &amp; Hızlı Oluşturma:</strong> Çoklu katılımcı seçimi, grup adı/açıklaması ve rol yönetimi (Yönetici/Üye).',
                '<strong>Filtreleme Çipleri:</strong> "Tümü", "Bireysel" ve "Gruplar" sekmeleri ile gelen kutunuzu anında filtreleyin.',
                '<strong>Yazıyor... &amp; Okundu Bildirimi:</strong> Karşı tarafın yazma durumu, mesaj teslimi ve renkli gönderici rozetleri.'
            ]
        },
        en: {
            badge: '💬 Go WebSocket Chat Hub · Group Messaging',
            badgeColor: 'rgba(0, 143, 208, 0.15)',
            badgeBorder: 'rgba(0, 143, 208, 0.3)',
            badgeText: '#38bdf8',
            title: 'Direct & Multi-Party Group Chat',
            desc: '1-on-1 and group instant messaging between PBX extensions. Zero-latency delivery via standalone Go WebSocket service with role management.',
            bullets: [
                '<strong>Group Channels &amp; Setup:</strong> Multi-contact picker, custom channel title/topic, and admin/member privileges.',
                '<strong>Filter Chips:</strong> Instant switching between "All", "Direct", and "Groups" inbox views.',
                '<strong>Typing &amp; Read Receipts:</strong> Real-time typing indicators, delivery confirmation, and deterministic color badges.'
            ]
        },
        de: {
            badge: '💬 Go WebSocket Chat Hub · Gruppen-Chat',
            badgeColor: 'rgba(0, 143, 208, 0.15)',
            badgeBorder: 'rgba(0, 143, 208, 0.3)',
            badgeText: '#38bdf8',
            title: 'Direkt- & Mehrbenutzer-Gruppenchat',
            desc: '1-zu-1- und Gruppen-Instant-Messaging zwischen Nebenstellen. Latenzfreie Zustellung über eigenständigen Go-WebSocket-Dienst mit Rollenverwaltung.',
            bullets: [
                '<strong>Gruppenkanäle &amp; Erstellung:</strong> Schnelle Mitgliederauswahl, Kanalbeschreibung und Administrator-/Mitgliederrollen.',
                '<strong>Filter-Chips:</strong> Schnelles Umschalten zwischen Postfächern: "Alle", "Direkt" und "Gruppen".',
                '<strong>Tipp- &amp; Lesebestätigung:</strong> Echtzeit-Tippstatus, Zustellquittungen und deterministische Farb-Badges.'
            ]
        }
    },
    {
        img: 'img/app_contacts.jpg',
        tr: {
            badge: '👥 50+ Kurumsal Dahili',
            badgeColor: 'rgba(139, 92, 246, 0.15)',
            badgeBorder: 'rgba(139, 92, 246, 0.3)',
            badgeText: '#a78bfa',
            title: 'Canlı Kurumsal Rehber',
            desc: 'Santral veri tabanındaki tüm dahililer otomatik olarak telefonunuza senkronize olur. Harici numara ezberleme zorunluluğuna son.',
            bullets: [
                '<strong>Anlık Çevrimiçi Durum:</strong> Kimin masasında veya telefonda müsait olduğunu yeşil nokta ile canlı izleyin.',
                '<strong>Rol &amp; Yetki Göstergeleri:</strong> admin, cc_agent, user gibi departman yetkileri unvan altında belirtilir.',
                '<strong>Tek Tıkla Arama:</strong> Dahili listesindeki yeşil telefon butonuna dokunarak doğrudan çağrı başlatın.'
            ]
        },
        en: {
            badge: '👥 50+ Corporate Extensions',
            badgeColor: 'rgba(139, 92, 246, 0.15)',
            badgeBorder: 'rgba(139, 92, 246, 0.3)',
            badgeText: '#a78bfa',
            title: 'Live Corporate Directory',
            desc: 'All company extensions automatically sync to your phone directly from the PBX database. No need to memorize external numbers.',
            bullets: [
                '<strong>Live Presence Status:</strong> Green presence dots indicate who is available at their desk or softphone.',
                '<strong>Role &amp; Department Tags:</strong> Clear badges for admin, cc_agent, and standard user privileges.',
                '<strong>One-Tap Calling:</strong> Start internal HD audio calls immediately by tapping the call button.'
            ]
        },
        de: {
            badge: '👥 50+ Unternehmens-Nebenstellen',
            badgeColor: 'rgba(139, 92, 246, 0.15)',
            badgeBorder: 'rgba(139, 92, 246, 0.3)',
            badgeText: '#a78bfa',
            title: 'Live-Unternehmensverzeichnis',
            desc: 'Alle Nebenstellen der Telefonanlage werden automatisch mit Ihrem Smartphone synchronisiert. Kein manuelles Abspeichern nötig.',
            bullets: [
                '<strong>Echtzeit-Präsenz:</strong> Grüne Punkte zeigen sofort, wer am Arbeitsplatz oder Softphone erreichbar ist.',
                '<strong>Rollen- &amp; Abteilungsanzeige:</strong> Klare Kennzeichnung für Administrator, CC-Agent und Standardbenutzer.',
                '<strong>Ein-Klick-Anruf:</strong> Schneller Anrufstart durch einfaches Antippen des grünen Hörersymbols.'
            ]
        }
    },
    {
        img: 'img/app_history.jpg',
        tr: {
            badge: '📊 Detaylı CDR & İstatistik',
            badgeColor: 'rgba(245, 158, 11, 0.15)',
            badgeBorder: 'rgba(245, 158, 11, 0.3)',
            badgeText: '#fbbf24',
            title: 'Arama Geçmişi & Süre Sayacı',
            desc: 'Santral CDR kayıtları ile tam senkronize çağrı listesi. Cevapsız, gelen ve giden aramaları filtreleyip analiz edin.',
            bullets: [
                '<strong>Çoklu Filtreleme:</strong> Tümü, Cevapsız, Gelen ve Giden çağrıları tek dokunuşla ayırın.',
                '<strong>Saniye Hassasiyetinde Süre:</strong> Her aramanın başlangıç saati ve net konuşma süresi listelenir.',
                '<strong>Geri Arama:</strong> Geçmiş listedeki herhangi bir kayda dokunarak anında geri arama yapın.'
            ]
        },
        en: {
            badge: '📊 Detailed CDR & Analytics',
            badgeColor: 'rgba(245, 158, 11, 0.15)',
            badgeBorder: 'rgba(245, 158, 11, 0.3)',
            badgeText: '#fbbf24',
            title: 'Call History & Duration Logs',
            desc: 'Real-time call detail records synced from the PBX database. Filter missed, incoming, and outgoing calls effortlessly.',
            bullets: [
                '<strong>Multi-Direction Filtering:</strong> Instant chips for All, Missed, Inbound, and Outbound calls.',
                '<strong>Exact Duration Counters:</strong> Accurate timestamp and talk duration for every call record.',
                '<strong>One-Touch Redial:</strong> Tap any record in history to dial back instantly.'
            ]
        },
        de: {
            badge: '📊 Detaillierte CDR-Statistiken',
            badgeColor: 'rgba(245, 158, 11, 0.15)',
            badgeBorder: 'rgba(245, 158, 11, 0.3)',
            badgeText: '#fbbf24',
            title: 'Anrufhistorie & Gesprächsdauer',
            desc: 'Vollständig mit den Server-CDR-Einträgen synchronisierte Anrufliste. Verpasste, eingehende und ausgehende Anrufe im Überblick.',
            bullets: [
                '<strong>Richtungsspezifische Filter:</strong> Schnelle Trennung nach Alle, Verpasst, Eingehend und Ausgehend.',
                '<strong>Sekundengenaue Dauer:</strong> Startzeitstempel und exakte Netto-Gesprächsdauer für jeden Eintrag.',
                '<strong>Direkter Rückruf:</strong> Ein Fingertipp auf den Eintrag startet den sofortigen Rückruf.'
            ]
        }
    },
    {
        img: 'img/app_login.jpg',
        tr: {
            badge: '⚙️ Zero Config • Build 33',
            badgeColor: 'rgba(14, 165, 233, 0.15)',
            badgeBorder: 'rgba(14, 165, 233, 0.3)',
            badgeText: '#38bdf8',
            title: 'Hızlı Sunucu Bağlantısı',
            desc: 'Karmaşık SIP portları, proxy adresleri ve STUN/TURN şifreleriyle uğraşmaya gerek yok. Yalnızca santral URL adresinizi girin.',
            bullets: [
                '<strong>Otomatik Yapılandırma Keşfi:</strong> Sunucu adresinden WSS ve TLS parametreleri dinamik olarak çekilir.',
                '<strong>Dahili Sürüm Takibi:</strong> AiPBX v1.0.32 (Build 33) ve sonraki sürümlerle %100 tam uyumlu.',
                '<strong>Self-Signed ve Güvenli TLS:</strong> Kurum içi özel SSL sertifikalarıyla kesintisiz el sıkışma.'
            ]
        },
        en: {
            badge: '⚙️ Zero Config • Build 33',
            badgeColor: 'rgba(14, 165, 233, 0.15)',
            badgeBorder: 'rgba(14, 165, 233, 0.3)',
            badgeText: '#38bdf8',
            title: 'Zero-Config Server Auto-Discovery',
            desc: 'No manual SIP ports, proxy URLs, or STUN/TURN passwords required. Simply provide your PBX server URL, extension, and secret.',
            bullets: [
                '<strong>Dynamic Parameter Fetch:</strong> WSS transport and TLS ports are discovered automatically over HTTPS.',
                '<strong>Protocol Compatibility:</strong> Certified for AiPBX v1.0.32 (Build 33) and later releases.',
                '<strong>Enterprise TLS Support:</strong> Smooth handshake with Let\'s Encrypt as well as internal CA certificates.'
            ]
        },
        de: {
            badge: '⚙️ Zero-Config • Build 33',
            badgeColor: 'rgba(14, 165, 233, 0.15)',
            badgeBorder: 'rgba(14, 165, 233, 0.3)',
            badgeText: '#38bdf8',
            title: 'Automatische Server-Erkennung',
            desc: 'Keine komplizierte Eingabe von SIP-Ports, Proxy-Adressen oder TURN-Zugangsdaten. Geben Sie einfach Ihre Server-URL ein.',
            bullets: [
                '<strong>Dynamische Konfiguration:</strong> WSS- und TLS-Parameter werden automatisch über HTTPS ermittelt.',
                '<strong>Versionskompatibilität:</strong> 100 % kompatibel mit AiPBX v1.0.32 (Build 33) und neuer.',
                '<strong>Sicheres Enterprise-TLS:</strong> Zuverlässiger Verbindungsaufbau mit Let\'s Encrypt oder internen Zertifikaten.'
            ]
        }
    }
];

let activeScreenIndex = 0;

function switchScreen(index) {
    activeScreenIndex = index;
    const currentLang = document.documentElement.getAttribute('data-lang') || 'en';
    updateScreenDisplay(index, currentLang);
}

function updateScreenDisplay(index, lang) {
    const item = screensData[index];
    if (!item) return;

    const data = item[lang] || item['en'] || item['tr'];

    // Update Tab Buttons
    const buttons = document.querySelectorAll('.screen-tab-btn');
    buttons.forEach((btn, idx) => {
        if (idx === index) btn.classList.add('active');
        else btn.classList.remove('active');
    });

    // Smooth image fade
    const imgEl = document.getElementById('activePhoneImg');
    if (imgEl && imgEl.src !== item.img) {
        imgEl.style.opacity = '0.3';
        setTimeout(() => {
            imgEl.src = item.img;
            imgEl.style.opacity = '1';
        }, 120);
    }

    // Update Meta Content
    const badgeEl = document.getElementById('activeScreenBadge');
    if (badgeEl) {
        badgeEl.innerText = data.badge;
        badgeEl.style.background = data.badgeColor;
        badgeEl.style.borderColor = data.badgeBorder;
        badgeEl.style.color = data.badgeText;
    }

    const titleEl = document.getElementById('activeScreenTitle');
    if (titleEl) titleEl.innerText = data.title;

    const descEl = document.getElementById('activeScreenDesc');
    if (descEl) descEl.innerText = data.desc;

    const bulletsContainer = document.getElementById('activeScreenBullets');
    if (bulletsContainer) {
        bulletsContainer.innerHTML = data.bullets.map(b => `
            <li class="screen-meta-bullet" style="display: flex; gap: 10px; font-size: 0.9rem; color: #cbd5e1; margin-bottom: 8px;">
                <span style="color: #34d399;">✔</span>
                <span>${b}</span>
            </li>
        `).join('');
    }
}

// 2. Multilingual Page Meta Information (Dynamic Title & Description for SEO)
const pageMetaTranslations = {
    'index': {
        tr: {
            title: 'AiPBX — Açık Kaynak Kurumsal IP Santral & PBX Telefon Sistemi | Asterisk 22 & WebRTC',
            desc: 'AiPBX; Asterisk 22, Nginx L4 ALPN stream çoklama, WebRTC, Go WebSocket sohbet motoru ve yerel Android/iOS mobil softphone istemcilerini birleştiren modern, açık kaynak kurumsal IP santral sistemidir.'
        },
        en: {
            title: 'AiPBX — Modern Open Source Enterprise IP Telephony | Asterisk 22 & WebRTC',
            desc: 'AiPBX is an enterprise-grade open-source IP PBX uniting Asterisk 22, Nginx L4 ALPN stream multiplexing, WebRTC, Go chat engine, and native mobile clients.'
        },
        de: {
            title: 'AiPBX — Moderne Open-Source Enterprise IP-Telefonanlage | Asterisk 22 & WebRTC',
            desc: 'AiPBX ist eine leistungsstarke Open-Source Enterprise IP-Telefonanlage mit Asterisk 22, Nginx L4 ALPN-Stream-Multiplexing, WebRTC, Go-Chat und nativen mobilen Apps.'
        }
    },
    'features': {
        tr: {
            title: 'Santral Özellikleri & Modüller — IVR, Ses Kayıt, Konferans, Faks | AiPBX',
            desc: 'AiPBX kurumsal santral modülleri: Çok seviyeli sesli yanıt sistemi (IVR), DID hat yönlendirme, otomatik ses kaydı, WebRTC softphone, dijital faks ve santral yıldız kodları.'
        },
        en: {
            title: 'Enterprise PBX Features & Modules — IVR, Recording, Fax | AiPBX',
            desc: 'AiPBX telephony suite: Multi-level IVR, DID inbound/outbound routing, in-browser WebRTC softphone, digital fax server, and star codes directory.'
        },
        de: {
            title: 'Enterprise PBX-Funktionen & Module — IVR, Aufnahme, Fax | AiPBX',
            desc: 'AiPBX Telefonie-Suite: Mehrstufiges IVR, DID-Routing, browserbasierter WebRTC-Softphone, digitaler Faxserver und vollständige Sterncodes.'
        }
    },
    'callcenter': {
        tr: {
            title: 'Çağrı Merkezi Çözümleri — ACD Kuyruk, Mola Kodları (*22/*23), Wallboard | AiPBX',
            desc: 'AiPBX çağrı merkezi altyapısı: Statik ve dinamik temsilciler, *22 mola ve *23 moladan dönüş kodları, anlık canlı duvar panosu (wallboard) ve *90 süpervizör çağrı dinleme.'
        },
        en: {
            title: 'Call Center & Queue Operations — ACD, Break Codes (*22/*23), Wallboard | AiPBX',
            desc: 'AiPBX enterprise call center capabilities: Static and dynamic members, *22 pause and *23 unpause break codes, live wallboard, and *90 supervisor call spy.'
        },
        de: {
            title: 'Callcenter & Warteschlangen — ACD, Pausencodes (*22/*23), Wallboard | AiPBX',
            desc: 'AiPBX Callcenter-Lösungen: Statische und dynamische Agenten, *22 Pausen- und *23 Wiederaufnahmecodes, Live-Wallboard und *90 Supervisor-Mithören.'
        }
    },
    'mobile-apps': {
        tr: {
            title: 'Mobil Softphone Uygulamaları — Android APK & iOS WebRTC PBX | AiPBX',
            desc: 'AiPBX yerel mobil uygulamaları: Android Kotlin ve iOS Swift, WebRTC Opus HD ses kalitesi, arka plan push bildirimleri, doğrudan APK indirme ve sıfır yapılandırma.'
        },
        en: {
            title: 'Enterprise Mobile Apps (Android & iOS) — WebRTC Softphone | AiPBX',
            desc: 'AiPBX native mobile communications: Android Kotlin and iOS Swift, WebRTC Opus HD voice, background push wake-up, and direct APK download.'
        },
        de: {
            title: 'Enterprise Mobil-Apps (Android & iOS) — WebRTC Softphone | AiPBX',
            desc: 'AiPBX native mobile Kommunikation: Android Kotlin und iOS Swift, WebRTC Opus HD-Audio, Hintergrund-Push-Aufweckung und direkte APK-Downloads.'
        }
    },
    'architecture': {
        tr: {
            title: 'Sistem Mimarisi & ALPN Çoklama — Asterisk 22, Nginx L4, WebRTC | AiPBX',
            desc: 'AiPBX katmanlı sistem mimarisi: Port 443 ALPN stream çoklama, PJSIP çift uç nokta WebRTC mimarisi, bağımsız Go WebSocket anlık mesajlaşma ve iki katmanlı MariaDB modeli.'
        },
        en: {
            title: 'System Architecture & ALPN Multiplexing — Asterisk 22, Nginx L4 | AiPBX',
            desc: 'AiPBX layered architecture: Port 443 ALPN stream multiplexing, dual-endpoint PJSIP WebRTC, Go WebSocket chat engine, and two-tier MariaDB security model.'
        },
        de: {
            title: 'Systemarchitektur & ALPN-Multiplexing — Asterisk 22, Nginx L4 | AiPBX',
            desc: 'AiPBX Schichtenarchitektur: Port 443 ALPN-Stream-Multiplexing, Dual-Endpoint PJSIP WebRTC, Go WebSocket-Chat und zweistufige MariaDB-Sicherheit.'
        }
    },
    'installation': {
        tr: {
            title: 'Kurulum ve Yönetim Rehberi — Debian & Ubuntu Asterisk PBX Dağıtımı | AiPBX',
            desc: 'AiPBX otomatik anahtar teslim kurulum kılavuzu: Ubuntu LTS ve Debian gereksinimleri, tek komutla install.sh scripti, port ve güvenlik duvarı kuralları, ilk yapılandırma.'
        },
        en: {
            title: 'Installation & Administration Guide — Turnkey Deployment | AiPBX',
            desc: 'AiPBX automated turnkey deployment guide: Ubuntu LTS & Debian requirements, install.sh walkthrough, firewall rules, and initial provisioning.'
        },
        de: {
            title: 'Installations- & Administrationshandbuch — Turnkey-Setup | AiPBX',
            desc: 'AiPBX automatisierte Komplettinstallation: Ubuntu LTS & Debian Voraussetzungen, install.sh Schritt-für-Schritt, Firewall-Regeln und Konfiguration.'
        }
    },
    'security': {
        tr: {
            title: 'Santral Güvenliği & CCIS Gateway — Fail2ban, TLS 1.3, SRTP Şifreleme | AiPBX',
            desc: 'AiPBX güvenlik savunma katmanları: Fail2ban kaba kuvvet saldırı koruması, TLS 1.3 ve SRTP ses şifreleme, NEC UNIVERGE SV8100/SV8300/SV8500 CCIS protokol gateway entegrasyonu.'
        },
        en: {
            title: 'Security Hardening & CCIS Protocol Gateway — TLS 1.3, Fail2ban | AiPBX',
            desc: 'AiPBX security defense layers: Fail2ban brute-force protection, TLS 1.3/SRTP encryption, and proprietary NEC UNIVERGE SV8100/SV8300/SV8500 CCIS protocol gateway.'
        },
        de: {
            title: 'Sicherheitshärtung & CCIS-Protokoll-Gateway — TLS 1.3, Fail2ban | AiPBX',
            desc: 'AiPBX Sicherheitsebenen: Fail2ban Brute-Force-Schutz, TLS 1.3/SRTP-Verschlüsselung und NEC UNIVERGE SV8100/SV8300/SV8500 CCIS-Protokoll-Gateway.'
        }
    },
    'api-docs': {
        tr: {
            title: 'REST API & WebSocket Dokümantasyonu — Geliştirici Kılavuzu & Entegrasyon | AiPBX',
            desc: 'AiPBX geliştirici referansı: REST API uç noktaları, Go anlık WebSocket mesajlaşma şeması, PHP 8 MVC mimarisi ve PHPUnit otomatik test paketi ile santral entegrasyonu.'
        },
        en: {
            title: 'REST API & WebSocket Developer Reference — Integrations | AiPBX',
            desc: 'AiPBX developer reference: REST API endpoints, Go real-time WebSocket chat schema, PHP 8 MVC architecture, and PHPUnit automated test suite.'
        },
        de: {
            title: 'REST-API & WebSocket Entwickler-Referenz — Integrationen | AiPBX',
            desc: 'AiPBX Entwickler-Referenz: REST-API-Endpunkte, Go-Echtzeit-WebSocket-Nachrichtenschema, PHP 8 MVC-Architektur und automatisierte PHPUnit-Tests.'
        }
    },
    'msteams': {
        tr: {
            title: 'Microsoft Teams Entegrasyonu & Direct Routing — Asterisk 22 SBC Gateway | AiPBX',
            desc: 'AiPBX Microsoft Teams Direct Routing entegrasyonu: Asterisk 22 PJSIP SBC altyapısı, SIP TLS ve SRTP ses şifreleme, Microsoft 365 PowerShell yapılandırması ve Teams kanal bildirimleri.'
        },
        en: {
            title: 'Microsoft Teams Direct Routing & SBC Gateway — Asterisk 22 | AiPBX',
            desc: 'AiPBX Microsoft Teams Direct Routing: Asterisk 22 PJSIP SBC gateway, SIP TLS and SRTP media encryption, Microsoft 365 PowerShell setup, and Teams webhook alerts.'
        },
        de: {
            title: 'Microsoft Teams Direct Routing & SBC-Gateway — Asterisk 22 | AiPBX',
            desc: 'AiPBX Microsoft Teams Direct Routing: Asterisk 22 PJSIP SBC-Gateway, SIP TLS und SRTP Sprachverschlüsselung, Microsoft 365 PowerShell-Setup und Teams-Webhooks.'
        }
    }
};

// 3. Language Management (TR / EN / DE)
function setLanguage(lang) {
    if (!['tr', 'en', 'de'].includes(lang)) lang = 'tr';

    document.documentElement.setAttribute('data-lang', lang);
    document.documentElement.lang = lang;

    try {
        localStorage.setItem('aipbx_lang', lang);
    } catch(e) {}

    // Update document title and meta description dynamically
    let rawPage = window.location.pathname.split('/').pop() || 'index.html';
    let pageKey = rawPage.replace('.html', '') || 'index';
    if (!pageMetaTranslations[pageKey]) pageKey = 'index';
    
    if (pageMetaTranslations[pageKey] && pageMetaTranslations[pageKey][lang]) {
        document.title = pageMetaTranslations[pageKey][lang].title;
        const metaDesc = document.querySelector('meta[name="description"]');
        if (metaDesc) metaDesc.setAttribute('content', pageMetaTranslations[pageKey][lang].desc);
    }

    // Update Desktop Button text & flag
    const flagEl = document.getElementById('currentLangFlag');
    const codeEl = document.getElementById('currentLangCode');
    const flags = { tr: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800" width="22" height="15"><rect width="1200" height="800" fill="#E30A17"/><circle cx="480" cy="400" r="200" fill="#fff"/><circle cx="520" cy="400" r="160" fill="#E30A17"/><polygon fill="#fff" points="583,400 641,335 600,400 641,465" transform="rotate(18,610,400)"/></svg>', en: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 30" width="22" height="15"><rect width="60" height="30" fill="#012169"/><path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/><path d="M0,0 L60,30 M60,0 L0,30" stroke="#C8102E" stroke-width="4"/><path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/><path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/></svg>', de: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600" width="22" height="15"><rect width="900" height="600" fill="#fff"/><rect width="900" height="200" fill="#ed2939"/><rect y="400" width="900" height="200" fill="#ed2939"/></svg>' };
    const codes = { tr: 'TR', en: 'EN', de: 'DE' };

    if (flagEl) flagEl.innerHTML = flags[lang] || '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>';
    if (codeEl) codeEl.innerText = codes[lang] || lang.toUpperCase();

    // Update Dropdown active states
    document.querySelectorAll('.lang-option').forEach(btn => {
        if (btn.getAttribute('data-set-lang') === lang) btn.classList.add('active');
        else btn.classList.remove('active');
    });

    // Update Mobile active states
    document.querySelectorAll('.mobile-lang-btn').forEach(btn => {
        if (btn.getAttribute('data-set-lang') === lang) btn.classList.add('active');
        else btn.classList.remove('active');
    });

    // Update interactive screen switcher if present
    updateScreenDisplay(activeScreenIndex, lang);
}

// Initialize on DOM Ready
document.addEventListener('DOMContentLoaded', () => {
    // 1. Synchronize UI with already active language applied synchronously in <head>
    const activeLang = document.documentElement.getAttribute('data-lang') || localStorage.getItem('aipbx_lang') || 'tr';
    setLanguage(activeLang);

    // Language Dropdown Toggle
    const langSelector = document.getElementById('langSelector');
    const langSelectorBtn = document.getElementById('langSelectorBtn');

    if (langSelectorBtn && langSelector) {
        langSelectorBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            langSelector.classList.toggle('open');
        });

        document.addEventListener('click', () => {
            langSelector.classList.remove('open');
        });
    }

    // Language Option Click Listeners
    document.querySelectorAll('.lang-option, .mobile-lang-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const lang = btn.getAttribute('data-set-lang');
            if (lang) {
                setLanguage(lang);
                if (langSelector) langSelector.classList.remove('open');
            }
        });
    });

    // 2. Mobile Drawer Navigation
    const mobileToggleBtn = document.getElementById('mobileToggleBtn');
    const mobileDrawer = document.getElementById('mobileDrawer');
    const mobileDrawerClose = document.getElementById('mobileDrawerClose');
    const mobileBackdrop = document.getElementById('mobileBackdrop');
    const mobileNavItems = document.querySelectorAll('.mobile-nav-item');

    function openMenu() {
        if (mobileDrawer) mobileDrawer.classList.add('open');
        if (mobileBackdrop) mobileBackdrop.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeMenu() {
        if (mobileDrawer) mobileDrawer.classList.remove('open');
        if (mobileBackdrop) mobileBackdrop.classList.remove('open');
        document.body.style.overflow = '';
    }

    if (mobileToggleBtn) mobileToggleBtn.addEventListener('click', openMenu);
    if (mobileDrawerClose) mobileDrawerClose.addEventListener('click', closeMenu);
    if (mobileBackdrop) mobileBackdrop.addEventListener('click', closeMenu);
    mobileNavItems.forEach(item => item.addEventListener('click', closeMenu));

    // 3. Code Block Copy to Clipboard
    document.querySelectorAll('.docs-code-copy, .terminal-copy').forEach(btn => {
        btn.addEventListener('click', () => {
            const container = btn.closest('.docs-code-box, .terminal-container');
            if (!container) return;
            const codeEl = container.querySelector('.docs-code-body code, .terminal-body');
            if (!codeEl) return;

            const textToCopy = codeEl.innerText.trim();
            navigator.clipboard.writeText(textToCopy).then(() => {
                const orig = btn.innerText;
                btn.innerText = 'Copied!';
                btn.style.color = '#34d399';
                setTimeout(() => {
                    btn.innerText = orig;
                    btn.style.color = '';
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy: ', err);
            });
        });
    });

    // 4. Sidebar Search / Quick Filter
    const sidebarSearch = document.getElementById('sidebarSearch');
    if (sidebarSearch) {
        sidebarSearch.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            const menuLinks = document.querySelectorAll('.sidebar-menu a');
            const groups = document.querySelectorAll('.sidebar-group');

            menuLinks.forEach(link => {
                const text = link.innerText.toLowerCase();
                const match = text.includes(query);
                link.parentElement.style.display = match ? 'block' : 'none';
            });

            groups.forEach(group => {
                const visibleLinks = group.querySelectorAll('.sidebar-menu li:not([style*="display: none"])');
                group.style.display = (visibleLinks.length > 0) ? 'block' : 'none';
            });
        });
    }

    // 5. Table of Contents Scrollspy
    const tocLinks = document.querySelectorAll('.toc-list a');
    if (tocLinks.length > 0) {
        const sections = Array.from(tocLinks).map(link => {
            const targetId = link.getAttribute('href').replace('#', '');
            return document.getElementById(targetId);
        }).filter(Boolean);

        window.addEventListener('scroll', () => {
            let current = '';
            const scrollPos = window.scrollY + 120;

            sections.forEach(section => {
                if (section.offsetTop <= scrollPos) {
                    current = section.getAttribute('id');
                }
            });

            tocLinks.forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('href') === '#' + current) {
                    link.classList.add('active');
                }
            });
        }, { passive: true });
    }

    // GeoIP-based language detection via Cloudflare
    if (!localStorage.getItem('aipbx_lang')) {
        fetch('/cdn-cgi/trace')
            .then(function(r) { return r.text(); })
            .then(function(t) {
                var m = t.match(/loc=([A-Z]{2})/);
                if (m) {
                    var cc = m[1];
                    var geoLang = 'en';
                    if (cc === 'TR' || cc === 'AZ') geoLang = 'tr';
                    else if (cc === 'AT' || cc === 'DE' || cc === 'CH' || cc === 'LI') geoLang = 'de';
                    var current = document.documentElement.getAttribute('data-lang');
                    if (current !== geoLang) {
                        setLanguage(geoLang);
                    }
                }
            })
            .catch(function() {});
    }

});
