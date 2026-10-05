<!-- System Status Summary Banner -->
<div class="card page-header-card" style="padding: 12px 18px; margin-bottom: 20px;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; width: 100%;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 36px; height: 36px; background: rgba(0, 242, 254, 0.1); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 16px;">
                <i class="fas fa-server"></i>
            </div>
            <div style="font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                <span><?php echo t('dashboard.system_status'); ?></span>
                <?php if ($is_asterisk_running): ?>
                    <span class="badge badge-success u-fs-11"><i class="fas fa-check-circle"></i> <?php echo t('dashboard.status_active'); ?></span>
                <?php else: ?>
                    <span class="badge badge-danger u-fs-11"><i class="fas fa-exclamation-triangle"></i> <?php echo t('dashboard.status_down'); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="u-flex-center">
            <form method="POST" autocomplete="off" class="u-m-0 u-inline">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="system_action" value="reload_asterisk">
                <button type="submit" class="btn btn-outline-primary btn-sm" title="<?php echo htmlspecialchars(t('dashboard.reload_tooltip')); ?>" style="display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fas fa-sync-alt"></i> <span class="btn-label"><?php echo t('dashboard.reload_button'); ?></span>
                </button>
            </form>
            <button type="button" class="btn-help" onclick="toggleModuleHelp('dashHelpBox')" title="<?php echo t('common.module_guide', 'Modül Rehberi'); ?>">
                <i class="fas fa-question-circle"></i>
            </button>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="dashHelpBox" style="width: 100%; margin-top: 14px; margin-bottom: 0;">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('dashboard.help_title'); ?></h4>
        <p style="margin: 0 0 8px 0; font-size: 13px;"><?php echo t('dashboard.help_intro'); ?></p>
        <ul style="margin: 0; padding-left: 20px; font-size: 12px; line-height: 1.6;">
            <li><strong><?php echo t('dashboard.help_reload'); ?></strong>: <?php echo t('dashboard.help_reload_desc'); ?></li>
            <li><strong><?php echo t('dashboard.help_services'); ?></strong>: <?php echo t('dashboard.help_services_desc'); ?></li>
        </ul>
    </div>
</div>

<!-- Live call status (refreshed from /api/dashboard_live.php) -->
<div class="u-flex-between u-mb-10">
    <div style="font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 8px;"><span class="live-dot"></span> <?php echo t('dashboard.live_title'); ?></div>
    <small class="u-muted u-fs-11"><?php echo t('dashboard.live_auto_refresh'); ?></small>
</div>
<div id="dashLive" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px;">
    <div class="card" style="margin-bottom: 0; padding: 16px 20px;">
        <div class="u-flex-between">
            <div class="u-muted u-fs-12 u-fw-700 u-uppercase"><?php echo t('dashboard.live_active_calls'); ?></div>
            <div style="width: 32px; height: 32px; background: rgba(16, 185, 129, 0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--success); font-size: 14px;">
                <i class="fas fa-phone-volume"></i>
            </div>
        </div>
        <div class="u-fs-26 u-fw-800 u-mt-6 u-success" data-live="active_calls"><?php echo (int)$live['active_calls']; ?></div>
    </div>
    <div class="card" style="margin-bottom: 0; padding: 16px 20px;">
        <div class="u-flex-between">
            <div class="u-muted u-fs-12 u-fw-700 u-uppercase"><?php echo t('dashboard.live_active_channels'); ?></div>
            <div style="width: 32px; height: 32px; background: rgba(0, 242, 254, 0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 14px;">
                <i class="fas fa-stream"></i>
            </div>
        </div>
        <div class="u-fs-26 u-fw-800 u-mt-6 u-primary" data-live="active_channels"><?php echo (int)$live['active_channels']; ?></div>
        <div class="u-fs-12 u-muted u-mt-2"><?php echo t('dashboard.live_processed'); ?>: <strong data-live="calls_processed"><?php echo (int)$live['calls_processed']; ?></strong></div>
    </div>
    <div class="card" style="margin-bottom: 0; padding: 16px 20px;">
        <div class="u-flex-between">
            <div class="u-muted u-fs-12 u-fw-700 u-uppercase"><?php echo t('dashboard.live_queue_waiting'); ?></div>
            <div style="width: 32px; height: 32px; background: rgba(245, 158, 11, 0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--warning); font-size: 14px;">
                <i class="fas fa-user-clock"></i>
            </div>
        </div>
        <div class="u-fs-26 u-fw-800 u-mt-6 u-warning" data-live="queue_waiting"><?php echo (int)$live['queue_waiting']; ?></div>
    </div>
    <div class="card" style="margin-bottom: 0; padding: 16px 20px;">
        <div class="u-flex-between">
            <div class="u-muted u-fs-12 u-fw-700 u-uppercase"><?php echo t('dashboard.live_today'); ?></div>
            <div style="width: 32px; height: 32px; background: rgba(59, 130, 246, 0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--text-main); font-size: 14px;">
                <i class="fas fa-calendar-day"></i>
            </div>
        </div>
        <div class="u-fs-26 u-fw-800 u-mt-6 u-text-main" data-live="today_total"><?php echo (int)$live['today_total']; ?></div>
        <div class="u-fs-12 u-muted u-mt-2">
            <span class="u-success"><?php echo t('dashboard.live_answered'); ?>: <strong data-live="today_answered"><?php echo (int)$live['today_answered']; ?></strong></span> ·
            <span class="u-danger"><?php echo t('dashboard.live_missed'); ?>: <strong data-live="today_missed"><?php echo (int)$live['today_missed']; ?></strong></span>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom: 24px; padding: 16px 20px;">
    <div class="u-muted u-fs-12 u-fw-700 u-uppercase u-mb-10"><i class="fas fa-network-wired"></i> <?php echo t('dashboard.live_trunk_usage'); ?></div>
    <div id="dashTrunkUsage" class="dash-trunk-usage"></div>
