<?php
/**
 * AiPBX.bid — Universal Head Include
 * Preloads fonts, stylesheets, favicons, and language cloak.
 */
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<meta name="theme-color" content="#0f172a">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="AiPBX">
<link rel="manifest" href="/site.webmanifest">

<!-- Favicons -->
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

<!-- Preconnects & Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

<!-- Font Awesome 6.5.1 -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />

<!-- Core Stylesheets -->
<link rel="stylesheet" href="/css/style.css">
<link rel="stylesheet" href="/css/tables.css">

<!-- Zero-Flicker Multilingual Cloak & Client Sync -->
<style id="lang-cloak">
    html[data-lang="tr"] [data-lang]:not([data-lang="tr"]),
    html[data-lang="en"] [data-lang]:not([data-lang="en"]),
    html[data-lang="de"] [data-lang]:not([data-lang="de"]) { display: none !important; }
    html[data-lang="tr"] span[data-lang="tr"], html[data-lang="tr"] a[data-lang="tr"], html[data-lang="tr"] strong[data-lang="tr"], html[data-lang="tr"] em[data-lang="tr"], html[data-lang="tr"] code[data-lang="tr"],
    html[data-lang="en"] span[data-lang="en"], html[data-lang="en"] a[data-lang="en"], html[data-lang="en"] strong[data-lang="en"], html[data-lang="en"] em[data-lang="en"], html[data-lang="en"] code[data-lang="en"],
    html[data-lang="de"] span[data-lang="de"], html[data-lang="de"] a[data-lang="de"], html[data-lang="de"] strong[data-lang="de"], html[data-lang="de"] em[data-lang="de"], html[data-lang="de"] code[data-lang="de"] { display: inline !important; }
</style>
<script>
    (function() {
        var currentLang = <?= json_encode($LANG) ?>;
        document.documentElement.setAttribute("data-lang", currentLang);
        document.documentElement.lang = currentLang;
    })();
</script>
