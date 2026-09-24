<?php
/**
 * AiPBX.bid — SEO & Structured Data Helper
 * Generates dynamic Canonical, Hreflang, OpenGraph, Twitter Cards, and Schema.org JSON-LD.
 */

global $LANG, $company, $hero, $flagshipFeatures, $faq, $tables;

// Localized SEO Titles & Descriptions
$seoTitles = [
    'tr' => 'AiPBX — Açık Kaynak Kurumsal IP Santral & PBX Telefon Sistemi | Asterisk 22 & WebRTC',
    'en' => 'AiPBX — Open-Source Enterprise IP PBX & Telephony Platform | Asterisk 22 & WebRTC',
    'de' => 'AiPBX — Open-Source Enterprise IP-Telefonanlage & PBX-System | Asterisk 22 & WebRTC',
];

$seoDescriptions = [
    'tr' => 'AiPBX; Asterisk 22, Nginx L4 ALPN stream çoklama, WebRTC, Passkey biyometrik oturum açma, Go sohbet motoru ve yerel Android/iOS mobil istemcileri birleştiren kurumsal açık kaynak IP santral platformudur.',
    'en' => 'AiPBX is an open-source enterprise IP PBX uniting Asterisk 22, Port 443 Nginx L4 ALPN stream multiplexing, WebRTC, Passkeys, Go chat daemon, and native Android & iOS softphone apps.',
    'de' => 'AiPBX ist eine Open-Source Enterprise IP-Telefonanlage mit Asterisk 22, Port 443 ALPN Multiplexing, WebRTC, Passkeys, Go-Chat-Engine und nativen Mobil-Softphones für Android & iOS.',
];

$seoKeywords = [
    'tr' => 'açık kaynak ip santral, kurumsal santral, kurumsal telefon santrali, asterisk 22 santral, webrtc santral, bulut santral, voip santral, ip pbx, softphone indir, çağrı merkezi santrali, mahir gül, passkey santral, microsoft teams direct routing santral, open source ip pbx, enterprise voip, asterisk webrtc',
    'en' => 'open source ip pbx, enterprise pbx, corporate voip, asterisk 22 pbx, webrtc softphone, cloud pbx, ip telephony, mahir gul, passkey pbx, microsoft teams direct routing pbx, alpn multiplexing, native android pbx, open source telecom',
    'de' => 'open source ip pbx, enterprise telefonanlage, asterisk 22 telefonanlage, webrtc pbx, voip telefonanlage, mahir gul, passkey pbx, microsoft teams direct routing, alpn multiplexing, native mobil softphone',
];

$locales = [
    'tr' => 'tr_TR',
    'en' => 'en_US',
    'de' => 'de_DE',
];

$currentTitle = $seoTitles[$LANG] ?? $seoTitles['tr'];
$currentDesc  = $seoDescriptions[$LANG] ?? $seoDescriptions['tr'];
$currentKw    = $seoKeywords[$LANG] ?? $seoKeywords['tr'];
$currentLocale = $locales[$LANG] ?? 'tr_TR';

$canonicalBase = 'https://aipbx.bid';
$canonicalUrl  = ($LANG === 'tr') ? $canonicalBase . '/' : $canonicalBase . '/?lang=' . $LANG;
?>

<!-- Primary Meta Tags -->
<title><?= htmlspecialchars($currentTitle) ?></title>
<meta name="title" content="<?= htmlspecialchars($currentTitle) ?>">
<meta name="description" content="<?= htmlspecialchars($currentDesc) ?>">
<meta name="keywords" content="<?= htmlspecialchars($currentKw) ?>">
<meta name="author" content="Mahir Gül (mhrgl.com)">
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
<meta name="googlebot" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
<meta name="bingbot" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
<meta name="rating" content="General">
<meta name="revisit-after" content="3 days">

<!-- Canonical & Hreflang Tags -->
<link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>">
<link rel="alternate" hreflang="tr" href="https://aipbx.bid/">
<link rel="alternate" hreflang="en" href="https://aipbx.bid/?lang=en">
<link rel="alternate" hreflang="de" href="https://aipbx.bid/?lang=de">
<link rel="alternate" hreflang="x-default" href="https://aipbx.bid/">

<!-- Open Graph / Facebook / LinkedIn / WhatsApp -->
<meta property="og:site_name" content="AiPBX — Modern Open Source Enterprise IP Telephony">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= htmlspecialchars($canonicalUrl) ?>">
<meta property="og:title" content="<?= htmlspecialchars($currentTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($currentDesc) ?>">
<meta property="og:image" content="https://aipbx.bid/img/feature_graphic.png">
<meta property="og:image:secure_url" content="https://aipbx.bid/img/feature_graphic.png">
<meta property="og:image:width" content="1024">
<meta property="og:image:height" content="500">
<meta property="og:image:type" content="image/png">
<meta property="og:image:alt" content="AiPBX Açık Kaynak Kurumsal IP Santral">
<meta property="og:locale" content="<?= htmlspecialchars($currentLocale) ?>">
<?php foreach ($locales as $lKey => $lVal): if ($lKey !== $LANG): ?>
<meta property="og:locale:alternate" content="<?= htmlspecialchars($lVal) ?>">
<?php endif; endforeach; ?>

