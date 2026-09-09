<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-user-shield" style="color: var(--primary);"></i> <?php echo t('fail2ban.header_title'); ?>
            <span class="badge <?php echo !empty($service_active) ? 'badge-success' : 'badge-danger'; ?>" style="margin-left: 8px; font-size: 11px;">
                <?php echo !empty($service_active) ? t('firewall.service_active') : t('firewall.service_inactive'); ?>
            </span>
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('fail2banHelpBox')" title="Modül Rehberi">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>
    <div class="module-help-box" id="fail2banHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('fail2ban.help_title'); ?></h4>
        <?php echo t('fail2ban.help_body'); ?>
    </div>
</div>

<?php if (empty($jails)): ?>
    <div class="card"><div style="padding: 20px; color: var(--text-muted);"><?php echo t('fail2ban.no_jails'); ?></div></div>
<?php endif; ?>

<?php foreach ($jails as $j): ?>
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-gavel" style="color: var(--primary);"></i> <?php echo htmlspecialchars($j['name']); ?>
                <span class="badge <?php echo $j['currently_banned'] > 0 ? 'badge-danger' : 'badge-success'; ?>" style="margin-left: 8px; font-size: 11px;">
                    <?php echo sprintf(t('fail2ban.currently_banned_badge'), $j['currently_banned']); ?>
                </span>
                <span class="badge badge-info" style="font-size: 11px;"><?php echo sprintf(t('fail2ban.total_banned_badge'), $j['total_banned']); ?></span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?php echo t('fail2ban.col_ip'); ?></th>
                        <th style="text-align: right;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($j['banned_ips'])): ?>
                        <?php echo uiTableEmptyRow(2, t('fail2ban.no_banned_ips'), 'fa-check-circle'); ?>
                    <?php else: ?>
                        <?php foreach ($j['banned_ips'] as $ip): ?>
                            <tr>
                                <td style="font-family: monospace;"><?php echo htmlspecialchars($ip); ?></td>
                                <td style="text-align: right;">
                                    <form method="POST" autocomplete="off" style="display:inline;" onsubmit="return confirm('<?php echo htmlspecialchars(t('fail2ban.unban_confirm'), ENT_QUOTES); ?>');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="unban_ip" value="1">
                                        <input type="hidden" name="jail" value="<?php echo htmlspecialchars($j['name']); ?>">
                                        <input type="hidden" name="ip" value="<?php echo htmlspecialchars($ip); ?>">
                                        <button type="submit" class="btn btn-warning btn-sm" title="<?php echo t('fail2ban.unban_tooltip'); ?>"><i class="fas fa-unlock"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <form method="POST" autocomplete="off" style="padding: 16px 20px; border-top: 1px solid var(--border-color);">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="update_jail_config" value="1">
            <input type="hidden" name="jail" value="<?php echo htmlspecialchars($j['name']); ?>">
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 12px; align-items: flex-end;">
                <div class="form-group" style="margin: 0;">
                    <label class="form-label"><?php echo t('fail2ban.field_bantime'); ?></label>
                    <input type="number" name="bantime" class="form-control" value="<?php echo (int) $j['bantime']; ?>" min="60" required>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label class="form-label"><?php echo t('fail2ban.field_findtime'); ?></label>
                    <input type="number" name="findtime" class="form-control" value="<?php echo (int) $j['findtime']; ?>" min="60" required>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label class="form-label"><?php echo t('fail2ban.field_maxretry'); ?></label>
                    <input type="number" name="maxretry" class="form-control" value="<?php echo (int) $j['maxretry']; ?>" min="1" required>
                </div>
                <button type="submit" class="btn btn-primary" title="<?php echo t('fail2ban.save_config_tooltip'); ?>"><i class="fas fa-save"></i></button>
            </div>
        </form>
    </div>
<?php endforeach; ?>

<div class="card" style="max-width: 620px;">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-list-check" style="color: var(--primary);"></i> <?php echo t('fail2ban.whitelist_title'); ?></div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <tbody>
                <?php foreach ($ignoreips as $ip): $is_protected = in_array($ip, $protected_ignoreips, true); ?>
                    <tr>
                        <td style="font-family: monospace;"><?php echo htmlspecialchars($ip); ?></td>
                        <td style="text-align: right;">
                            <?php if ($is_protected): ?>
                                <span class="badge badge-warning" title="<?php echo htmlspecialchars(t('fail2ban.protected_tooltip')); ?>"><i class="fas fa-lock"></i></span>
                            <?php else: ?>
                                <form method="POST" autocomplete="off" style="display:inline;" onsubmit="return confirm('<?php echo htmlspecialchars(t('fail2ban.remove_ignoreip_confirm'), ENT_QUOTES); ?>');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="remove_ignoreip" value="1">
                                    <input type="hidden" name="ip" value="<?php echo htmlspecialchars($ip); ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" title="<?php echo t('fail2ban.remove_ignoreip_tooltip'); ?>"><i class="fas fa-trash-alt"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <form method="POST" autocomplete="off" style="padding: 0 20px 20px; display: flex; gap: 10px; align-items: flex-end;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="add_ignoreip" value="1">
        <div class="form-group" style="flex: 1; margin: 0;">
            <label class="form-label"><?php echo t('fail2ban.field_add_ignoreip'); ?></label>
            <input type="text" name="ip" class="form-control" placeholder="192.168.1.0/24">
        </div>
        <button type="submit" class="btn btn-primary" style="padding: 10px 16px;"><i class="fas fa-plus"></i></button>
    </form>
</div>
