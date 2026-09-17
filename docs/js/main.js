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

// 2. Language Management (TR / EN / DE)
function setLanguage(lang, isAuto = false) {
    if (!['tr', 'en', 'de'].includes(lang)) lang = 'en';

    document.documentElement.setAttribute('data-lang', lang);
    document.documentElement.lang = lang;

    if (!isAuto) {
        localStorage.setItem('aipbx_lang', lang);
    }

    // Update Desktop Button text & flag
    const flagEl = document.getElementById('currentLangFlag');
    const codeEl = document.getElementById('currentLangCode');
    const flags = { tr: '🇹🇷', en: '🇬🇧', de: '🇩🇪' };
    const codes = { tr: 'TR', en: 'EN', de: 'DE' };

    if (flagEl) flagEl.innerText = flags[lang] || '🌐';
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
    // 1. Language Initialization & Auto-Detection
    const savedLang = localStorage.getItem('aipbx_lang');
    if (savedLang) {
        setLanguage(savedLang);
    } else {
        const navLangs = navigator.languages || [navigator.language || navigator.userLanguage || 'en'];
        let detected = 'en';
        for (const l of navLangs) {
            const clean = (l || '').toLowerCase();
            if (clean.startsWith('tr')) { detected = 'tr'; break; }
            if (clean.startsWith('de')) { detected = 'de'; break; }
        }
        setLanguage(detected, true);

        // Optional Geo-IP hint in background
        try {
            fetch('https://ipapi.co/json/')
                .then(r => r.json())
                .then(data => {
                    if (!localStorage.getItem('aipbx_lang') && data && data.country_code) {
                        const cc = data.country_code.toUpperCase();
                        if (['TR', 'AZ'].includes(cc)) setLanguage('tr', true);
                        else if (['DE', 'AT', 'CH'].includes(cc)) setLanguage('de', true);
                    }
                }).catch(() => {});
        } catch(e) {}
    }

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
});
