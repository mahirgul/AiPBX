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
    // Ertelenmiş reload sistemi (2026-08-24): bekleyen değişiklik varsa
    // sidebar'ın en üstünde kalıcı, göze çarpan bir "Uygula" rozeti gösterilir
    // — sadece bekleyen bir şey VARKEN görünür (yoksa hiç basılmaz), her
    // sayfada (helpers.php'nin require edilmiş olmasına bağlı olmadan)
    // çalışsın diye asterisk_sync.php doğrudan require ediliyor.
    require_once __DIR__ . '/../src/asterisk_sync.php';
    $pending_sync_count = hasModulePermission('pending_sync', 'view') ? getPendingSyncCount() : 0;
    ?>
    <?php if (hasModulePermission('pending_sync', 'view')): ?>
        <!-- id="pending-sync-badge" -> SPA navigasyonlarda spa_router.js tarafından
             göster/gizle + sayı güncellemesi yapılıyor (bkz. header.php'deki
             data-pending-sync-count + spa_router.js'teki updatePendingSyncBadge()).
             Rozet SPA-content-area DIŞINDA (sidebar'da) olduğu için normal SPA
             içerik değişimiyle asla yenilenmiyordu — "Uygula"ya basınca sayfa
             yenilenene kadar eski sayıyla kalıyordu (2026-08-31, kullanıcı bulgusu). -->
        <a href="/pending-sync" class="nav-link" id="pending-sync-badge" style="display: <?php echo $pending_sync_count > 0 ? 'flex' : 'none'; ?>; align-items: center; justify-content: center; gap: 8px; margin: 0 12px 12px; padding: 10px 12px; border-radius: 8px; background: var(--warning); color: #1a1a1a; font-weight: 700; font-size: 13px; text-decoration: none;" title="<?php echo t('sidebar.pending_sync_tooltip'); ?>">
            <i class="fas fa-cloud-upload-alt"></i>
            <span class="nav-text"><?php echo t('sidebar.pending_sync_label'); ?> (<span id="pending-sync-count"><?php echo $pending_sync_count; ?></span>)</span>
        </a>
    <?php endif; ?>

    <?php
    // Eşzamanlı admin uyarısı (2026-08-24, kullanıcı isteği): şu an sistemde
    // aktif BAŞKA bir admin varsa (son 2 dakikada istek yapmış) kalıcı bir
    // uyarı gösterilir — iki admin aynı anda çakışan değişiklikler yapabilir.
    // Sadece admin rolü için anlamlı (last_seen_at sadece adminler için
    // takip edilmez ama sorgu sadece admin rolünü filtreliyor).
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
        <!-- 1. Kontrol Paneli / Dashboard Menüsü -->
        <?php if ($can_view_dashboard_group): ?>
            <li class="nav-group <?php echo ($is_dashboard_active && !$is_collapsed_cookie) ? 'open' : ''; ?>" id="group-dashboard">
                <button class="nav-toggle-btn" onclick="toggleNavGroup('group-dashboard')" title="<?php echo t('sidebar.group_dashboard'); ?>">
                    <span class="toggle-title">
                        <i class="fas fa-chart-pie" style="color: var(--primary);"></i> <span class="nav-text"><?php echo t('sidebar.group_dashboard'); ?></span>
                    </span>
                    <i class="fas fa-chevron-down chevron-icon"></i>
                </button>
                <ul class="nav-submenu">
                    <?php if (hasModulePermission('dashboard', 'view')): ?>
                        <li>
                            <a href="/dashboard" class="nav-link <?php echo $active_page === 'dashboard.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_dashboard_overview'); ?>">
                                <i class="fas fa-tachometer-alt"></i> <span class="nav-text"><?php echo t('sidebar.item_dashboard_overview'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('my_phone', 'view')): ?>
                        <li>
                            <a href="/my-phone" class="nav-link <?php echo $active_page === 'my_phone.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_my_phone'); ?>">
                                <i class="fas fa-phone-volume"></i> <span class="nav-text"><?php echo t('sidebar.item_my_phone'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('chat', 'view')): ?>
                        <li>
                            <a href="/chat" class="nav-link <?php echo $active_page === 'chat.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_chat'); ?>">
                                <i class="fas fa-comments"></i> <span class="nav-text"><?php echo t('sidebar.item_chat'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('cdr_reports', 'view') || hasModulePermission('cc_reports', 'view')): ?>
                        <li>
                            <a href="/cdr-reports" class="nav-link <?php echo ($active_page === 'cdr_reports.php' || $active_page === 'reports.php') ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_cdr_reports'); ?>">
                                <i class="fas fa-file-audio"></i> <span class="nav-text"><?php echo t('sidebar.item_cdr_reports'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </li>
        <?php endif; ?>

        <!-- 2. Dış Hat Yönetimi Menüsü -->
        <?php if ($can_view_trunks_group): ?>
            <li class="nav-group <?php echo ($is_trunk_active && !$is_collapsed_cookie) ? 'open' : ''; ?>" id="group-trunks">
                <button class="nav-toggle-btn" onclick="toggleNavGroup('group-trunks')" title="<?php echo t('sidebar.group_trunks'); ?>">
                    <span class="toggle-title">
                        <i class="fas fa-network-wired" style="color: var(--primary);"></i> <span class="nav-text"><?php echo t('sidebar.group_trunks'); ?></span>
                    </span>
                    <i class="fas fa-chevron-down chevron-icon"></i>
                </button>
                <ul class="nav-submenu">
                    <?php if (hasModulePermission('trunks', 'view')): ?>
                        <li>
                            <a href="/trunks" class="nav-link <?php echo $active_page === 'trunks.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_trunks'); ?>">
                                <i class="fas fa-server"></i> <span class="nav-text"><?php echo t('sidebar.item_trunks'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('did_routes', 'view')): ?>
                        <li>
                            <a href="/did-routes" class="nav-link <?php echo $active_page === 'did_routes.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_did_routes'); ?>">
                                <i class="fas fa-route"></i> <span class="nav-text"><?php echo t('sidebar.item_did_routes'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('outbound_routes', 'view')): ?>
                        <li>
                            <a href="/outbound-routes" class="nav-link <?php echo $active_page === 'outbound_routes.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_outbound_routes'); ?>">
                                <i class="fas fa-sign-out-alt"></i> <span class="nav-text"><?php echo t('sidebar.item_outbound_routes'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </li>
        <?php endif; ?>

        <!-- 3. PBX Yönetimi Menüsü -->
        <?php if ($can_view_pbx_group): ?>
            <li class="nav-group <?php echo ($is_pbx_active && !$is_collapsed_cookie) ? 'open' : ''; ?>" id="group-pbx">
                <button class="nav-toggle-btn" onclick="toggleNavGroup('group-pbx')" title="<?php echo t('sidebar.group_pbx'); ?>">
                    <span class="toggle-title">
                        <i class="fas fa-phone-alt" style="color: var(--primary);"></i> <span class="nav-text"><?php echo t('sidebar.group_pbx'); ?></span>
                    </span>
                    <i class="fas fa-chevron-down chevron-icon"></i>
                </button>
                <ul class="nav-submenu">
                    <?php if (hasModulePermission('time_conditions', 'view')): ?>
                        <li>
                            <a href="/time-conditions" class="nav-link <?php echo $active_page === 'time_conditions.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_time_conditions'); ?>">
                                <i class="fas fa-clock"></i> <span class="nav-text"><?php echo t('sidebar.item_time_conditions'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('ivrs', 'view')): ?>
                        <li>
                            <a href="/ivrs" class="nav-link <?php echo $active_page === 'ivrs.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_ivrs'); ?>">
                                <i class="fas fa-microphone-alt"></i> <span class="nav-text"><?php echo t('sidebar.item_ivrs'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('extensions', 'view')): ?>
                        <li>
                            <a href="/extensions" class="nav-link <?php echo ($active_page === 'extensions.php' || $active_page === 'users.php') ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_extensions'); ?>">
                                <i class="fas fa-phone-square-alt"></i> <span class="nav-text"><?php echo t('sidebar.item_extensions'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('queues', 'view')): ?>
                        <li>
                            <a href="/queues" class="nav-link <?php echo $active_page === 'queues.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_queues'); ?>">
                                <i class="fas fa-layer-group"></i> <span class="nav-text"><?php echo t('sidebar.item_queues'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('sounds', 'view')): ?>
                        <li>
                            <a href="/sounds" class="nav-link <?php echo $active_page === 'sounds.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_sounds'); ?>">
                                <i class="fas fa-music"></i> <span class="nav-text"><?php echo t('sidebar.item_sounds'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('end_call', 'view')): ?>
                        <li>
                            <a href="/end-call" class="nav-link <?php echo $active_page === 'end_call.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_end_call'); ?>">
                                <i class="fas fa-phone-slash"></i> <span class="nav-text"><?php echo t('sidebar.item_end_call'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('feature_codes', 'view')): ?>
                        <li>
                            <a href="/feature-codes" class="nav-link <?php echo $active_page === 'feature_codes.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_feature_codes_tooltip'); ?>">
                                <i class="fas fa-hashtag"></i> <span class="nav-text"><?php echo t('sidebar.item_feature_codes'); ?></span>
                            </a>
                        </li>
                        <li>
                            <a href="/feature-codes-status" class="nav-link <?php echo $active_page === 'feature_codes_status.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_feature_codes_status'); ?>">
                                <i class="fas fa-list-check"></i> <span class="nav-text"><?php echo t('sidebar.item_feature_codes_status'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </li>
        <?php endif; ?>

        <!-- 4. Yönetim Menüsü -->
        <?php if ($can_view_admin_group): ?>
            <li class="nav-group <?php echo ($is_admin_active && !$is_collapsed_cookie) ? 'open' : ''; ?>" id="group-admin">
                <button class="nav-toggle-btn" onclick="toggleNavGroup('group-admin')" title="<?php echo t('sidebar.group_admin'); ?>">
                    <span class="toggle-title">
                        <i class="fas fa-user-shield" style="color: var(--warning);"></i> <span class="nav-text"><?php echo t('sidebar.group_admin'); ?></span>
                    </span>
                    <i class="fas fa-chevron-down chevron-icon"></i>
                </button>
                <ul class="nav-submenu">
                    <?php if (hasModulePermission('system_users', 'view')): ?>
                        <li>
                            <a href="/system-users" class="nav-link <?php echo $active_page === 'system_users.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_system_users'); ?>">
                                <i class="fas fa-users-cog"></i> <span class="nav-text"><?php echo t('sidebar.item_system_users'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('roles', 'view')): ?>
                        <li>
                            <a href="/roles" class="nav-link <?php echo $active_page === 'roles.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_roles'); ?>">
                                <i class="fas fa-user-shield"></i> <span class="nav-text"><?php echo t('sidebar.item_roles'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('asterisk_settings', 'view')): ?>
                        <li>
                            <a href="/asterisk-settings" class="nav-link <?php echo $active_page === 'asterisk_settings.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_asterisk_settings'); ?>">
                                <i class="fas fa-cogs"></i> <span class="nav-text"><?php echo t('sidebar.item_asterisk_settings'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('brand_settings', 'view')): ?>
                        <li>
                            <a href="/brand-settings" class="nav-link <?php echo $active_page === 'brand_settings.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_brand_settings'); ?>">
                                <i class="fas fa-palette"></i> <span class="nav-text"><?php echo t('sidebar.item_brand_settings'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('push_settings', 'view')): ?>
                        <li>
                            <a href="/push-settings" class="nav-link <?php echo $active_page === 'push_settings.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_push_settings', 'Mobil Bildirim'); ?>">
                                <i class="fas fa-bell"></i> <span class="nav-text"><?php echo t('sidebar.item_push_settings', 'Mobil Bildirim'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('fax_mail_settings', 'view')): ?>
                        <li>
                            <a href="/fax-mail-settings" class="nav-link <?php echo $active_page === 'fax_mail_settings.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_fax_mail_settings'); ?>">
                                <i class="fas fa-paper-plane"></i> <span class="nav-text"><?php echo t('sidebar.item_fax_mail_settings'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('pending_sync', 'view')): ?>
                        <li>
                            <a href="/pending-sync" class="nav-link <?php echo $active_page === 'pending_sync.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_pending_sync'); ?>">
                                <i class="fas fa-cloud-upload-alt"></i> <span class="nav-text"><?php echo t('sidebar.item_pending_sync'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('audit_log', 'view')): ?>
                        <li>
                            <a href="/audit-log" class="nav-link <?php echo $active_page === 'audit_log.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_audit_log'); ?>">
                                <i class="fas fa-shield-alt"></i> <span class="nav-text"><?php echo t('sidebar.item_audit_log'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </li>
        <?php endif; ?>

        <!-- 4b. Güvenlik Menüsü (Firewall + fail2ban, 2026-08-31, kullanıcı isteği) -->
        <?php if ($can_view_security_group): ?>
            <li class="nav-group <?php echo ($is_security_active && !$is_collapsed_cookie) ? 'open' : ''; ?>" id="group-security">
                <button class="nav-toggle-btn" onclick="toggleNavGroup('group-security')" title="<?php echo t('sidebar.group_security'); ?>">
                    <span class="toggle-title">
                        <i class="fas fa-shield-halved" style="color: var(--danger);"></i> <span class="nav-text"><?php echo t('sidebar.group_security'); ?></span>
                    </span>
                    <i class="fas fa-chevron-down chevron-icon"></i>
                </button>
                <ul class="nav-submenu">
                    <li>
                        <a href="/firewall" class="nav-link <?php echo $active_page === 'firewall.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_firewall'); ?>">
                            <i class="fas fa-fire"></i> <span class="nav-text"><?php echo t('sidebar.item_firewall'); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="/fail2ban" class="nav-link <?php echo $active_page === 'fail2ban.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_fail2ban'); ?>">
                            <i class="fas fa-user-shield"></i> <span class="nav-text"><?php echo t('sidebar.item_fail2ban'); ?></span>
                        </a>
                    </li>
                </ul>
            </li>
        <?php endif; ?>

        <!-- 4c. Entegrasyonlar Menüsü (Microsoft Teams) -->
        <?php if ($can_view_teams_group): ?>
            <li class="nav-group <?php echo ($is_teams_active && !$is_collapsed_cookie) ? 'open' : ''; ?>" id="group-integrations">
                <button class="nav-toggle-btn" onclick="toggleNavGroup('group-integrations')" title="<?php echo t('sidebar.group_integrations', 'Entegrasyonlar'); ?>">
                    <span class="toggle-title">
                        <i class="fab fa-microsoft" style="color: #6264a7;"></i> <span class="nav-text"><?php echo t('sidebar.group_integrations', 'Entegrasyonlar'); ?></span>
                    </span>
                    <i class="fas fa-chevron-down chevron-icon"></i>
                </button>
                <ul class="nav-submenu">
                    <li>
                        <a href="/ms-teams" class="nav-link <?php echo $active_page === 'ms_teams.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_ms_teams', 'Microsoft Teams'); ?>">
                            <i class="fab fa-windows" style="color: #6264a7;"></i> <span class="nav-text"><?php echo t('sidebar.item_ms_teams', 'Microsoft Teams'); ?></span>
                        </a>
                    </li>
                </ul>
            </li>
        <?php endif; ?>

        <!-- 5. Faks Sistemi Menüsü -->
        <?php if ($can_view_fax_group): ?>
            <li class="nav-group <?php echo ($is_fax_active && !$is_collapsed_cookie) ? 'open' : ''; ?>" id="group-fax">
                <button class="nav-toggle-btn" onclick="toggleNavGroup('group-fax')" title="<?php echo t('sidebar.group_fax'); ?>">
                    <span class="toggle-title">
                        <i class="fas fa-fax" style="color: var(--primary);"></i> <span class="nav-text"><?php echo t('sidebar.group_fax'); ?></span>
                    </span>
                    <i class="fas fa-chevron-down chevron-icon"></i>
                </button>
                <ul class="nav-submenu">
                    <?php if (hasModulePermission('fax_inbox', 'view')): ?>
                        <li>
                            <a href="/fax-inbox" class="nav-link <?php echo $active_page === 'fax_inbox.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_fax_inbox'); ?>">
                                <i class="fas fa-inbox"></i> <span class="nav-text"><?php echo t('sidebar.item_fax_inbox'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('fax_send', 'view')): ?>
                        <li>
                            <a href="/fax-send" class="nav-link <?php echo $active_page === 'fax_send.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_fax_send'); ?>">
                                <i class="fas fa-paper-plane"></i> <span class="nav-text"><?php echo t('sidebar.item_fax_send'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('fax_sent', 'view')): ?>
                        <li>
                            <a href="/fax-sent" class="nav-link <?php echo $active_page === 'fax_sent.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_fax_sent'); ?>">
                                <i class="fas fa-history"></i> <span class="nav-text"><?php echo t('sidebar.item_fax_sent'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </li>
        <?php endif; ?>

        <!-- 6. Çağrı Merkezi Menüsü -->
        <?php 
        $can_view_supervisor_monitor = (
            !empty($user['can_view_queue_monitor']) ||
            hasModulePermission('queue_monitor', 'view')
        );
        if (!$can_view_supervisor_monitor && !empty($user['extension'])) {
            $db_sb = getDB();
            $stmt_sb = $db_sb->query("SELECT supervisors_json, supervisor_extension FROM pbx_queues WHERE is_active = 1");
            $q_sups = $stmt_sb->fetchAll(PDO::FETCH_ASSOC);
            foreach ($q_sups as $qs) {
                $s_list = json_decode($qs['supervisors_json'] ?? '[]', true) ?: [];
                if (!empty($qs['supervisor_extension']) && !in_array($qs['supervisor_extension'], $s_list)) {
                    $s_list[] = $qs['supervisor_extension'];
                }
                if (in_array((string)$user['extension'], array_map('strval', $s_list))) {
                    $can_view_supervisor_monitor = true;
                    break;
                }
            }
        }
        if ($can_view_cc_group || $can_view_supervisor_monitor): 
        ?>
            <li class="nav-group <?php echo (($is_cc_active || $active_page === 'cc_supervisor.php') && !$is_collapsed_cookie) ? 'open' : ''; ?>" id="group-cc">
                <button class="nav-toggle-btn" onclick="toggleNavGroup('group-cc')" title="<?php echo t('sidebar.group_cc'); ?>">
                    <span class="toggle-title">
                        <i class="fas fa-headset" style="color: var(--success);"></i> <span class="nav-text"><?php echo t('sidebar.group_cc'); ?></span>
                    </span>
                    <i class="fas fa-chevron-down chevron-icon"></i>
                </button>
                <ul class="nav-submenu">
                    <?php if ($can_view_supervisor_monitor || hasModulePermission('cc_board', 'view')): ?>
                        <li>
                            <a href="/cc-board" class="nav-link <?php echo ($active_page === 'cc_board.php' || $active_page === 'cc_supervisor.php') ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_cc_board_unified_tooltip'); ?>">
                                <i class="fas fa-chart-line" style="color: var(--primary);"></i> <span class="nav-text"><?php echo t('sidebar.item_cc_board_unified'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('cc_agent', 'view')): ?>
                        <li>
                            <a href="/cc-agent" class="nav-link <?php echo $active_page === 'cc_agent.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_cc_agent'); ?>">
                                <i class="fas fa-phone-alt"></i> <span class="nav-text"><?php echo t('sidebar.item_cc_agent'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('pause_reports', 'view')): ?>
                        <li>
                            <a href="/pause-reports" class="nav-link <?php echo $active_page === 'pause_reports.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_pause_reports'); ?>">
                                <i class="fas fa-coffee"></i> <span class="nav-text"><?php echo t('sidebar.item_pause_reports'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (hasModulePermission('queue_logs', 'view')): ?>
                        <li>
                            <a href="/queue-logs" class="nav-link <?php echo $active_page === 'queue_logs.php' ? 'active' : ''; ?>" title="<?php echo t('sidebar.item_queue_logs'); ?>">
                                <i class="fas fa-list-alt"></i> <span class="nav-text"><?php echo t('sidebar.item_queue_logs'); ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </li>
        <?php endif; ?>
    </ul>
</aside>
