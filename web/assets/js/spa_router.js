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

    // On rapid successive navigations (the user clicks a link and, before the
    // response arrives, clicks another link/form) two fetches could run in
    // parallel — whichever came back first was painted, so the screen could
    // keep the wrong (older) page even with the right URL in the address bar
    // (found in the 2026-08-21 audit). Every navigation/form submission gets
    // its own sequence number; when the response arrives it is checked to
    // still be the "latest request", otherwise it is silently dropped.
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
        window.loadSPAPage = loadSPAPage;

        // Intercept programmatic form.submit() calls so onchange="this.form.submit()" triggers SPA
        if (!HTMLFormElement.prototype._spaSubmitWrapped) {
            const origSubmit = HTMLFormElement.prototype.submit;
            HTMLFormElement.prototype.submit = function() {
                if (typeof this.requestSubmit === 'function') {
                    this.requestSubmit();
                } else {
                    origSubmit.call(this);
                }
            };
            HTMLFormElement.prototype._spaSubmitWrapped = true;
        }

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

            const method = (form.method || 'GET').toUpperCase();
            const formData = new FormData(form);

            if (method === 'GET') {
                const actionAttr = form.getAttribute('action');
                let targetUrlObj;
                try {
                    targetUrlObj = new URL(actionAttr || window.location.pathname, window.location.origin);
                } catch(e) {
                    targetUrlObj = new URL(window.location.pathname, window.location.origin);
                }
                const params = new URLSearchParams(formData);
                targetUrlObj.search = params.toString();
                loadSPAPage(targetUrlObj.href, true);
                return;
            }

            const actionUrl = form.getAttribute('action') || window.location.href;
            startProgress();
            const mySeq = ++requestSeq;

            fetch(actionUrl, {
                method: method,
                headers: {
                    'X-SPA-Request': '1'
                },
                body: formData
            })
            .then(res => {
                // When the server returned an error page (403/419/500 etc.) or
                // an empty body, it used to silently replace .content-area —
                // the user could believe "saved" or the content went blank,
                // with no warning at all (found in the 2026-08-21 audit). Same
                // pattern as loadSPAPage(): on an HTTP error fall back to a hard
                // (full page) navigation.
                if (!res.ok) throw new Error('HTTP ' + res.status);
                if (!isHtmlResponse(res)) {
                    return downloadResponse(res, actionUrl).then(() => null);
                }
                const finalUrl = res.url || actionUrl;
                return res.text().then(html => ({ html, url: finalUrl }));
            })
            .then(data => {
                if (data === null) return; // a file was downloaded
                if (mySeq !== requestSeq) return; // a newer navigation has already started
                renderSPAPage(data.html, data.url, true);
            })
            .catch(err => {
                console.error('SPA Form Handling Error:', err);
                if (mySeq !== requestSeq) return;
                completeProgress();
                window.location.href = actionUrl; // hard navigation only on an HTTP error
            });
        });

        // Handle Browser Back / Forward History Navigation
        window.addEventListener('popstate', function() {
            loadSPAPage(window.location.href, false);
        });
    }

    // A link or form may answer with a file (report PDF/Excel, recording...):
    // putting that into the page showed raw bytes. Anything that is not HTML
    // is saved as a download instead and the page stays where it was.
    function isHtmlResponse(res) {
        const type = (res.headers.get('Content-Type') || '').toLowerCase();
        const disposition = (res.headers.get('Content-Disposition') || '').toLowerCase();
        return type.includes('text/html') && !disposition.startsWith('attachment');
    }

    function downloadResponse(res, url) {
        const disposition = res.headers.get('Content-Disposition') || '';
        const m = disposition.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
        let name = m ? decodeURIComponent(m[1]) : '';
        if (!name) {
            try { name = new URL(url, window.location.origin).pathname.split('/').pop() || 'download'; } catch (e) { name = 'download'; }
        }
        return res.blob().then(blob => {
            const objectUrl = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = objectUrl;
            a.download = name;
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(objectUrl), 60000);
            completeProgress();
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
            if (!isHtmlResponse(res)) {
                return downloadResponse(res, url).then(() => null);
            }
            return res.text();
        })
        .then(html => {
            if (html === null) return; // a file was downloaded, the page stays as it is
            if (mySeq !== requestSeq) return; // a newer navigation has already started
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
        // NOTE: writing innerHTML + updating the sidebar + injecting scripts
        // all used to happen in the SAME requestAnimationFrame callback — on
        // big pages this blocked the main thread for 400ms+, with the browser
        // warning "Violation: 'requestAnimationFrame' handler took Xms" (a
        // short "stutter"). Now only the visual work (innerHTML, scroll,
        // progress bar) happens in this frame; the less urgent work (sidebar
        // active link, script injection) runs AFTER the browser has painted it
        // (on the next tick).
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

                // The <script src> tags injected by executePageScripts() load
                // ASYNCHRONOUSLY in the browser — firing spa:pageLoaded right
                // here missed the event before the page script (e.g.
                // agent_ui.js) had loaded and registered its listener (the page
                // stayed stuck on "Loading..."). Now we wait for the load/error
                // results.
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

    // The "Apply" badge in the sidebar sits OUTSIDE .content-area, so a normal
    // SPA content swap never refreshed it (2026-08-31, user finding) — we read
    // the data-pending-sync-count that header.php embeds in the SPA response
    // and update the badge here. The element always stays in the DOM (see
    // sidebar.php - #pending-sync-badge) and is only shown/hidden with
    // display:none/flex; so there is no need to rebuild the markup here, just
    // change the count and the visibility.
    function updatePendingSyncBadge(pageData) {
        if (!pageData) return;
        const countAttr = pageData.getAttribute('data-pending-sync-count');
        if (countAttr === null) return;

        const count = parseInt(countAttr, 10) || 0;

        // 1. Sidebar badge update
        const badge = document.getElementById('pending-sync-badge');
        if (badge) {
            const countSpan = document.getElementById('pending-sync-count');
            if (countSpan) countSpan.textContent = count;
            badge.style.display = count > 0 ? 'flex' : 'none';
        }

        // 2. Header (topbar) Apply button and dropdown update
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

                // Scripts with src load ASYNCHRONOUSLY in the browser — a
                // promise is added to wait for the load/error result (see the
                // note at the caller).
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
                    document.body.appendChild(newScript); // inline script: appendChild runs it synchronously
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
