/* Sidebar menu search (filters the menu, offers the Assistant) and ?highlight= from the settings search. */
    (function () {
        var input = document.getElementById('sidebar-menu-search');
        var sidebar = document.getElementById('app-sidebar');
        if (!input || !sidebar) return;
        function currentGroups() {
            var list = Array.prototype.slice.call(sidebar.querySelectorAll('.nav-menu > .nav-group'));
            list.forEach(function (g) {
                if (!g.hasAttribute('data-initial-open')) {
                    g.setAttribute('data-initial-open', g.classList.contains('open') ? '1' : '0');
                }
            });
            return list;
        }
        currentGroups();
        input.addEventListener('input', function () {
            // Looked up each time: the link goes away and comes back with the Assistant.
            var aiLink = document.getElementById('sidebar-ai-ask-link');
            var aiText = document.getElementById('sidebar-ai-ask-text');
            var q = (input.value || '').trim().toLowerCase();
            currentGroups().forEach(function (group) {
                var items = Array.prototype.slice.call(group.querySelectorAll('.nav-submenu > li'));
                var groupTitle = (group.querySelector('.toggle-title')?.textContent || '').toLowerCase();
                var anyMatch = false;
                items.forEach(function (li) {
                    var text = (li.textContent || '').toLowerCase();
                    var match = !q || text.indexOf(q) !== -1 || groupTitle.indexOf(q) !== -1;
                    li.style.display = match ? '' : 'none';
                    if (match) anyMatch = true;
                });
                group.style.display = (!q || anyMatch) ? '' : 'none';
                if (q) {
                    group.hidden = false;
                    group.classList.toggle('open', anyMatch);
                } else {
                    group.classList.toggle('open', group.getAttribute('data-initial-open') === '1');
                }
            });
            if (!q && typeof window.applySidebarSection === 'function') {
                window.applySidebarSection();
            }
            if (aiLink && aiText) {
                if (q) {
                    aiLink.classList.add('is-visible');
                    aiLink.href = '/assistant?q=' + encodeURIComponent(input.value.trim());
                    aiText.textContent = 'AI: "' + input.value.trim() + '"';
                } else {
                    aiLink.classList.remove('is-visible');
                }
            }
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                var raw = (input.value || '').trim();
                if (!raw || !document.getElementById('sidebar-ai-ask-link')) return;
                e.preventDefault();
                var url = '/assistant?q=' + encodeURIComponent(raw);
                if (window.SpaRouter && typeof window.SpaRouter.navigate === 'function') {
                    window.SpaRouter.navigate(url);
                } else {
                    window.location.href = url;
                }
            }
        });
    })();
    

(function () {
    function runHighlightFromUrl() {
        var params = new URLSearchParams(window.location.search || '');
        var term = (params.get('highlight') || '').trim().toLowerCase();
        if (!term) return;
        var root = document.querySelector('.content-area') || document.body;
        var candidates = root.querySelectorAll('.form-label, label, .card-title, h1, h2, h3, h4, .nav-link, th, td');
        for (var i = 0; i < candidates.length; i++) {
            var el = candidates[i];
            if ((el.textContent || '').toLowerCase().indexOf(term) !== -1) {
                var pane = el.closest('.tab-pane');
                if (pane && pane.id) {
                    var tabBtn = document.querySelector('[data-bs-target="#' + pane.id + '"], [href="#' + pane.id + '"]');
                    if (tabBtn && typeof tabBtn.click === 'function') tabBtn.click();
                }
                var target = el.closest('.form-group, .card, tr') || el;
                target.classList.remove('aipbx-search-highlight');
                void target.offsetWidth;
                target.classList.add('aipbx-search-highlight');
                setTimeout(function (node) {
                    node.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 80, target);
                break;
            }
        }
    }
    document.addEventListener('DOMContentLoaded', runHighlightFromUrl);
    document.addEventListener('spa:pageLoaded', runHighlightFromUrl);
})();
