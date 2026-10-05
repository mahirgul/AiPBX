<?php
/**
 * Navigation Sidebar Template Component
 */
$is_collapsed_cookie = isset($_COOKIE['sidebar_collapsed']) && $_COOKIE['sidebar_collapsed'] === 'true';
?>
<!-- Sidebar Navigation -->
<aside class="sidebar <?php echo $is_collapsed_cookie ? 'collapsed' : ''; ?>" id="app-sidebar">
    <div class="brand">
        <div class="brand-icon">
            <?php if ($site_logo_type === 'image' && !empty($site_logo_image)): ?>
                <img src="<?php echo htmlspecialchars($site_logo_image); ?>" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">
            <?php else: ?>
                <i class="fas <?php echo htmlspecialchars($site_logo_icon); ?>"></i>
            <?php endif; ?>
        </div>
        <div class="brand-text-wrapper">
            <div class="brand-title"><?php echo htmlspecialchars($brand_title); ?></div>
            <div class="brand-sub"><?php echo htmlspecialchars($brand_sub); ?></div>
        </div>
    </div>

    <?php
    // Deferred reload system (2026-08-24): while changes are pending, a
    // persistent, eye-catching "Apply" badge is shown at the top of the
    // sidebar — visible only WHILE something is pending (not printed
    // otherwise); asterisk_sync.php is required directly so it works on
    // every page (without depending on helpers.php being loaded).
    require_once __DIR__ . '/../src/asterisk_sync.php';
    $pending_sync_count = hasModulePermission('pending_sync', 'view') ? getPendingSyncCount() : 0;
    ?>
    <?php if (hasModulePermission('pending_sync', 'view')): ?>
        <!-- id="pending-sync-badge" -> shown/hidden and its count updated by
             spa_router.js on SPA navigations (see data-pending-sync-count in
             header.php + updatePendingSyncBadge() in spa_router.js). The badge
             is OUTSIDE the SPA content area (in the sidebar), so a normal SPA
             content swap never refreshed it — after pressing "Apply" it kept
             the old count until the page was reloaded (2026-08-31, user finding). -->
        <a href="/pending-sync" class="nav-link" id="pending-sync-badge" style="display: <?php echo $pending_sync_count > 0 ? 'flex' : 'none'; ?>; align-items: center; justify-content: center; gap: 8px; margin: 0 12px 12px; padding: 10px 12px; border-radius: 8px; background: var(--warning); color: #1a1a1a; font-weight: 700; font-size: 13px; text-decoration: none;" title="<?php echo t('sidebar.pending_sync_tooltip'); ?>">
            <i class="fas fa-cloud-upload-alt"></i>
            <span class="nav-text"><?php echo t('sidebar.pending_sync_label'); ?> (<span id="pending-sync-count"><?php echo $pending_sync_count; ?></span>)</span>
        </a>
    <?php endif; ?>

    <?php
    // Concurrent admin warning (2026-08-24, user request): when ANOTHER admin
    // is active in the system right now (made a request in the last 2
    // minutes), a persistent warning is shown — two admins could make
    // conflicting changes at the same time. Only meaningful for the admin
    // role (the query filters on the admin role).
    $other_active_admins = ($role === 'admin') ? getOtherActiveAdmins($user['id'] ?? 0) : [];
    ?>
    <?php if (!empty($other_active_admins)): ?>
        <?php
            $other_names = array_map(fn($a) => $a['full_name'], $other_active_admins);
        ?>
        <div class="nav-link" style="display: flex; align-items: center; justify-content: center; gap: 8px; margin: 0 12px 12px; padding: 10px 12px; border-radius: 8px; background: var(--info); color: #fff; font-weight: 700; font-size: 12px; text-align: center;" title="<?php echo htmlspecialchars(implode(', ', $other_names)); ?>">
            <i class="fas fa-user-clock"></i>
            <span class="nav-text"><?php echo t('sidebar.other_admin_active'); ?></span>
        </div>
    <?php endif; ?>

    <ul class="nav-menu">
        <?php
        $sidebar_label = fn($l) => is_array($l) ? t($l[0], $l[1]) : t($l);
        $sidebar_icon = fn(array $e) => '<i class="' . $e['icon'] . '"' . (isset($e['icon_style']) ? ' style="' . $e['icon_style'] . '"' : '') . '></i>';
        foreach (require __DIR__ . '/sidebar_menu.php' as $group):
            $items = array_filter($group['items'], fn($item) => $item['show']);
            if (!$items) {
                continue;
            }
            $is_active = fn($item) => in_array($active_page, $item['pages'], true)
                || (isset($item['uri']) && str_contains($request_uri, $item['uri']));
            $open = in_array($active_page, $group['open_on'] ?? [], true) || array_filter($group['items'], $is_active);
            $group_label = $sidebar_label($group['label']);
            ?>
            <li class="nav-group <?php echo ($open && !$is_collapsed_cookie) ? 'open' : ''; ?>" id="group-<?php echo $group['id']; ?>">
                <button class="nav-toggle-btn" onclick="toggleNavGroup('group-<?php echo $group['id']; ?>')" title="<?php echo $group_label; ?>">
                    <span class="toggle-title">
                        <?php echo $sidebar_icon($group); ?> <span class="nav-text"><?php echo $group_label; ?></span>
                    </span>
                    <i class="fas fa-chevron-down chevron-icon"></i>
                </button>
                <ul class="nav-submenu">
                    <?php foreach ($items as $item): ?>
                        <li>
                            <a href="<?php echo $item['href']; ?>" class="nav-link <?php echo $is_active($item) ? 'active' : ''; ?>" title="<?php echo $sidebar_label($item['title'] ?? $item['label']); ?>">
                                <?php echo $sidebar_icon($item); ?> <span class="nav-text"><?php echo $sidebar_label($item['label']); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </li>
        <?php endforeach; ?>
    </ul>
</aside>
