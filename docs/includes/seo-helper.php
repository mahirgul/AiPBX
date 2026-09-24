<?php
/**
 * AiPBX.bid — Universal SEO Helper (Tüm sayfalar için)
 * Her sayfada $page değişkeni set edilmiş olmalı.
 * Merkezi SEO veri tablosu — kolayca güncellenebilir.
 */

$pageSeoMap = [
    'index' => [
        'tr' => [
            'title'    => 'AiPBX — Açık Kaynak Kurumsal IP Santral | Asterisk 22 + Port 443 ALPN',
            'desc'     => 'AiPBX: Asterisk 22, Port 443 ALPN stream çoklama, WebRTC, Passkey biyometrik kimlik, Go anlık sohbet ve native Android/iOS softphone barındıran açık kaynak kurumsal IP santral.',
            'keywords' => 'AiPBX, açık kaynak IP santral, Asterisk 22, WebRTC santral, Port 443 SIP, kurumsal telekomünikasyon, passkey kimlik doğrulama, VoIP çözümü Türkiye',
        ],
        'en' => [
            'title'    => 'AiPBX — Open Source Enterprise IP PBX | Asterisk 22 + Port 443 ALPN',
            'desc'     => 'AiPBX: Open source enterprise IP PBX uniting Asterisk 22, Port 443 ALPN stream multiplexing, WebRTC, Passkeys, Go IM engine and native Android/iOS softphones.',
            'keywords' => 'AiPBX, open source IP PBX, Asterisk 22, WebRTC PBX, Port 443 SIP, enterprise telephony, passkey authentication, VoIP solution',
        ],
        'de' => [
            'title'    => 'AiPBX — Open Source Enterprise IP-Telefonanlage | Asterisk 22 + Port 443 ALPN',
            'desc'     => 'AiPBX: Enterprise IP-TK-Anlage mit Asterisk 22, Port 443 ALPN-Multiplexing, WebRTC, biometrischen Passkeys, Go-Echtzeit-Chat und nativen Mobil-Softphones.',
            'keywords' => 'AiPBX, Open Source IP TK-Anlage, Asterisk 22, WebRTC Telefonie, Port 443 SIP, Enterprise Kommunikation, Passkey Authentifizierung',
        ],
        'slug' => '/',
    ],
    'features' => [
        'tr' => [
            'title'    => 'Santral Özellikleri & Modüller — AiPBX Kurumsal IP Santral',
            'desc'     => 'AiPBX kurumsal santral modülleri: Port 443 ALPN, Dahili Sohbet, Görsel CDR (LinkedID), Konferans, Süpervizör Dinleme (*90), Çalma Grupları ve MS Teams SBC.',
            'keywords' => 'AiPBX özellikler, IP santral modülleri, ALPN SIP, ChanSpy, ConfBridge, CDR LinkedID, Ring Groups, MS Teams SBC, Boss-Secretary',
        ],
        'en' => [
            'title'    => 'Enterprise Features & Modules — AiPBX IP PBX',
            'desc'     => 'AiPBX telephony suite: Port 443 ALPN, In-Dialer Chat, Visual CDR (LinkedID), Conferences, Supervisor Spy (*90), Ring Groups and MS Teams SBC.',
            'keywords' => 'AiPBX features, IP PBX modules, ALPN SIP, ChanSpy, ConfBridge, CDR LinkedID, Ring Groups, MS Teams SBC, Boss-Secretary',
        ],
        'de' => [
            'title'    => 'Funktionen & Kernmodule — AiPBX Enterprise IP-Telefonanlage',
            'desc'     => 'AiPBX Telefonie-Suite: Port 443 ALPN, In-App-Chat, Visuelles CDR, Konferenz, Supervisor Mithören (*90), Rufgruppen und MS Teams SBC.',
            'keywords' => 'AiPBX Funktionen, IP TK-Anlage Module, ALPN SIP, ChanSpy, ConfBridge, CDR LinkedID, Rufgruppen, MS Teams SBC',
        ],
        'slug' => '/features.html',
    ],
    'architecture' => [
        'tr' => [
            'title'    => 'Sistem Mimarisi & ALPN Çoklama — Asterisk 22, Nginx L4, WebRTC | AiPBX',
            'desc'     => 'AiPBX katmanlı sistem mimarisi: Port 443 ALPN stream çoklama (HTTP/2, WebRTC, SIP/TLS), PJSIP çift uç nokta, Go WebSocket anlık mesajlaşma, iki katmanlı MariaDB.',
            'keywords' => 'AiPBX mimari, ALPN stream çoklama, Nginx L4 SIP, WebRTC Asterisk, PJSIP, MariaDB iki veritabanı, Go WebSocket chat',
        ],
        'en' => [
            'title'    => 'System Architecture & ALPN Multiplexing — Asterisk 22, Nginx L4, WebRTC | AiPBX',
            'desc'     => 'AiPBX layered architecture: Port 443 ALPN stream multiplexing (HTTP/2, WebRTC, SIP/TLS), PJSIP dual endpoint, Go WebSocket IM, dual-database MariaDB model.',
            'keywords' => 'AiPBX architecture, ALPN stream multiplexing, Nginx L4 SIP, WebRTC Asterisk, PJSIP dual endpoint, MariaDB dual database, Go WebSocket chat',
        ],
        'de' => [
            'title'    => 'Systemarchitektur & ALPN-Multiplexing — Asterisk 22, Nginx L4, WebRTC | AiPBX',
            'desc'     => 'AiPBX Schichtarchitektur: Port 443 ALPN-Multiplexing (HTTP/2, WebRTC, SIP/TLS), PJSIP Dual-Endpunkt, Go WebSocket-Chat, Zwei-Datenbank-MariaDB-Modell.',
            'keywords' => 'AiPBX Architektur, ALPN Stream-Multiplexing, Nginx L4 SIP, WebRTC Asterisk, PJSIP, MariaDB Dual-Datenbank, Go WebSocket Chat',
        ],
        'slug' => '/architecture.html',
    ],
    'callcenter' => [
        'tr' => [
            'title'    => 'Çağrı Merkezi Çözümleri — ACD Kuyruk, Mola Kodları (*22/*23), Wallboard | AiPBX',
            'desc'     => 'AiPBX çağrı merkezi: Statik/dinamik ACD kuyruk, *22 mola ve *23 moladan dönüş, anlık wallboard, *90 Spy/Whisper/Barge süpervizör dinleme.',
            'keywords' => 'AiPBX çağrı merkezi, ACD kuyruk, mola kodu *22, wallboard, ChanSpy *90, süpervizör dinleme, çağrı merkezi Asterisk',
        ],
        'en' => [
            'title'    => 'Call Center Solutions — ACD Queue, Break Codes (*22/*23), Wallboard | AiPBX',
            'desc'     => 'AiPBX call center: Static/dynamic ACD queues, *22 break and *23 resume, live wallboard, *90 Spy/Whisper/Barge supervisor monitoring.',
            'keywords' => 'AiPBX call center, ACD queue, break code *22, wallboard, ChanSpy *90, supervisor monitoring, Asterisk call center',
        ],
        'de' => [
            'title'    => 'Callcenter-Lösungen — ACD-Warteschlange, Pausencodes (*22/*23), Wallboard | AiPBX',
            'desc'     => 'AiPBX Callcenter: Statische/dynamische ACD-Warteschlangen, *22 Pause und *23 Rückkehr, Live-Wallboard, *90 Spy/Whisper/Barge Supervisor-Überwachung.',
            'keywords' => 'AiPBX Callcenter, ACD Warteschlange, Pausencode *22, Wallboard, ChanSpy *90, Supervisor Mithören, Asterisk Callcenter',
        ],
        'slug' => '/callcenter.html',
    ],
    'mobile-apps' => [
        'tr' => [
            'title'    => 'Mobil Softphone — Android & iOS Uygulaması | AiPBX',
            'desc'     => 'AiPBX native Android (Kotlin) ve iOS (Swift) softphone: WebRTC Opus ses/video, FCM/APNs push, dahili sohbet, G.729 ve Turnkey App kurulumu.',
            'keywords' => 'AiPBX mobil softphone, Android VoIP uygulaması, iOS softphone, WebRTC mobile, FCM push bildirim, PJSIP Android, kurumsal softphone',
        ],
        'en' => [
            'title'    => 'Mobile Softphone — Android & iOS App | AiPBX',
            'desc'     => 'AiPBX native Android (Kotlin) & iOS (Swift) softphone: WebRTC Opus audio/video, FCM/APNs push, in-dialer chat, G.729 and Turnkey App installation.',
            'keywords' => 'AiPBX mobile softphone, Android VoIP app, iOS softphone, WebRTC mobile, FCM push notification, PJSIP Android, enterprise softphone',
        ],
        'de' => [
            'title'    => 'Mobiles Softphone — Android & iOS App | AiPBX',
            'desc'     => 'AiPBX natives Android (Kotlin) & iOS (Swift) Softphone: WebRTC Opus Audio/Video, FCM/APNs Push, In-Dialer-Chat, G.729 und Turnkey App-Installation.',
            'keywords' => 'AiPBX mobiles Softphone, Android VoIP App, iOS Softphone, WebRTC mobil, FCM Push-Benachrichtigung, PJSIP Android, Enterprise Softphone',
        ],
        'slug' => '/mobile-apps.html',
    ],
    'msteams' => [
        'tr' => [
            'title'    => 'Microsoft Teams Direct Routing & SBC Entegrasyonu | AiPBX',
            'desc'     => 'AiPBX MS Teams Direct Routing: SIP TLS 5061, DigiCert/Baltimore CA, New-CsOnlinePSTNGateway PowerShell, PJSIP trunk ve Asterisk REFER transfer desteği.',
            'keywords' => 'AiPBX Microsoft Teams, Direct Routing SBC, SIP TLS Teams, CsOnlinePSTNGateway, Asterisk Teams entegrasyonu, kurumsal Teams sesli arama',
        ],
        'en' => [
            'title'    => 'Microsoft Teams Direct Routing & SBC Integration | AiPBX',
            'desc'     => 'AiPBX MS Teams Direct Routing: SIP TLS 5061, DigiCert/Baltimore CA, New-CsOnlinePSTNGateway PowerShell, PJSIP trunk and Asterisk REFER transfer support.',
            'keywords' => 'AiPBX Microsoft Teams, Direct Routing SBC, SIP TLS Teams, CsOnlinePSTNGateway, Asterisk Teams integration, enterprise Teams voice',
        ],
        'de' => [
            'title'    => 'Microsoft Teams Direct Routing & SBC-Integration | AiPBX',
            'desc'     => 'AiPBX MS Teams Direct Routing: SIP TLS 5061, DigiCert/Baltimore CA, New-CsOnlinePSTNGateway PowerShell, PJSIP Trunk und Asterisk REFER Transfer.',
            'keywords' => 'AiPBX Microsoft Teams, Direct Routing SBC, SIP TLS Teams, CsOnlinePSTNGateway, Asterisk Teams Integration, Enterprise Teams Sprachanrufe',
        ],
        'slug' => '/msteams.html',
    ],
    'security' => [
        'tr' => [
            'title'    => 'Güvenlik & SBC Koruması — FIDO2 Passkey, TLS 1.3, SRTP | AiPBX',
            'desc'     => 'AiPBX güvenlik katmanı: FIDO2/WebAuthn Passkey, Google OAuth 2.0, TLS 1.3, SRTP AES_CM_128, Fail2ban SIP koruması ve Session Border Controller.',
            'keywords' => 'AiPBX güvenlik, FIDO2 passkey, WebAuthn VoIP, TLS 1.3 SIP, SRTP şifreleme, Fail2ban Asterisk, SBC koruması, VoIP güvenlik',
        ],
        'en' => [
            'title'    => 'Security & SBC Protection — FIDO2 Passkey, TLS 1.3, SRTP | AiPBX',
            'desc'     => 'AiPBX security layer: FIDO2/WebAuthn Passkeys, Google OAuth 2.0, TLS 1.3, SRTP AES_CM_128, Fail2ban SIP protection and Session Border Controller.',
            'keywords' => 'AiPBX security, FIDO2 passkey, WebAuthn VoIP, TLS 1.3 SIP, SRTP encryption, Fail2ban Asterisk, SBC protection, VoIP security',
        ],
        'de' => [
            'title'    => 'Sicherheit & SBC-Schutz — FIDO2 Passkey, TLS 1.3, SRTP | AiPBX',
            'desc'     => 'AiPBX Sicherheitsschicht: FIDO2/WebAuthn Passkeys, Google OAuth 2.0, TLS 1.3, SRTP AES_CM_128, Fail2ban SIP-Schutz und Session Border Controller.',
            'keywords' => 'AiPBX Sicherheit, FIDO2 Passkey, WebAuthn VoIP, TLS 1.3 SIP, SRTP Verschlüsselung, Fail2ban Asterisk, SBC Schutz, VoIP Sicherheit',
        ],
        'slug' => '/security.html',
    ],
    'api-docs' => [
        'tr' => [
            'title'    => 'REST API & WebSocket Dokümantasyonu | AiPBX Geliştirici Kılavuzu',
            'desc'     => 'AiPBX REST API ve WebSocket entegrasyon kılavuzu: JWT kimlik doğrulama, uzantı yönetimi, gerçek zamanlı olaylar, CTI ve softphone geliştirici referans dokümantasyonu.',
            'keywords' => 'AiPBX API, REST API PBX, WebSocket VoIP, JWT kimlik doğrulama santral, CTI entegrasyonu, Asterisk API, VoIP geliştirici kılavuzu',
        ],
        'en' => [
            'title'    => 'REST API & WebSocket Documentation | AiPBX Developer Guide',
            'desc'     => 'AiPBX REST API & WebSocket integration guide: JWT authentication, extension management, real-time events, CTI integration and softphone developer reference.',
            'keywords' => 'AiPBX API, REST API PBX, WebSocket VoIP, JWT authentication PBX, CTI integration, Asterisk API, VoIP developer guide',
        ],
        'de' => [
            'title'    => 'REST API & WebSocket Dokumentation | AiPBX Entwicklerhandbuch',
            'desc'     => 'AiPBX REST API & WebSocket Integrationsleitfaden: JWT-Authentifizierung, Nebenstellen-Verwaltung, Echtzeit-Events, CTI-Integration und Softphone-Entwicklerreferenz.',
            'keywords' => 'AiPBX API, REST API TK-Anlage, WebSocket VoIP, JWT Authentifizierung, CTI Integration, Asterisk API, VoIP Entwicklerhandbuch',
        ],
        'slug' => '/api-docs.html',
    ],
    'installation' => [
        'tr' => [
            'title'    => 'Kurulum Kılavuzu — Ubuntu/Debian Turnkey Installer | AiPBX',
            'desc'     => 'AiPBX tam otomatik kurulum: Ubuntu 22.04/24.04 ve Debian 12 için tek satır Turnkey installer, git clone kaynak derleme ve adım adım yapılandırma kılavuzu.',
            'keywords' => 'AiPBX kurulum, Ubuntu Asterisk kurulum, Debian VoIP kurulum, Turnkey PBX installer, Asterisk 22 kurulum, IP santral kurulumu, tek satır PBX',
        ],
        'en' => [
            'title'    => 'Installation Guide — Ubuntu/Debian Turnkey Installer | AiPBX',
            'desc'     => 'AiPBX fully automated installation: One-liner Turnkey installer for Ubuntu 22.04/24.04 and Debian 12, git clone source build and step-by-step configuration guide.',
            'keywords' => 'AiPBX installation, Ubuntu Asterisk install, Debian VoIP install, Turnkey PBX installer, Asterisk 22 setup, IP PBX installation, one-liner PBX',
        ],
        'de' => [
            'title'    => 'Installationsanleitung — Ubuntu/Debian Turnkey Installer | AiPBX',
            'desc'     => 'AiPBX vollautomatische Installation: Einzeilen-Turnkey-Installer für Ubuntu 22.04/24.04 und Debian 12, Git-Clone-Quellbuild und schrittweise Konfigurationsanleitung.',
            'keywords' => 'AiPBX Installation, Ubuntu Asterisk installieren, Debian VoIP installieren, Turnkey TK-Anlage Installer, Asterisk 22 Setup, IP TK-Anlage Installation',
        ],
        'slug' => '/installation.html',
    ],
    'tables' => [
        'tr' => [
            'title'    => 'Karşılaştırma & Teknik Tablolar — Özellik Matrisi | AiPBX',
            'desc'     => 'AiPBX özellik karşılaştırma tabloları: Codec desteği (G.711, G.729, Opus, H.264), yıldız kodları (*97, *22, *90), Asterisk modülleri ve güvenlik protokolleri.',
            'keywords' => 'AiPBX karşılaştırma tablosu, IP santral özellik matrisi, Asterisk codec listesi, VoIP yıldız kodları, Asterisk modülleri, PBX protokol desteği',
        ],
        'en' => [
            'title'    => 'Comparison & Technical Specs — Feature Matrix | AiPBX',
            'desc'     => 'AiPBX feature comparison tables: Codec support (G.711, G.729, Opus, H.264), star codes (*97, *22, *90), Asterisk modules and security protocols.',
            'keywords' => 'AiPBX comparison table, IP PBX feature matrix, Asterisk codec list, VoIP star codes, Asterisk modules, PBX protocol support',
        ],
        'de' => [
            'title'    => 'Vergleich & Technische Spezifikationen — Funktionsmatrix | AiPBX',
            'desc'     => 'AiPBX Funktionsvergleichstabellen: Codec-Unterstützung (G.711, G.729, Opus, H.264), Sternbefehle (*97, *22, *90), Asterisk-Module und Sicherheitsprotokolle.',
            'keywords' => 'AiPBX Vergleichstabelle, IP TK-Anlage Funktionsmatrix, Asterisk Codec-Liste, VoIP Sternbefehle, Asterisk Module, TK-Anlage Protokollunterstützung',
        ],
        'slug' => '/tables.html',
    ],
];