</div>
<script>
window.DASHBOARD_PAGE = {
    labels: { free: <?php echo json_encode(t('dashboard.live_trunk_idle')); ?>, none: <?php echo json_encode(t('dashboard.live_no_trunks')); ?> },
    live: <?php echo json_encode($live, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>
};
</script>
<script src="<?php echo asset('/assets/js/dashboard.js'); ?>"></script>

<!-- 1. Total count summary cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <!-- Users -->
    <div class="card" style="margin-bottom: 0; padding: 16px 20px;">
        <div class="u-flex-between">
            <div class="u-muted u-fs-12 u-fw-700 u-uppercase"><?php echo t('dashboard.card_users'); ?></div>
            <div style="width: 32px; height: 32px; background: rgba(59, 130, 246, 0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--secondary); font-size: 14px;">
                <i class="fas fa-users-cog"></i>
            </div>
        </div>
        <div class="u-fs-26 u-fw-800 u-mt-6 u-text-main"><?php echo $user_count; ?></div>
    </div>

    <!-- Dahililer -->
    <div class="card" style="margin-bottom: 0; padding: 16px 20px;">
        <div class="u-flex-between">
            <div class="u-muted u-fs-12 u-fw-700 u-uppercase"><?php echo t('dashboard.card_extensions'); ?></div>
            <div style="width: 32px; height: 32px; background: rgba(0, 242, 254, 0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 14px;">
                <i class="fas fa-phone-square-alt"></i>
            </div>
        </div>
        <div class="u-fs-26 u-fw-800 u-mt-6 u-primary"><?php echo $online_pjsip_count; ?> <span class="u-fs-13 u-fw-600 u-muted">/ <?php echo $ext_count; ?></span></div>
    </div>

    <!-- Trunks -->
    <div class="card" style="margin-bottom: 0; padding: 16px 20px;">
        <div class="u-flex-between">
            <div class="u-muted u-fs-12 u-fw-700 u-uppercase"><?php echo t('dashboard.card_trunks'); ?></div>
            <div style="width: 32px; height: 32px; background: rgba(16, 185, 129, 0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--success); font-size: 14px;">
                <i class="fas fa-network-wired"></i>
            </div>
        </div>
        <div class="u-fs-26 u-fw-800 u-mt-6 u-success"><?php echo $online_trunk_count; ?> <span class="u-fs-13 u-fw-600 u-muted">/ <?php echo $trunk_count; ?></span></div>
    </div>

    <!-- Gelen Fakslar -->
    <div class="card" style="margin-bottom: 0; padding: 16px 20px;">
        <div class="u-flex-between">
            <div class="u-muted u-fs-12 u-fw-700 u-uppercase"><?php echo t('dashboard.card_fax_in'); ?></div>
            <div style="width: 32px; height: 32px; background: rgba(245, 158, 11, 0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--warning); font-size: 14px;">
                <i class="fas fa-inbox"></i>
            </div>
        </div>
        <div class="u-fs-26 u-fw-800 u-mt-6 u-warning"><?php echo $fax_rx_count; ?></div>
    </div>
</div>

<!-- 3. Server info and service management grid -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 24px;">
    <!-- Sunucu Metrikleri -->
    <div class="card u-mb-0">
        <div class="card-header" style="padding-bottom: 12px;">
            <div class="card-title">
                <i class="fas fa-microchip u-primary"></i> <?php echo t('dashboard.server_metrics'); ?>
            </div>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div style="background: var(--bg-input); border: 1px solid var(--border-color); padding: 12px 14px; border-radius: 10px;">
                <div class="u-muted u-fw-600 u-fs-11"><?php echo t('dashboard.metric_version'); ?></div>
                <div class="u-fs-14 u-fw-700 u-primary u-mt-2"><?php echo htmlspecialchars($asterisk_version); ?></div>
            </div>
            <div style="background: var(--bg-input); border: 1px solid var(--border-color); padding: 12px 14px; border-radius: 10px;">
                <div class="u-muted u-fw-600 u-fs-11"><?php echo t('dashboard.metric_ram'); ?></div>
                <div class="u-fs-14 u-fw-700 u-success u-mt-2"><?php echo htmlspecialchars($ram_info); ?></div>
            </div>
            <div style="background: var(--bg-input); border: 1px solid var(--border-color); padding: 12px 14px; border-radius: 10px;">
                <div class="u-muted u-fw-600 u-fs-11"><?php echo t('dashboard.metric_disk'); ?></div>
                <div class="u-fs-14 u-fw-700 u-warning u-mt-2"><?php echo htmlspecialchars($disk_info); ?></div>
            </div>
            <div style="background: var(--bg-input); border: 1px solid var(--border-color); padding: 12px 14px; border-radius: 10px;">
                <div class="u-muted u-fw-600 u-fs-11"><?php echo t('dashboard.metric_uptime'); ?></div>
                <div class="u-fs-14 u-fw-700 u-text-main u-mt-2"><?php echo htmlspecialchars($uptime_info); ?></div>
            </div>
        </div>
    </div>

    <!-- Servis Restarts ve Reboot -->
    <div class="card u-mb-0">
        <div class="card-header" style="padding-bottom: 12px;">
            <div class="card-title">
                <i class="fas fa-cogs u-primary"></i> <?php echo t('dashboard.service_control'); ?>
            </div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">
            <form method="POST" autocomplete="off" onsubmit="return confirm('<?php echo htmlspecialchars(t('dashboard.confirm_restart_asterisk'), ENT_QUOTES); ?>');">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="system_action" value="restart_service">
                <input type="hidden" name="service_name" value="asterisk">
                <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%; justify-content: center; gap: 6px;" title="<?php echo htmlspecialchars(t('dashboard.tooltip_restart_asterisk')); ?>">
                    <i class="fas fa-phone-alt u-primary"></i> <?php echo t('dashboard.restart_asterisk'); ?>
                </button>
            </form>

            <form method="POST" autocomplete="off" onsubmit="return confirm('<?php echo htmlspecialchars(t('dashboard.confirm_restart_httpd'), ENT_QUOTES); ?>');">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="system_action" value="restart_service">
                <input type="hidden" name="service_name" value="apache2">
                <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%; justify-content: center; gap: 6px;" title="<?php echo htmlspecialchars(t('dashboard.tooltip_restart_httpd')); ?>">
                    <i class="fas fa-globe" style="color: var(--secondary);"></i> <?php echo t('dashboard.restart_httpd'); ?>
                </button>
            </form>

            <form method="POST" autocomplete="off" onsubmit="return confirm('<?php echo htmlspecialchars(t('dashboard.confirm_restart_mariadb'), ENT_QUOTES); ?>');">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="system_action" value="restart_service">
                <input type="hidden" name="service_name" value="mariadb">
                <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%; justify-content: center; gap: 6px;" title="<?php echo htmlspecialchars(t('dashboard.tooltip_restart_mariadb')); ?>">
                    <i class="fas fa-database u-warning"></i> <?php echo t('dashboard.restart_mariadb'); ?>
                </button>
            </form>

            <form method="POST" autocomplete="off" onsubmit="return confirm('<?php echo htmlspecialchars(t('dashboard.confirm_restart_postfix'), ENT_QUOTES); ?>');">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="system_action" value="restart_service">
                <input type="hidden" name="service_name" value="postfix">
                <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%; justify-content: center; gap: 6px;" title="<?php echo htmlspecialchars(t('dashboard.tooltip_restart_postfix')); ?>">
                    <i class="fas fa-paper-plane u-success"></i> <?php echo t('dashboard.restart_postfix'); ?>
                </button>
            </form>
        </div>
    </div>
</div>
