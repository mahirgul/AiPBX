<!-- System Status Summary Banner -->
<div class="card page-header-card">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="width: 44px; height: 44px; background: rgba(0, 242, 254, 0.1); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 20px;">
                <i class="fas fa-server"></i>
            </div>
            <div>
                <div style="font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                    <?php echo t('dashboard.system_status'); ?>
                    <?php if ($is_asterisk_running): ?>
                        <span class="badge badge-success" style="font-size: 11px;"><i class="fas fa-check-circle"></i> <?php echo t('dashboard.status_active'); ?></span>
                    <?php else: ?>
                        <span class="badge badge-danger" style="font-size: 11px;"><i class="fas fa-exclamation-triangle"></i> <?php echo t('dashboard.status_down'); ?></span>
                    <?php endif; ?>
                </div>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                    <?php echo t('dashboard.extensions_label'); ?>: <strong style="color: var(--primary);"><?php echo $online_pjsip_count; ?> / <?php echo $ext_count; ?> <?php echo t('dashboard.online_suffix'); ?></strong> | <?php echo t('dashboard.mail_relay'); ?>: <strong style="color: <?php echo !empty($mail_relay_host) ? 'var(--success)' : 'var(--text-muted)'; ?>;"><?php echo htmlspecialchars($mail_relay_host ?? t('dashboard.not_configured')); ?></strong>
                </div>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            <form method="POST" autocomplete="off" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="system_action" value="reload_asterisk">
                <button type="submit" class="btn btn-primary btn-sm" title="<?php echo htmlspecialchars(t('dashboard.reload_tooltip')); ?>">
                    <i class="fas fa-sync-alt"></i> <span class="btn-label"><?php echo t('dashboard.reload_button'); ?></span>
                </button>
            </form>
            <button type="button" class="btn-help" onclick="toggleModuleHelp('dashHelpBox')" title="Modül Rehberi">
                <i class="fas fa-question-circle"></i>
            </button>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="dashHelpBox" style="margin-top: 16px; margin-bottom: 0;">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('dashboard.help_title'); ?></h4>
        <p style="margin: 0 0 8px 0; font-size: 13px;"><?php echo t('dashboard.help_intro'); ?></p>
        <ul style="margin: 0; padding-left: 20px; font-size: 12px; line-height: 1.6;">
            <li><strong><?php echo t('dashboard.help_reload'); ?></strong>: <?php echo t('dashboard.help_reload_desc'); ?></li>
            <li><strong><?php echo t('dashboard.help_services'); ?></strong>: <?php echo t('dashboard.help_services_desc'); ?></li>
        </ul>
    </div>
</div>

<!-- 1. Toplam Sayı Özet Kartları -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <!-- Kullanıcılar -->
    <div class="card" style="margin-bottom: 0; padding: 16px 20px;">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div style="color: var(--text-muted); font-size: 12px; font-weight: 700; text-transform: uppercase;"><?php echo t('dashboard.card_users'); ?></div>
            <div style="width: 32px; height: 32px; background: rgba(59, 130, 246, 0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--secondary); font-size: 14px;">
                <i class="fas fa-users-cog"></i>
            </div>
        </div>
        <div style="font-size: 26px; font-weight: 800; margin-top: 6px; color: var(--text-main);"><?php echo $user_count; ?></div>
    </div>

    <!-- Dahililer -->
    <div class="card" style="margin-bottom: 0; padding: 16px 20px;">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div style="color: var(--text-muted); font-size: 12px; font-weight: 700; text-transform: uppercase;"><?php echo t('dashboard.card_extensions'); ?></div>
            <div style="width: 32px; height: 32px; background: rgba(0, 242, 254, 0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 14px;">
                <i class="fas fa-phone-square-alt"></i>
            </div>
        </div>
        <div style="font-size: 26px; font-weight: 800; margin-top: 6px; color: var(--primary);"><?php echo $online_pjsip_count; ?> <span style="font-size: 13px; font-weight: 600; color: var(--text-muted);">/ <?php echo $ext_count; ?></span></div>
    </div>

    <!-- Dış Hatlar -->
    <div class="card" style="margin-bottom: 0; padding: 16px 20px;">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div style="color: var(--text-muted); font-size: 12px; font-weight: 700; text-transform: uppercase;"><?php echo t('dashboard.card_trunks'); ?></div>
            <div style="width: 32px; height: 32px; background: rgba(16, 185, 129, 0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--success); font-size: 14px;">
                <i class="fas fa-network-wired"></i>
            </div>
        </div>
        <div style="font-size: 26px; font-weight: 800; margin-top: 6px; color: var(--success);"><?php echo $online_trunk_count; ?> <span style="font-size: 13px; font-weight: 600; color: var(--text-muted);">/ <?php echo $trunk_count; ?></span></div>
    </div>

    <!-- Gelen Fakslar -->
    <div class="card" style="margin-bottom: 0; padding: 16px 20px;">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div style="color: var(--text-muted); font-size: 12px; font-weight: 700; text-transform: uppercase;"><?php echo t('dashboard.card_fax_in'); ?></div>
            <div style="width: 32px; height: 32px; background: rgba(245, 158, 11, 0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--warning); font-size: 14px;">
                <i class="fas fa-inbox"></i>
            </div>
        </div>
        <div style="font-size: 26px; font-weight: 800; margin-top: 6px; color: var(--warning);"><?php echo $fax_rx_count; ?></div>
    </div>