// Aktif sayfa belirle ($page değişkeni PHP sayfasında set edilmeli)
$pageKey = $page ?? 'index';
if (!isset($pageSeoMap[$pageKey])) {
    $pageKey = 'index';
}

$seoData    = $pageSeoMap[$pageKey][$LANG] ?? $pageSeoMap[$pageKey]['tr'];
$seoTitle   = $seoData['title'];
$seoDesc    = $seoData['desc'];
$seoKw      = $seoData['keywords'];
$seoSlug    = $pageSeoMap[$pageKey]['slug'] ?? '/';
$canonicalBase = 'https://aipbx.bid';
$canonicalTR   = $canonicalBase . $seoSlug;
$canonicalEN   = $canonicalBase . $seoSlug . '?lang=en';
$canonicalDE   = $canonicalBase . $seoSlug . '?lang=de';
$canonical     = ($LANG === 'en') ? $canonicalEN : (($LANG === 'de') ? $canonicalDE : $canonicalTR);

$locales = ['tr' => 'tr_TR', 'en' => 'en_US', 'de' => 'de_DE'];
$currentLocale = $locales[$LANG] ?? 'tr_TR';
?>

<!-- Primary Meta Tags -->
<title><?= htmlspecialchars($seoTitle) ?></title>
<meta name="title" content="<?= htmlspecialchars($seoTitle) ?>">
<meta name="description" content="<?= htmlspecialchars($seoDesc) ?>">
<meta name="keywords" content="<?= htmlspecialchars($seoKw) ?>">
<meta name="author" content="Mahir Gül (mhrgl.com)">
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
<meta name="googlebot" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
<meta name="rating" content="General">
<meta name="revisit-after" content="3 days">