<!-- Twitter / X Card -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:site" content="@mhrgl">
<meta name="twitter:creator" content="@mhrgl">
<meta name="twitter:url" content="<?= htmlspecialchars($canonicalUrl) ?>">
<meta name="twitter:title" content="<?= htmlspecialchars($currentTitle) ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($currentDesc) ?>">
<meta name="twitter:image" content="https://aipbx.bid/img/feature_graphic.png">
<meta name="twitter:image:alt" content="AiPBX Kurumsal IP Santral Platformu">

<!-- Schema.org Comprehensive Structured Data (JSON-LD) -->
<script type="application/ld+json">
<?php
// Build FAQ Schema Entities in active language
$faqEntities = [];
if (!empty($faq)) {
    foreach ($faq as $item) {
        $q = getLocal($item, 'q');
        $a = getLocal($item, 'a');
        if ($q && $a) {
            $faqEntities[] = [
                '@type' => 'Question',
                'name'  => $q,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $a
                ]
            ];
        }
    }
}

// Build Feature ItemList
$featureListItems = [];
if (!empty($flagshipFeatures)) {
    foreach ($flagshipFeatures as $idx => $feat) {
        $featureListItems[] = [
            '@type'     => 'ListItem',
            'position' => $idx + 1,
            'name'     => getLocal($feat, 'title'),
            'description' => getLocal($feat, 'desc')
        ];
    }
}

$schemaGraph = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type' => 'SoftwareApplication',
            '@id'   => 'https://aipbx.bid/#software',
            'name'  => 'AiPBX',
            'alternateName' => [
                'AiPBX IP Santral',
                'AiPBX Enterprise IP PBX',
                'AiPBX Telefon Santrali',
                'AiPBX Open Source PBX'
            ],
            'applicationCategory' => 'BusinessApplication, TelecommunicationsApplication',
            'operatingSystem' => 'Linux (Debian 12, Ubuntu 24.04/22.04 LTS), Android, iOS, Web',
            'offers' => [
                '@type'         => 'Offer',
                'price'         => '0',
                'priceCurrency' => 'USD',
                'availability'  => 'https://schema.org/InStock'
            ],
            'description' => $currentDesc,
            'softwareVersion' => $company['version'] ?? '1.0.34 LTS',
            'license' => 'https://github.com/mahirgul/AiPBX/blob/main/LICENSE',
            'url'     => 'https://aipbx.bid/',
            'downloadUrl' => 'https://aipbx.bid/mobile-apps.html',
            'author'  => [
                '@type' => 'Person',
                'name'  => 'Mahir Gül',
                'url'   => 'https://mhrgl.com'
            ],
            'screenshot' => 'https://aipbx.bid/img/feature_graphic.png',
            'featureList' => array_column($featureListItems, 'name')
        ],
        [
            '@type' => 'Organization',
            '@id'   => 'https://aipbx.bid/#organization',
            'name'  => 'AiPBX',
            'url'   => 'https://aipbx.bid/',
            'logo'  => [
                '@type'   => 'ImageObject',
                'url'     => 'https://aipbx.bid/logo.png',
                'caption' => 'AiPBX Logo'
            ],
            'founder' => [
                '@type' => 'Person',
                'name'  => 'Mahir Gül',
                'url'   => 'https://mhrgl.com'
            ],
            'sameAs'  => [
                'https://github.com/mahirgul/AiPBX',
                'https://mhrgl.com'
            ]
        ],
        [
            '@type' => 'WebSite',
            '@id'   => 'https://aipbx.bid/#website',
            'url'   => 'https://aipbx.bid/',
            'name'  => 'AiPBX — Modern Open Source Enterprise IP Telephony',
            'description' => $currentDesc,
            'inLanguage' => ['tr', 'en', 'de'],
            'publisher'  => [
                '@id' => 'https://aipbx.bid/#organization'
            ]
        ],
        [
            '@type' => 'BreadcrumbList',
            '@id'   => 'https://aipbx.bid/#breadcrumb',
            'itemListElement' => [
                [
                    '@type'    => 'ListItem',
                    'position' => 1,
                    'name'     => ($LANG === 'de') ? 'Startseite' : (($LANG === 'en') ? 'Home' : 'Ana Sayfa'),
                    'item'     => $canonicalUrl
                ]
            ]
        ]
    ]
];

if (!empty($faqEntities)) {
    $schemaGraph['@graph'][] = [
        '@type'      => 'FAQPage',
        '@id'        => 'https://aipbx.bid/#faq',
        'mainEntity' => $faqEntities
    ];
}

if (!empty($featureListItems)) {
    $schemaGraph['@graph'][] = [
        '@type'           => 'ItemList',
        '@id'             => 'https://aipbx.bid/#features-list',
        'name'            => ($LANG === 'de') ? 'AiPBX Kerntechnologien' : (($LANG === 'en') ? 'AiPBX Core Technologies' : 'AiPBX Çekirdek Teknolojileri'),
        'itemListElement' => $featureListItems
    ];
}

echo json_encode($schemaGraph, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
</script>
