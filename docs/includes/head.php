<?php
/**
 * AiPBX.bid — Universal Head Include
 * Preloads fonts, stylesheets, favicons, synchronous language auto-detection, and edge GeoIP.
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

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

<!-- Font Awesome 6.5.1 -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />

<!-- Core Stylesheets -->
<link rel="stylesheet" href="/css/style.css?v=1.0.35">
<link rel="stylesheet" href="/css/tables.css?v=1.0.35">

<!-- Synchronous Language Auto-Detection & Zero-Flicker Cloak -->
<style id="lang-cloak">
    html[data-lang="tr"] [data-lang]:not([data-lang="tr"]),
    html[data-lang="en"] [data-lang]:not([data-lang="en"]),
    html[data-lang="de"] [data-lang]:not([data-lang="de"]) { display: none !important; }
    
    html[data-lang="tr"] span[data-lang="tr"], html[data-lang="tr"] a[data-lang="tr"], html[data-lang="tr"] strong[data-lang="tr"], html[data-lang="tr"] em[data-lang="tr"], html[data-lang="tr"] code[data-lang="tr"],
    html[data-lang="en"] span[data-lang="en"], html[data-lang="en"] a[data-lang="en"], html[data-lang="en"] strong[data-lang="en"], html[data-lang="en"] em[data-lang="en"], html[data-lang="en"] code[data-lang="en"],
    html[data-lang="de"] span[data-lang="de"], html[data-lang="de"] a[data-lang="de"], html[data-lang="de"] strong[data-lang="de"], html[data-lang="de"] em[data-lang="de"], html[data-lang="de"] code[data-lang="de"] { display: inline !important; }

    html[data-lang="tr"] p[data-lang="tr"], html[data-lang="tr"] div[data-lang="tr"], html[data-lang="tr"] h1[data-lang="tr"], html[data-lang="tr"] h2[data-lang="tr"], html[data-lang="tr"] h3[data-lang="tr"], html[data-lang="tr"] li[data-lang="tr"],
    html[data-lang="en"] p[data-lang="en"], html[data-lang="en"] div[data-lang="en"], html[data-lang="en"] h1[data-lang="en"], html[data-lang="en"] h2[data-lang="en"], html[data-lang="en"] h3[data-lang="en"], html[data-lang="en"] li[data-lang="en"],
    html[data-lang="de"] p[data-lang="de"], html[data-lang="de"] div[data-lang="de"], html[data-lang="de"] h1[data-lang="de"], html[data-lang="de"] h2[data-lang="de"], html[data-lang="de"] h3[data-lang="de"], html[data-lang="de"] li[data-lang="de"] { display: block !important; }
</style>
<script>
    (function() {
        var saved = null;
        try { saved = localStorage.getItem('aipbx_user_lang') || localStorage.getItem('aipbx_lang'); } catch(e) {}
        if (!saved) {
            var match = document.cookie.match(/(?:^|;\s*)aipbx_lang=([^;]+)/);
            if (match && ["tr", "en", "de"].includes(match[1])) {
                saved = match[1];
            }
        }
        var urlParam = null;
        try {
            var params = new URLSearchParams(window.location.search);
            var ql = params.get('lang');
            if (ql && ["tr", "en", "de"].includes(ql)) {
                urlParam = ql;
                saved = ql;
                try {
                    localStorage.setItem('aipbx_user_lang', ql);
                    localStorage.setItem('aipbx_lang', ql);
                    document.cookie = "aipbx_lang=" + ql + ";path=/;max-age=31536000;SameSite=Lax";
                } catch(e) {}
            }
        } catch(e) {}

        var lang = saved;
        if (!lang || !["tr", "en", "de"].includes(lang)) {
            var navLangs = navigator.languages || [navigator.language || navigator.userLanguage || "en"];
            lang = "en";
            for (var i = 0; i < navLangs.length; i++) {
                var l = (navLangs[i] || "").toLowerCase();
                if (l.indexOf("tr") === 0 || l.indexOf("az") === 0) { lang = "tr"; break; }
                if (l.indexOf("de") === 0) { lang = "de"; break; }
            }
        }
        document.documentElement.setAttribute("data-lang", lang);
        document.documentElement.lang = lang;

        // Edge GeoIP detection via Cloudflare trace ONLY if user has NOT manually chosen a language
        if (!saved && !urlParam) {
            fetch('/cdn-cgi/trace')
                .then(function(r) { return r.text(); })
                .then(function(text) {
                    try {
                        if (localStorage.getItem('aipbx_user_lang')) return;
                    } catch(e) {}
                    var m = text.match(/loc=([A-Z]{2})/);
                    if (m) {
                        var country = m[1];
                        var geoLang = "en";
                        if (country === "AT" || country === "DE" || country === "CH" || country === "LI") {
                            geoLang = "de";
                        } else if (country === "TR" || country === "AZ") {
                            geoLang = "tr";
                        }
                        if (geoLang !== document.documentElement.getAttribute('data-lang')) {
                            if (window.setLanguage) {
                                window.setLanguage(geoLang);
                            } else {
                                document.documentElement.setAttribute("data-lang", geoLang);
                                document.documentElement.lang = geoLang;
                            }
                        }
                    }
                }).catch(function() {});
        }
    })();
</script>