<!-- Canonical & Hreflang -->
<link rel="canonical" href="<?= htmlspecialchars($canonical) ?>">
<link rel="alternate" hreflang="tr" href="<?= htmlspecialchars($canonicalTR) ?>">
<link rel="alternate" hreflang="en" href="<?= htmlspecialchars($canonicalEN) ?>">
<link rel="alternate" hreflang="de" href="<?= htmlspecialchars($canonicalDE) ?>">
<link rel="alternate" hreflang="x-default" href="<?= htmlspecialchars($canonicalTR) ?>">

<!-- Open Graph -->
<meta property="og:site_name" content="AiPBX — Modern Open Source Enterprise IP Telephony">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= htmlspecialchars($canonical) ?>">
<meta property="og:title" content="<?= htmlspecialchars($seoTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($seoDesc) ?>">
<meta property="og:image" content="https://aipbx.bid/logo.png">
<meta property="og:image:width" content="512">
<meta property="og:image:height" content="512">
<meta property="og:image:type" content="image/png">
<meta property="og:locale" content="<?= htmlspecialchars($currentLocale) ?>">
<?php foreach ($locales as $lKey => $lVal): if ($lKey !== $LANG): ?>
<meta property="og:locale:alternate" content="<?= htmlspecialchars($lVal) ?>">
<?php endif; endforeach; ?>

