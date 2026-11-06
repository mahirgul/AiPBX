<div class="card">
    <?php echo uiPageHeaderOpen('fa-user-shield', t('fail2ban.header_title'), 'fail2banHelpBox'); ?>
<span class="badge <?php echo !empty($service_active) ? 'badge-success' : 'badge-danger'; ?> u-ml-8 u-fs-11">
                <?php echo !empty($service_active) ? t('firewall.service_active') : t('firewall.service_inactive'); ?>
            </span>
<?php echo uiPageHeaderActions(); ?>

<?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('fail2banHelpBox', t('fail2ban.help_title')); ?>
        <?php echo t('fail2ban.help_body'); ?>
<?php echo uiHelpBoxClose(); ?>
</div>

<?php if (empty($jails)): ?>
    <div class="card"><div class="u-p-20 u-muted"><?php echo t('fail2ban.no_jails'); ?></div></div>
<?php endif; ?>

<?php foreach ($jails as $j): ?>
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-gavel u-primary"></i> <?php echo htmlspecialchars($j['name']); ?>
                <span class="badge <?php echo $j['currently_banned'] > 0 ? 'badge-danger' : 'badge-success'; ?> u-ml-8 u-fs-11">
                    <?php echo sprintf(t('fail2ban.currently_banned_badge'), $j['currently_banned']); ?>
                </span>
                <span class="badge badge-info u-fs-11"><?php echo sprintf(t('fail2ban.total_banned_badge'), $j['total_banned']); ?></span>
            </div>
        </div>

        <?php echo uiTableOpen([
            t('fail2ban.col_ip'),
            ['label' => '', 'class' => 'u-text-right'],
        ]); ?>
                    <?php if (empty($j['banned_ips'])): ?>
                        <?php echo uiTableEmptyRow(2, t('fail2ban.no_banned_ips'), 'fa-check-circle'); ?>
                    <?php else: ?>
                        <?php foreach ($j['banned_ips'] as $ip): ?>
                            <tr>
                                <td class="u-mono"><?php echo htmlspecialchars($ip); ?></td>
                                <td class="u-text-right">
                                    <?php if (hasModulePermission('fail2ban', 'edit')): ?>
                                        <form method="POST" autocomplete="off" class="u-inline" onsubmit="return confirm(<?php echo jsArg(t('fail2ban.unban_confirm')); ?>);">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="unban_ip" value="1">
                                            <input type="hidden" name="jail" value="<?php echo htmlspecialchars($j['name']); ?>">
                                            <input type="hidden" name="ip" value="<?php echo htmlspecialchars($ip); ?>">
                                            <button type="submit" class="btn btn-icon btn-warning btn-sm" title="<?php echo t('fail2ban.unban_tooltip'); ?>"><i class="fas fa-unlock"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php echo uiTableClose(); ?>

        <?php if (hasModulePermission('fail2ban', 'edit')): ?>
        <form class="u-p-16-20 u-border-top-1-solid-border-color" method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="update_jail_config" value="1">
            <input type="hidden" name="jail" value="<?php echo htmlspecialchars($j['name']); ?>">
            <div class="u-d-grid u-gtc-1fr-1fr-1fr-auto u-gap-12 u-ai-flex-end">
                <div class="form-group u-m-0">
                    <label class="form-label"><?php echo t('fail2ban.field_bantime'); ?></label>
                    <input type="number" name="bantime" class="form-control" value="<?php echo (int) $j['bantime']; ?>" min="60" required>
                </div>
                <div class="form-group u-m-0">
                    <label class="form-label"><?php echo t('fail2ban.field_findtime'); ?></label>
                    <input type="number" name="findtime" class="form-control" value="<?php echo (int) $j['findtime']; ?>" min="60" required>
                </div>
                <div class="form-group u-m-0">
                    <label class="form-label"><?php echo t('fail2ban.field_maxretry'); ?></label>
                    <input type="number" name="maxretry" class="form-control" value="<?php echo (int) $j['maxretry']; ?>" min="1" required>
                </div>
                <button type="submit" class="btn btn-primary u-d-inline-flex u-ai-center u-jc-center u-gap-8" title="<?php echo t('fail2ban.save_config_tooltip'); ?>"><i class="fas fa-save"></i> <span class="d-md-none"><?php echo t('fail2ban.save_config_tooltip'); ?></span></button>
            </div>
        </form>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<div class="card u-maxw-620">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-list-check u-primary"></i> <?php echo t('fail2ban.whitelist_title'); ?></div>
    </div>
    <?php echo uiTableOpen([]); ?>
                <?php foreach ($ignoreips as $ip): $is_protected = in_array($ip, $protected_ignoreips, true); ?>
                    <tr>
                        <td class="u-mono"><?php echo htmlspecialchars($ip); ?></td>
                        <td class="u-text-right">
                            <?php if ($is_protected): ?>
                                <span class="badge badge-warning" title="<?php echo htmlspecialchars(t('fail2ban.protected_tooltip')); ?>"><i class="fas fa-lock"></i></span>
                            <?php elseif (hasModulePermission('fail2ban', 'edit')): ?>
                                <form method="POST" autocomplete="off" class="u-inline" onsubmit="return confirm(<?php echo jsArg(t('fail2ban.remove_ignoreip_confirm')); ?>);">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="remove_ignoreip" value="1">
                                    <input type="hidden" name="ip" value="<?php echo htmlspecialchars($ip); ?>">
                                    <button type="submit" class="btn btn-icon btn-danger btn-sm" title="<?php echo t('fail2ban.remove_ignoreip_tooltip'); ?>"><i class="fas fa-trash-alt"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php echo uiTableClose(); ?>
    <?php if (hasModulePermission('fail2ban', 'edit')): ?>
    <form method="POST" autocomplete="off" class="u-card-pad u-d-flex u-gap-10 u-ai-flex-end">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="add_ignoreip" value="1">
        <div class="form-group u-m-0 u-flex-1">
            <label class="form-label"><?php echo t('fail2ban.field_add_ignoreip'); ?></label>
            <input type="text" name="ip" class="form-control" placeholder="192.168.1.0/24">
        </div>
        <button type="submit" class="btn btn-icon btn-primary"><i class="fas fa-plus"></i></button>
    </form>
    <?php endif; ?>
</div>