</div>

<!-- 3. Sunucu Bilgileri ve Servis Yönetimi Grid -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 24px;">
    <!-- Sunucu Metrikleri -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header" style="padding-bottom: 12px;">
            <div class="card-title">
                <i class="fas fa-microchip" style="color: var(--primary);"></i> <?php echo t('dashboard.server_metrics'); ?>
            </div>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div style="background: var(--bg-input); border: 1px solid var(--border-color); padding: 12px 14px; border-radius: 10px;">
                <div style="font-size: 11px; color: var(--text-muted); font-weight: 600;"><?php echo t('dashboard.metric_version'); ?></div>
                <div style="font-size: 14px; font-weight: 700; color: var(--primary); margin-top: 2px;"><?php echo htmlspecialchars($asterisk_version); ?></div>
            </div>
            <div style="background: var(--bg-input); border: 1px solid var(--border-color); padding: 12px 14px; border-radius: 10px;">
                <div style="font-size: 11px; color: var(--text-muted); font-weight: 600;"><?php echo t('dashboard.metric_ram'); ?></div>
                <div style="font-size: 14px; font-weight: 700; color: var(--success); margin-top: 2px;"><?php echo htmlspecialchars($ram_info); ?></div>
            </div>
            <div style="background: var(--bg-input); border: 1px solid var(--border-color); padding: 12px 14px; border-radius: 10px;">
                <div style="font-size: 11px; color: var(--text-muted); font-weight: 600;"><?php echo t('dashboard.metric_disk'); ?></div>
                <div style="font-size: 14px; font-weight: 700; color: var(--warning); margin-top: 2px;"><?php echo htmlspecialchars($disk_info); ?></div>
            </div>
            <div style="background: var(--bg-input); border: 1px solid var(--border-color); padding: 12px 14px; border-radius: 10px;">
                <div style="font-size: 11px; color: var(--text-muted); font-weight: 600;"><?php echo t('dashboard.metric_uptime'); ?></div>
                <div style="font-size: 14px; font-weight: 700; color: var(--text-main); margin-top: 2px;"><?php echo htmlspecialchars($uptime_info); ?></div>
            </div>
        </div>
    </div>

    <!-- Servis Restarts ve Reboot -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header" style="padding-bottom: 12px;">
            <div class="card-title">
                <i class="fas fa-cogs" style="color: var(--primary);"></i> <?php echo t('dashboard.service_control'); ?>
            </div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">
            <form method="POST" autocomplete="off" onsubmit="return confirm('<?php echo htmlspecialchars(t('dashboard.confirm_restart_asterisk'), ENT_QUOTES); ?>');">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="system_action" value="restart_service">
                <input type="hidden" name="service_name" value="asterisk">
                <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%; justify-content: center; gap: 6px;" title="<?php echo htmlspecialchars(t('dashboard.tooltip_restart_asterisk')); ?>">
                    <i class="fas fa-phone-alt" style="color: var(--primary);"></i> <?php echo t('dashboard.restart_asterisk'); ?>
                </button>
            </form>

            <form method="POST" autocomplete="off" onsubmit="return confirm('<?php echo htmlspecialchars(t('dashboard.confirm_restart_httpd'), ENT_QUOTES); ?>');">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="system_action" value="restart_service">
                <input type="hidden" name="service_name" value="httpd">
                <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%; justify-content: center; gap: 6px;" title="<?php echo htmlspecialchars(t('dashboard.tooltip_restart_httpd')); ?>">
                    <i class="fas fa-globe" style="color: var(--secondary);"></i> <?php echo t('dashboard.restart_httpd'); ?>
                </button>
            </form>

            <form method="POST" autocomplete="off" onsubmit="return confirm('<?php echo htmlspecialchars(t('dashboard.confirm_restart_mariadb'), ENT_QUOTES); ?>');">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="system_action" value="restart_service">
                <input type="hidden" name="service_name" value="mariadb">
                <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%; justify-content: center; gap: 6px;" title="<?php echo htmlspecialchars(t('dashboard.tooltip_restart_mariadb')); ?>">
                    <i class="fas fa-database" style="color: var(--warning);"></i> <?php echo t('dashboard.restart_mariadb'); ?>
                </button>
            </form>

            <form method="POST" autocomplete="off" onsubmit="return confirm('<?php echo htmlspecialchars(t('dashboard.confirm_restart_postfix'), ENT_QUOTES); ?>');">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="system_action" value="restart_service">
                <input type="hidden" name="service_name" value="postfix">
                <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%; justify-content: center; gap: 6px;" title="<?php echo htmlspecialchars(t('dashboard.tooltip_restart_postfix')); ?>">
                    <i class="fas fa-paper-plane" style="color: var(--success);"></i> <?php echo t('dashboard.restart_postfix'); ?>
                </button>
            </form>
        </div>
    </div>
</div>