<!-- Twitter Card -->
<meta name="twitter:card" content="summary">
<meta name="twitter:site" content="@mhrgl">
<meta name="twitter:creator" content="@mhrgl">
<meta name="twitter:title" content="<?= htmlspecialchars($seoTitle) ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($seoDesc) ?>">
<meta name="twitter:image" content="https://aipbx.bid/logo.png">

<!-- Schema.org JSON-LD -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "SoftwareApplication",
      "@id": "https://aipbx.bid/#software",
      "name": "AiPBX",
      "applicationCategory": "BusinessApplication,TelecommunicationsApplication",
      "operatingSystem": "Linux (Debian 12, Ubuntu 24.04/22.04 LTS), Android, iOS, Web",
      "offers": {"@type":"Offer","price":"0","priceCurrency":"USD","availability":"https://schema.org/InStock"},
      "description": <?= json_encode($seoDesc) ?>,
      "softwareVersion": <?= json_encode($company['version'] ?? '1.0.34 LTS') ?>,
      "license": "https://github.com/mahirgul/AiPBX/blob/main/LICENSE",
      "url": "https://aipbx.bid/",
      "author": {"@type":"Person","name":"Mahir Gül","url":"https://mhrgl.com"}
    },
    {
      "@type": "WebSite",
      "@id": "https://aipbx.bid/#website",
      "url": "https://aipbx.bid/",
      "name": "AiPBX",
      "description": <?= json_encode($seoDesc) ?>,
      "inLanguage": ["tr","en","de"]
    },
    {
      "@type": "BreadcrumbList",
      "itemListElement": [
        {"@type":"ListItem","position":1,"name":<?= json_encode($LANG==='en'?'Home':($LANG==='de'?'Startseite':'Ana Sayfa')) ?>,"item":"https://aipbx.bid/"}
        <?php if ($pageKey !== 'index'): ?>,
        {"@type":"ListItem","position":2,"name":<?= json_encode($seoTitle) ?>,"item":<?= json_encode($canonical) ?>}
        <?php endif; ?>
      ]
    }
  ]
}
</script>
