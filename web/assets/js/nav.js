/**
 * Navigation Accordion & Sidebar Menu Module
 */
function toggleNavGroup(groupId) {
    const target = document.getElementById(groupId);
    if (!target) return;

    const isOpen = target.classList.contains('open');

    // Accordion: Diğer tüm açık menüleri kapat
    document.querySelectorAll('.nav-group').forEach(function(group) {
        if (group.id !== groupId) {
            group.classList.remove('open');
        }
    });

    // Seçilen grubu aç/kapat
    if (isOpen) {
        target.classList.remove('open');
    } else {
        target.classList.add('open');
    }
}

function updateSidebarToggleIcon(isOpen) {
    const icon = document.getElementById('header-sidebar-toggle-icon') || document.querySelector('#header-sidebar-toggle i');
    if (!icon) return;
    if (isOpen) {
        icon.className = 'fas fa-chevron-left';
    } else {
        icon.className = 'fas fa-chevron-right';
    }
}

function toggleSidebar(e) {
    if (e) {
        e.stopPropagation();
    }
    const sidebar = document.getElementById('app-sidebar');
    const overlay = document.getElementById('mobile-sidebar-overlay');
    if (!sidebar) return;

    // Mobil ekranda (<=900px) drawer olarak aç/kapat
    if (window.innerWidth <= 900) {
        const isMobileOpen = sidebar.classList.toggle('mobile-open');
        if (overlay) {
            if (isMobileOpen) overlay.classList.add('active');
            else overlay.classList.remove('active');
        }
        updateSidebarToggleIcon(isMobileOpen);
        return;
    }

    // Masaüstü ekranda tam kayar menü olarak aç/kapat (Linear / Slack tarzı)
    const isCollapsed = sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebar_collapsed', isCollapsed ? 'true' : 'false');
    document.cookie = "sidebar_collapsed=" + (isCollapsed ? "true" : "false") + "; path=/; max-age=31536000";
    updateSidebarToggleIcon(!isCollapsed);
}

/**
 * Mobile Drawer Functions
 */
function toggleMobileSidebar() {
    const sidebar = document.getElementById('app-sidebar');
    const overlay = document.getElementById('mobile-sidebar-overlay');
    if (!sidebar) return;

    sidebar.classList.remove('collapsed');
    const isOpen = sidebar.classList.contains('mobile-open');
    if (isOpen) {
        closeMobileSidebar();
    } else {
        sidebar.classList.add('mobile-open');
        if (overlay) overlay.classList.add('active');
        updateSidebarToggleIcon(true);
    }
}

function closeMobileSidebar() {
    const sidebar = document.getElementById('app-sidebar');
    const overlay = document.getElementById('mobile-sidebar-overlay');
    if (sidebar) sidebar.classList.remove('mobile-open');
    if (overlay) overlay.classList.remove('active');
    updateSidebarToggleIcon(false);
}

document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('app-sidebar');

    if (sidebar) {
        // Restore saved sidebar state
        const isCollapsed = localStorage.getItem('sidebar_collapsed') === 'true';
        if (isCollapsed) {
            sidebar.classList.add('collapsed');
            updateSidebarToggleIcon(false);
        } else {
            updateSidebarToggleIcon(true);
        }
    }
});
