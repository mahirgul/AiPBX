<?php
$cards = $firewall['cards'];
$many = count($cards) > 1;
$canEdit = hasModulePermission('firewall', 'edit');
$canRemove = $canEdit || hasModulePermission('firewall', 'delete');
// After a change the page stays on the card it was made on.
$activeIface = (string) ($_GET['card'] ?? '');
$activeIndex = max(0, (int) array_search($activeIface, array_column($cards, 'iface'), true));
$removeConfirm = static function (array $r, array $card): string {
    $text = sprintf(t('firewall.remove_confirm_card'), $card['iface']);
    if (($r['warn'] ?? '') === 'session') {
        $text = sprintf(t('firewall.remove_confirm_session'), $card['iface']);
    } elseif (($r['warn'] ?? '') === 'last') {
        $text = t('firewall.remove_confirm_last');
    }
    if (FirewallService::isCallPort($r['port']) && ($card['phones'] ?? 0) > 0) {
        $text = sprintf(t('firewall.remove_confirm_phones'), $card['phones'], $card['iface']) . ' ' . $text;
    }
    return $text;
};
$useLabel = static fn(array $r): string => !empty($r['custom']) ? t('firewall.use_custom') : (string) $r['use'];
?>
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-shield-halved u-primary"></i> <?php echo t('firewall.header_title'); ?>
            <span class="badge <?php echo $firewall['active'] ? 'badge-success' : 'badge-danger'; ?>" style="margin-left: 8px; font-size: 11px;">
                <?php echo $firewall['active'] ? t('firewall.service_active') : t('firewall.service_inactive'); ?>
            </span>
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('firewallHelpBox')" title="<?php echo t('common.module_guide'); ?>">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>

    <div class="module-help-box" id="firewallHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('firewall.help_title'); ?></h4>
        <?php echo t('firewall.help_cards'); ?>
    </div>

    <?php if ($many): ?>
    <div class="settings-tabs" style="padding: 0 20px;">
        <?php foreach ($cards as $i => $card): ?>
            <button type="button" class="settings-tab-btn<?php echo $i === $activeIndex ? ' active' : ''; ?>" data-tab="card<?php echo $i; ?>" onclick="switchSettingsTab('card<?php echo $i; ?>', this)">
                <i class="fas fa-ethernet"></i> <?php echo htmlspecialchars($card['iface']); ?>
            </button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($cards)): ?>
        <p class="u-muted" style="padding: 0 20px 20px;"><?php echo t('firewall.empty'); ?></p>
    <?php endif; ?>

    <?php foreach ($cards as $i => $card): ?>
    <div id="tab_card<?php echo $i; ?>" class="settings-tab-pane<?php echo $i === $activeIndex ? ' active' : ''; ?>"<?php echo $i === $activeIndex ? '' : ' style="display: none;"'; ?>>
        <div style="padding: 4px 20px 12px; display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: center; justify-content: space-between;">
            <div class="u-fs-12">
                <strong><?php echo htmlspecialchars($card['iface']); ?></strong>
                <span class="u-muted"><?php echo htmlspecialchars($card['ips'] ? implode(', ', $card['ips']) : t('firewall.no_address')); ?></span>
                <?php if ($many): ?>
                    <span class="badge <?php echo $card['own'] ? 'badge-warning' : 'badge-info'; ?>" style="margin-left: 6px;">
                        <?php echo $card['own'] ? t('firewall.own_rules') : t('firewall.general_rules'); ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php if ($canEdit && $card['removable_zone']): ?>
                <form method="POST" action="/firewall?card=<?php echo urlencode($card['iface']); ?>" autocomplete="off" class="u-inline" onsubmit="return confirm(<?php echo jsArg(sprintf(t('firewall.general_confirm'), $card['iface'])); ?>);">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="use_general" value="1">
                    <input type="hidden" name="iface" value="<?php echo htmlspecialchars($card['iface']); ?>">
                    <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-rotate-left"></i> <?php echo t('firewall.use_general'); ?></button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($card['open_all']): ?>
            <p class="u-muted u-fs-12" style="padding: 0 20px 20px;"><i class="fas fa-circle-info"></i> <?php echo t('firewall.open_all'); ?></p>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="data-table no-dt">
                <thead>
                    <tr>
                        <th><?php echo t('firewall.col_port'); ?></th>
                        <th><?php echo t('firewall.col_protocol'); ?></th>
                        <th><?php echo t('firewall.col_source'); ?></th>
                        <th><?php echo t('firewall.col_use'); ?></th>
                        <th class="u-text-right"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($card['rules'])): ?>
                        <?php echo uiTableEmptyRow(5, t('firewall.empty'), 'fa-shield-halved'); ?>
                    <?php endif; ?>
                    <?php foreach ($card['rules'] as $r): ?>
                        <tr>
                            <td class="u-fw-700"><?php echo htmlspecialchars($r['port'] !== '' ? $r['port'] : '—'); ?></td>
                            <td><?php if ($r['protocol'] !== ''): ?><span class="badge badge-info"><?php echo strtoupper(htmlspecialchars($r['protocol'])); ?></span><?php else: ?><span class="u-muted">—</span><?php endif; ?></td>
                            <td class="u-fs-12">
                                <?php if (!empty($r['custom'])): ?>
                                    <code class="u-fs-11" style="overflow-wrap: anywhere;"><?php echo htmlspecialchars($r['value']); ?></code>
                                <?php elseif ($r['source'] !== ''): ?>
                                    <?php echo htmlspecialchars($r['source']); ?>
                                <?php else: ?>
                                    <span class="u-muted"><?php echo t('firewall.source_any'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="u-fs-12"><?php echo htmlspecialchars($useLabel($r)); ?></td>
                            <td class="u-text-right">
                                <?php if ($canRemove): ?>
                                    <form method="POST" action="/firewall?card=<?php echo urlencode($card['iface']); ?>" autocomplete="off" class="u-inline" onsubmit="return confirm(<?php echo jsArg($removeConfirm($r, $card)); ?>);">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="remove_rule" value="1">
                                        <input type="hidden" name="iface" value="<?php echo htmlspecialchars($card['iface']); ?>">
                                        <input type="hidden" name="kind" value="<?php echo htmlspecialchars($r['kind']); ?>">
                                        <input type="hidden" name="value" value="<?php echo htmlspecialchars($r['value']); ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="<?php echo t('firewall.remove_tooltip'); ?>"><i class="fas fa-trash-alt"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

</div>

<?php if ($canEdit): ?>
<div class="card" style="max-width: 560px;">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-plus-circle u-primary"></i> <?php echo t('firewall.add_rule_title'); ?></div>
    </div>
    <form method="POST" autocomplete="off" style="padding: 0 20px 20px;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="add_rule" value="1">

        <?php if ($many): ?>
        <div class="form-group">
            <label class="form-label"><?php echo t('firewall.field_target'); ?></label>
            <select name="target" class="form-control">
                <option value="all"><?php echo t('firewall.target_all'); ?></option>
                <?php foreach ($cards as $card): ?>
                    <option value="<?php echo htmlspecialchars($card['iface']); ?>"><?php echo htmlspecialchars($card['iface'] . ($card['ips'] ? ' — ' . implode(', ', $card['ips']) : '')); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="u-grid-2">
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
            <small class="u-hint u-fs-11"><?php echo t('firewall.source_help'); ?></small>
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px; margin-top: 8px;">
            <i class="fas fa-plus"></i> <?php echo t('firewall.add_rule_submit'); ?>
        </button>
    </form>
</div>
<?php endif; ?>
