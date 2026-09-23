/**
 * Single-Page Application (SPA) Router & Content Loading Engine (Flicker-Free & Loop-Protected)
 * Preserves Header, Sidebar, and WebRTC SIP Softphone WebSocket Connection across all page transitions.
 */

(function() {
    'use strict';

    if (window.SPA_ROUTER_INITIALIZED) {
        return;
    }
    window.SPA_ROUTER_INITIALIZED = true;

    let progressBar = null;

    // Hızlı ardışık navigasyonlarda (kullanıcı bir linke tıklayıp yanıt gelmeden
    // hemen başka bir linke/forma tıklarsa) iki fetch paralel çalışabiliyordu —
    // hangisi önce dönerse ekrana o basılıyordu, URL adres çubuğunda doğru olsa
    // bile ekranda yanlış (daha eski) sayfa kalabiliyordu (2026-08-21 denetiminde
    // bulundu). Her navigasyon/form-gönderimi kendi sıra numarasını alır, yanıt
    // gelince hâlâ "en güncel istek" mi diye kontrol edilir; değilse sessizce atlanır.
    let requestSeq = 0;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSPARouter);
    } else {
        initSPARouter();
    }

    function createProgressBar() {
        if (document.getElementById('spa-progress-bar')) return;
        progressBar = document.createElement('div');
        progressBar.id = 'spa-progress-bar';
        progressBar.style.cssText = 'position: fixed; top: 0; left: 0; width: 0%; height: 3px; background: linear-gradient(90deg, #00f2fe, #4facfe); z-index: 99999; transition: width 0.2s ease, opacity 0.3s ease; opacity: 0; pointer-events: none;';
        document.body.appendChild(progressBar);
    }

    function startProgress() {
        if (!progressBar) createProgressBar();
        if (progressBar) {
            progressBar.style.opacity = '1';
            progressBar.style.width = '35%';
        }
    }

    function completeProgress() {
        if (!progressBar) return;
        progressBar.style.width = '100%';
        setTimeout(function() {
            progressBar.style.opacity = '0';
            setTimeout(function() {
                progressBar.style.width = '0%';
            }, 300);
        }, 150);
    }

    function initSPARouter() {
        createProgressBar();

        // Intercept all internal link clicks safely
        document.body.addEventListener('click', function(e) {
            const anchor = e.target.closest('a');
            if (!anchor) return;

            const href = anchor.getAttribute('href');
            if (!href || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('tel:') || href.startsWith('mailto:')) {
                return;
            }

            // Skip external links, data-no-spa links, or file downloads
            if (anchor.target === '_blank' || anchor.hasAttribute('download') || anchor.getAttribute('data-no-spa') === 'true' || href.match(/\.(pdf|wav|tif|png|jpg|csv|zip)$/i)) {
                return;
            }

            const targetUrl = new URL(anchor.href, window.location.origin);
            if (targetUrl.origin !== window.location.origin) {
                return; // External domain
            }

            // Skip if clicking exact same URL
            if (targetUrl.pathname === window.location.pathname && targetUrl.search === window.location.search) {
                e.preventDefault();
                return;
            }

            e.preventDefault();
            loadSPAPage(targetUrl.href, true);
        });

        // Intercept form submissions inside content area
        document.body.addEventListener('submit', function(e) {
            const form = e.target;
            if (!form || form.getAttribute('data-no-spa') === 'true' || form.target === '_blank') {
                return;
            }

            const contentArea = form.closest('.content-area');
            if (!contentArea) return;

            e.preventDefault();

            const actionUrl = form.getAttribute('action') || window.location.href;
            const method = (form.method || 'POST').toUpperCase();
            const formData = new FormData(form);

            startProgress();
            const mySeq = ++requestSeq;

            fetch(actionUrl, {
                method: method,
                headers: {
                    'X-SPA-Request': '1'
                },
                body: method === 'POST' ? formData : null
            })
            .then(res => {
                // Sunucu bir hata sayfası (403/419/500 vb.) ya da boş gövde
                // döndürürse, önceden bu sessizce .content-area'nın yerine
                // geçiyordu — kullanıcı "kaydedildi" sanabiliyor ya da içerik
                // boşalıyordu, hiçbir uyarı çıkmıyordu (2026-08-21 denetiminde
                // bulundu). loadSPAPage() ile aynı desen: HTTP hatasında sert
                // (tam sayfa) yönlendirmeye düş.
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const finalUrl = res.url || actionUrl;
                return res.text().then(html => ({ html, url: finalUrl }));
            })
            .then(data => {
                if (mySeq !== requestSeq) return; // daha yeni bir navigasyon zaten başladı
                renderSPAPage(data.html, data.url, true);
            })
            .catch(err => {
                console.error('SPA Form Handling Error:', err);
                if (mySeq !== requestSeq) return;
                completeProgress();
                window.location.href = actionUrl; // Sadece HTTP hatasında sert yönlendirme
            });
        });

        // Handle Browser Back / Forward History Navigation
        window.addEventListener('popstate', function() {
            loadSPAPage(window.location.href, false);
        });
    }

    function loadSPAPage(url, pushHistory) {
        startProgress();
        const mySeq = ++requestSeq;

        fetch(url, {
            cache: 'no-cache',
            headers: {
                'X-SPA-Request': '1',
                'Cache-Control': 'no-cache'
            }
        })
        .then(res => {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.text();
        })
        .then(html => {
            if (mySeq !== requestSeq) return; // daha yeni bir navigasyon zaten başladı
            renderSPAPage(html, url, pushHistory);
        })
        .catch(err => {
            console.error('SPA Router Fetch Failure:', err);
            if (mySeq !== requestSeq) return;
            completeProgress();
            window.location.href = url; // Only navigate on hard HTTP fail
        });
    }

    function renderSPAPage(html, url, pushHistory) {
        const contentArea = document.querySelector('.content-area');
        if (!contentArea) {
            window.location.href = url;
            return;
        }

        // Close any open modal overlays
        document.querySelectorAll('.modal-overlay.active').forEach(modal => {
            modal.style.display = 'none';
            modal.classList.remove('active');
        });

        // Parse returned HTML
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');

        // Extract inner page content
        const newContent = doc.querySelector('.content-area') || doc.body;
        
        // Extract title data
        const pageData = doc.querySelector('#spa-page-data');
        if (pageData && pageData.getAttribute('data-title')) {
            document.title = pageData.getAttribute('data-title');
        } else if (doc.title) {
            document.title = doc.title;
        }

        // Atomic DOM Swap
        // NOT: Eskiden innerHTML yazımı + sidebar güncelleme + script enjeksiyonu
        // hepsi AYNI requestAnimationFrame callback'inde yapılıyordu — büyük
        // sayfalarda bu 400ms+ süren, tarayıcının "Violation: 'requestAnimationFrame'
        // handler took Xms" uyarısı verdiği bir ana thread bloklanmasına yol
        // açıyordu (kısa bir "takılma" hissi). Şimdi: bu frame'de sadece görünümü
        // etkileyen (innerHTML, scroll, progress bar) işler yapılıyor, tarayıcı
        // bunu boyadıktan SONRA (bir sonraki tick'te) daha az acil olan işler
        // (sidebar aktif link, script enjeksiyonu) çalışıyor.
        requestAnimationFrame(() => {
            contentArea.innerHTML = newContent.innerHTML;

            if (pushHistory) {
                window.history.pushState({ url: url }, '', url);
            }

            completeProgress();
            window.scrollTo({ top: 0, behavior: 'instant' });

            setTimeout(() => {
                updateActiveSidebarNav(url);
                updatePendingSyncBadge(pageData);

                // executePageScripts() enjekte ettiği <script src> etiketleri
                // tarayıcıda ASENKRON yükleniyor — spa:pageLoaded'ı hemen burada
                // ateşlersek sayfa scripti (ör. agent_ui.js) henüz yüklenip kendi
                // dinleyicisini kaydetmeden olay kaçırılıyordu (sayfa "Yükleniyor..."
                // durumunda takılı kalıyordu). Artık yükleme/hata sonucunu bekliyoruz.
                executePageScripts(contentArea).then(() => {
                    document.dispatchEvent(new CustomEvent('spa:pageLoaded', { detail: { url: url } }));
                });
            }, 0);
        });
    }

    function updateActiveSidebarNav(url) {
        const currentPath = new URL(url, window.location.origin).pathname;
        const navLinks = document.querySelectorAll('.nav-menu .nav-link');

        // Close mobile drawer overlay if open
        if (typeof closeMobileSidebar === 'function') {
            closeMobileSidebar();
        }

        let activeGroup = null;

        navLinks.forEach(link => {
            const linkPath = new URL(link.href, window.location.origin).pathname;
            if (linkPath === currentPath) {
                link.classList.add('active');
                activeGroup = link.closest('.nav-group');
            } else {
                link.classList.remove('active');
            }
        });

        // Open active group only if sidebar is expanded, close all other groups for clean accordion state
        const sidebar = document.getElementById('app-sidebar');
        const isCollapsed = (sidebar && sidebar.classList.contains('collapsed')) || localStorage.getItem('sidebar_collapsed') === 'true';

        document.querySelectorAll('.nav-group').forEach(group => {
            if (!isCollapsed && activeGroup && group === activeGroup) {
                group.classList.add('open');
            } else {
                group.classList.remove('open');
            }
        });
    }

    // Sidebar'daki "Uygula" rozeti .content-area DIŞINDA olduğu için normal SPA
    // içerik değişimiyle asla yenilenmiyordu (2026-08-31, kullanıcı bulgusu) —
    // header.php'nin SPA yanıtına gömdüğü data-pending-sync-count'u okuyup
    // rozeti burada güncelliyoruz. Element her zaman DOM'da duruyor (bkz.
    // sidebar.php - #pending-sync-badge), sadece display:none/flex ile
    // gösterilip gizleniyor; bu yüzden burada tam markup'ı yeniden üretmeye
    // gerek yok, sadece sayıyı ve görünürlüğü değiştirmek yeterli.
    function updatePendingSyncBadge(pageData) {
        if (!pageData) return;
        const countAttr = pageData.getAttribute('data-pending-sync-count');
        if (countAttr === null) return;

        const count = parseInt(countAttr, 10) || 0;

        // 1. Sidebar rozet güncellemesi
        const badge = document.getElementById('pending-sync-badge');
        if (badge) {
            const countSpan = document.getElementById('pending-sync-count');
            if (countSpan) countSpan.textContent = count;
            badge.style.display = count > 0 ? 'flex' : 'none';
        }

        // 2. Header (Topbar) Uygula butonu ve açılır menü güncellemesi
        const headerContainer = document.getElementById('header-pending-sync-container');
        if (headerContainer) {
            const headerCount = document.getElementById('header-pending-sync-count');
            if (headerCount) headerCount.textContent = count;
            const dropdownCount = document.getElementById('header-sync-dropdown-count');
            if (dropdownCount) dropdownCount.textContent = count;
            headerContainer.style.display = count > 0 ? 'inline-flex' : 'none';
        }
    }

    function executePageScripts(container) {
        // Remove previous page-specific script tags to avoid duplicate executions
        document.querySelectorAll('script[data-page-script="true"]').forEach(s => s.remove());

        container.querySelectorAll('script').forEach(s => s.classList.add('spa-inert'));

        const scripts = container.querySelectorAll('script');
        const loadPromises = [];

        scripts.forEach(oldScript => {
            const src = oldScript.getAttribute('src') || '';

            // Ignore global setup scripts if present
            if (src.includes('header_phone.js') || src.includes('jssip.min.js') || src.includes('sip.min.js') || src.includes('spa_router.js') || src.includes('theme.js') || src.includes('nav.js') || src.includes('ui_helper.js')) {
                return;
            }

            if (src) {
                const baseSrc = src.split('?')[0];
                document.querySelectorAll(`script[src*="${baseSrc}"]`).forEach(s => s.remove());

                const newScript = document.createElement('script');
                Array.from(oldScript.attributes).forEach(attr => {
                    if (attr.name === 'class') return;
                    let val = attr.value || '';
                    if (attr.name === 'src') {
                        val = val.replace(/^["'\\]+|["'\\]+$/g, '').trim();
                    }
                    newScript.setAttribute(attr.name, val);
                });
                newScript.setAttribute('data-page-script', 'true');

                // src'li script'ler tarayıcıda ASENKRON yükleniyor — yükleme/
                // hata sonucunu bekleyecek bir promise ekleniyor (bkz. çağıran
                // yerdeki not).
                loadPromises.push(new Promise((resolve) => {
                    newScript.addEventListener('load', resolve);
                    newScript.addEventListener('error', resolve);
                }));

                document.head.appendChild(newScript);
            } else {
                const newScript = document.createElement('script');
                Array.from(oldScript.attributes).forEach(attr => {
                    if (attr.name === 'class') return;
                    newScript.setAttribute(attr.name, attr.value);
                });
                newScript.setAttribute('data-page-script', 'true');
                newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                try {
                    document.body.appendChild(newScript); // inline script: appendChild senkron çalıştırır
                } catch (scriptErr) {
                    console.error('[SPA Router] Inline script execution error:', scriptErr);
                }
            }
        });

        return Promise.all(loadPromises);
    }

    // Expose SPA function
    window.loadSPAPage = loadSPAPage;

})();
