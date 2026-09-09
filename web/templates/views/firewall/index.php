<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-shield-halved" style="color: var(--primary);"></i> <?php echo t('firewall.header_title'); ?>
            <span class="badge <?php echo $status['active'] ? 'badge-success' : 'badge-danger'; ?>" style="margin-left: 8px; font-size: 11px;">
                <?php echo $status['active'] ? t('firewall.service_active') : t('firewall.service_inactive'); ?>
            </span>
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('firewallHelpBox')" title="Modül Rehberi">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>

    <div class="module-help-box" id="firewallHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('firewall.help_title'); ?></h4>
        <?php echo t('firewall.help_body'); ?>
        <br>- <strong><?php echo t('firewall.help_protected'); ?></strong> <?php echo implode(', ', $protected_ports); ?>
    </div>

    <div style="padding: 0 20px 8px; color: var(--text-muted); font-size: 12px;">
        <?php echo t('firewall.zone_label'); ?>: <strong><?php echo htmlspecialchars($status['zone']); ?></strong>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?php echo t('firewall.col_port'); ?></th>
                    <th><?php echo t('firewall.col_protocol'); ?></th>
                    <th><?php echo t('firewall.col_scope'); ?></th>
                    <th style="text-align: right;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($status['ports']) && empty($status['rich_rules'])): ?>
                    <?php echo uiTableEmptyRow(4, t('firewall.empty'), 'fa-shield-halved'); ?>
                <?php else: ?>
                    <?php foreach ($status['ports'] as $p): $is_protected = FirewallService::isProtected($p['port']); ?>
                        <tr>
                            <td style="font-weight: 700;"><?php echo htmlspecialchars($p['port']); ?></td>
                            <td><span class="badge badge-info"><?php echo strtoupper(htmlspecialchars($p['protocol'])); ?></span></td>
                            <td><span style="color: var(--text-muted); font-size: 12px;"><?php echo t('firewall.scope_general'); ?></span></td>
                            <td style="text-align: right;">
                                <?php if ($is_protected): ?>
                                    <span class="badge badge-warning" title="<?php echo htmlspecialchars(t('firewall.protected_tooltip')); ?>"><i class="fas fa-lock"></i></span>
                                <?php else: ?>
                                    <form method="POST" autocomplete="off" style="display:inline;" onsubmit="return confirm('<?php echo htmlspecialchars(t('firewall.remove_confirm'), ENT_QUOTES); ?>');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="remove_port_rule" value="1">
                                        <input type="hidden" name="port" value="<?php echo htmlspecialchars($p['port']); ?>">
                                        <input type="hidden" name="protocol" value="<?php echo htmlspecialchars($p['protocol']); ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="<?php echo t('firewall.remove_tooltip'); ?>"><i class="fas fa-trash-alt"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($status['rich_rules'] as $rule): $is_protected = FirewallService::isProtected($rule); ?>
                        <tr>
                            <td colspan="3" style="font-size: 11px; font-family: monospace; color: var(--text-main);"><?php echo htmlspecialchars($rule); ?></td>
                            <td style="text-align: right;">
                                <?php if ($is_protected): ?>
                                    <span class="badge badge-warning" title="<?php echo htmlspecialchars(t('firewall.protected_tooltip')); ?>"><i class="fas fa-lock"></i></span>
                                <?php else: ?>
                                    <form method="POST" autocomplete="off" style="display:inline;" onsubmit="return confirm('<?php echo htmlspecialchars(t('firewall.remove_confirm'), ENT_QUOTES); ?>');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="remove_rich_rule" value="1">
                                        <input type="hidden" name="rule" value="<?php echo htmlspecialchars($rule); ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="<?php echo t('firewall.remove_tooltip'); ?>"><i class="fas fa-trash-alt"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="max-width: 560px;">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-plus-circle" style="color: var(--primary);"></i> <?php echo t('firewall.add_rule_title'); ?></div>
    </div>
    <form method="POST" autocomplete="off" style="padding: 0 20px 20px;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="add_port_rule" value="1">

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label class="form-label"><?php echo t('firewall.field_port'); ?></label>
                <input type="text" name="port" class="form-control" placeholder="8080" required>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('firewall.field_protocol'); ?></label>
                <select name="protocol" class="form-control">
                    <option value="tcp">TCP</option>
                    <option value="udp">UDP</option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label"><?php echo t('firewall.field_source'); ?></label>
            <input type="text" name="source_subnet" class="form-control" placeholder="192.168.1.0/24">
            <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;"><?php echo t('firewall.source_help'); ?></small>
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px; margin-top: 8px;">
            <i class="fas fa-plus"></i> <?php echo t('firewall.add_rule_submit'); ?>
        </button>
    </form>
</div>
