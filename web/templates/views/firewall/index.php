<?php
/**
 * Firewall: the "Common" tab holds the rules every network card uses (the default zone, copied into
 * the zone of a card with rules of its own); each card's tab shows only what differs on it: its own
 * rules and the common ones closed there. A rule can be turned off and on again without deleting it.
 */
$cards = $firewall['cards'];
$common = $firewall['common'] ?? [];
$disabled = $firewall['disabled'] ?? [];
$realCards = array_values(array_filter($cards, static fn(array $c): bool => empty($c['virtual'])));
$useCommon = count($realCards) > 1;
$canEdit = hasModulePermission('firewall', 'edit');
$canRemove = $canEdit || hasModulePermission('firewall', 'delete');
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);

// Tabs: Common first (with more than one card), then every card. After a change the page stays on its tab.
$tabs = [];
if ($useCommon) {
    $tabs['common'] = ['label' => t('firewall.common_tab'), 'icon' => 'fa-layer-group'];
}
foreach ($cards as $card) {
    $tabs[$card['iface']] = ['label' => $card['iface'], 'icon' => !empty($card['virtual']) ? 'fa-network-wired' : 'fa-ethernet'];
}
$active = (string) ($_GET['card'] ?? '');
if (!isset($tabs[$active])) {
    $active = (string) array_key_first($tabs);
}

$confirmFor = static function (array $r, string $scope, ?array $card): string {
    if ($scope === 'common') {
        return ($r['warn'] ?? '') === 'session' ? t('firewall.remove_confirm_common_session') : t('firewall.remove_confirm_common');
    }
    $text = sprintf(t('firewall.remove_confirm_card'), $scope);
    if (($r['warn'] ?? '') === 'session') {
        $text = sprintf(t('firewall.remove_confirm_session'), $scope);
    } elseif (($r['warn'] ?? '') === 'last') {
        $text = t('firewall.remove_confirm_last');
    }
    if ($card !== null && FirewallService::isCallPort((string) $r['port']) && ($card['phones'] ?? 0) > 0) {
        $text = sprintf(t('firewall.remove_confirm_phones'), $card['phones'], $scope) . ' ' . $text;
    }
    return $text;
};

/** A small form with one button; $fields are its hidden inputs. */
$button = static function (string $scope, array $fields, string $class, string $icon, string $label, string $confirm = '') use ($h, $csrf_token): string {
    $html = '<form method="POST" action="/firewall?card=' . urlencode($scope) . '" class="u-inline" autocomplete="off"'
        . ($confirm !== '' ? ' onsubmit="return confirm(' . $h(json_encode($confirm)) . ');"' : '') . '>'
        . '<input type="hidden" name="csrf_token" value="' . $h($csrf_token) . '">';
    foreach ($fields as $k => $v) {
        $html .= '<input type="hidden" name="' . $h($k) . '" value="' . $h($v) . '">';
    }
    return $html . '<button type="submit" class="btn ' . $class . '" title="' . $h($label) . '"><i class="fas ' . $icon . '"></i>'
        . (str_contains($class, 'btn-icon') ? '' : ' ' . $h($label)) . '</button></form>';
};

/** One rule row. $scope: 'common' or an interface; $off: the rule is turned off (fw_disabled_rules). */
$row = static function (array $r, string $scope, bool $off, ?array $card) use ($h, $button, $confirmFor, $canEdit, $canRemove, $useCommon, $realCards): string {
    $custom = !empty($r['custom']);
    $dim = $off ? ' u-opacity-0-5' : '';
    $html = '<tr>';
    $html .= '<td class="u-fw-700' . $dim . '">' . $h($r['port'] !== '' ? $r['port'] : '—') . '</td>';
    $html .= '<td class="' . trim($dim) . '">' . ($r['protocol'] !== '' ? '<span class="badge badge-info">' . strtoupper($h($r['protocol'])) . '</span>' : '<span class="u-muted">—</span>') . '</td>';
    $html .= '<td class="u-fs-12' . $dim . '">' . ($custom ? '<code class="u-fs-11 u-overflow-wrap-anywhere">' . $h($r['value']) . '</code>'
        : ($r['source'] !== '' ? $h($r['source']) : '<span class="u-muted">' . t('firewall.source_any') . '</span>')) . '</td>';
    $html .= '<td class="u-fs-12">' . $h($custom ? t('firewall.use_custom') : $r['use']) . ($off ? ' <span class="badge badge-secondary">' . t('firewall.off') . '</span>' : '') . '</td>';
    if ($scope === 'common' && $useCommon) {
        // Where the rule is open: a card's button closes or opens it on that card only.
        $html .= '<td class="u-fs-12">';
        if (!$off) {
            foreach ($realCards as $c) {
                if ($c['open_all']) {
                    continue;
                }
                $closed = in_array($c['iface'], $r['closed_on'] ?? [], true);
                $fields = ['common_on_card' => 1, 'iface' => $c['iface'], 'kind' => $r['kind'], 'value' => $r['value'], 'on' => $closed ? 1 : 0];
                $html .= $canEdit && !$custom
                    ? $button('common', $fields, 'btn-xs ' . ($closed ? 'btn-secondary' : 'btn-success'), $closed ? 'fa-xmark' : 'fa-check', $c['iface'],
                        $closed ? '' : sprintf(t('firewall.close_on_card_confirm'), $c['iface'])) . ' '
                    : '<span class="badge ' . ($closed ? 'badge-secondary' : 'badge-success') . '">' . $h($c['iface']) . '</span> ';
            }
        }
        $html .= '</td>';
    }
    $html .= '<td class="u-text-right u-nowrap">';
    $id = ['kind' => $r['kind'], 'value' => $r['value']];
    if ($canEdit && !$custom) {
        $html .= $off
            ? $button($scope, ['toggle_rule' => 1, 'scope' => $scope, 'on' => 1] + $id, 'btn-xs btn-success', 'fa-toggle-off', t('firewall.turn_on'))
            : $button($scope, ['toggle_rule' => 1, 'scope' => $scope, 'on' => 0] + $id, 'btn-xs btn-secondary', 'fa-toggle-on', t('firewall.turn_off'), $confirmFor($r, $scope, $card));
        $html .= ' ';
    }
    if ($canRemove) {
        $html .= $off
            ? $button($scope, ['delete_disabled' => 1, 'scope' => $scope] + $id, 'btn-icon btn-xs btn-danger', 'fa-trash-alt', t('firewall.remove_tooltip'), t('firewall.delete_off_confirm'))
            : $button($scope, ['remove_rule' => 1, 'iface' => $scope] + $id, 'btn-icon btn-xs btn-danger', 'fa-trash-alt', t('firewall.remove_tooltip'), $confirmFor($r, $scope, $card));
    }
    return $html . '</td></tr>';
};

/** The rules table of one tab. */
$table = static function (array $rules, array $off, string $scope, ?array $card, string $empty) use ($row, $useCommon): string {
    $cols = $scope === 'common' && $useCommon ? 6 : 5;
    $html = uiTableOpen([t('firewall.col_port'), t('firewall.col_protocol'), t('firewall.col_source'), t('firewall.col_use'),
        $cols === 6 ? t('firewall.col_cards') : null, ''], ['search' => false]);
    if ($rules === [] && $off === []) {
        $html .= uiTableEmptyRow($cols, $empty, 'fa-shield-halved');
    }
    foreach ($rules as $r) {
        $html .= $row($r, $scope, false, $card);
    }
    foreach ($off as $r) {
        $html .= $row($r, $scope, true, $card);
    }
    return $html . uiTableClose();
};

/** Adding a rule, inside the tab it applies to. */
$addForm = static function (string $scope) use ($h, $csrf_token): string {
    return '<form method="POST" action="/firewall?card=' . urlencode($scope) . '" autocomplete="off" class="u-flex-gap u-wrap u-ai-flex-end u-card-pad">'
        . '<input type="hidden" name="csrf_token" value="' . $h($csrf_token) . '"><input type="hidden" name="add_rule" value="1"><input type="hidden" name="target" value="' . $h($scope) . '">'
        . '<div class="form-group u-m-0"><label class="form-label">' . t('firewall.field_port') . '</label><input type="text" name="port" class="form-control" placeholder="8080" required></div>'
        . '<div class="form-group u-m-0"><label class="form-label">' . t('firewall.field_protocol') . '</label><select name="protocol" class="form-control"><option value="tcp">TCP</option><option value="udp">UDP</option></select></div>'
        . '<div class="form-group u-m-0"><label class="form-label">' . t('firewall.field_source') . '</label><input type="text" name="source_subnet" class="form-control" placeholder="192.168.1.0/24" title="' . $h(t('firewall.source_help')) . '"></div>'
        . '<button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> ' . t('firewall.add_rule_submit') . '</button></form>';
};
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-shield-halved', t('firewall.header_title'), 'firewallHelpBox'); ?>
<span class="badge <?php echo $firewall['active'] ? 'badge-success' : 'badge-danger'; ?> u-fs-11">
                <?php echo $firewall['active'] ? t('firewall.service_active') : t('firewall.service_inactive'); ?>
            </span>
<?php echo uiPageHeaderActions(); ?>
<?php echo uiPageHeaderClose(); ?>

    <?php echo uiHelpBoxOpen('firewallHelpBox', t('firewall.help_title')); ?>
        <?php echo t('firewall.help_common'); ?>
<?php echo uiHelpBoxClose(); ?>

    <?php if (count($tabs) > 1): ?>
    <div class="settings-tabs u-p-0-20">
        <?php foreach ($tabs as $key => $tab): ?>
            <button type="button" class="settings-tab-btn<?php echo $key === $active ? ' active' : ''; ?>" data-tab="fw<?php echo md5((string) $key); ?>" onclick="switchSettingsTab('fw<?php echo md5((string) $key); ?>', this)">
                <i class="fas <?php echo $tab['icon']; ?>"></i> <?php echo $h($tab['label']); ?>
            </button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($cards)): ?>
        <p class="u-muted u-card-pad"><?php echo t('firewall.empty'); ?></p>
    <?php endif; ?>

    <?php if ($useCommon): ?>
    <div id="tab_fw<?php echo md5('common'); ?>" class="settings-tab-pane<?php echo $active === 'common' ? ' active' : ''; ?>"<?php echo $active === 'common' ? '' : ' style="display: none;"'; ?>>
        <p class="u-muted u-fs-12 u-p-4-20-12 u-m-0"><?php echo t('firewall.common_intro'); ?></p>
        <?php echo $table($common, $disabled['common'] ?? [], 'common', null, t('firewall.empty')); ?>
        <?php if ($canEdit) { echo $addForm('common'); } ?>
    </div>
    <?php endif; ?>

    <?php foreach ($cards as $card):
        $iface = $card['iface'];
        $diffOnly = $useCommon && empty($card['virtual']);
        $rules = $diffOnly ? $card['extra'] : $card['rules'];
    ?>
    <div id="tab_fw<?php echo md5($iface); ?>" class="settings-tab-pane<?php echo $iface === $active ? ' active' : ''; ?>"<?php echo $iface === $active ? '' : ' style="display: none;"'; ?>>
        <div class="u-p-4-20-12 u-d-flex u-wrap u-gap-8-12 u-ai-center u-jc-space-between">
            <div class="u-fs-12">
                <strong><?php echo $h($iface); ?></strong>
                <span class="u-muted"><?php echo $h($card['ips'] ? implode(', ', $card['ips']) : t('firewall.no_address')); ?></span>
                <?php if ($diffOnly): ?>
                    <span class="badge <?php echo $card['own'] ? 'badge-warning' : 'badge-info'; ?> u-ml-6">
                        <?php echo $card['own'] ? t('firewall.own_rules') : t('firewall.general_rules'); ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php if ($canEdit && $card['removable_zone']): ?>
                <?php echo $button($iface, ['use_general' => 1, 'iface' => $iface], 'btn-secondary btn-sm', 'fa-rotate-left', t('firewall.use_general'), sprintf(t('firewall.general_confirm'), $iface)); ?>
            <?php endif; ?>
        </div>

        <?php if ($card['open_all']): ?>
            <p class="u-muted u-fs-12 u-card-pad"><i class="fas fa-circle-info"></i> <?php echo t('firewall.open_all'); ?></p>
        <?php else: ?>
            <?php if (!empty($card['virtual']) && $card['own']): ?>
                <p class="u-muted u-fs-12 u-p-0-20-8 u-m-0"><i class="fas fa-circle-info"></i> <?php echo t('firewall.virtual_own'); ?></p>
            <?php endif; ?>
            <?php if ($diffOnly): ?>
                <p class="u-muted u-fs-12 u-p-0-20-8 u-m-0"><i class="fas fa-layer-group"></i> <?php echo sprintf(t('firewall.common_also'), count($common) - count($card['closed_common'])); ?></p>
            <?php endif; ?>
            <?php echo $table($rules, $disabled[$iface] ?? [], $iface, $card, $diffOnly ? t('firewall.no_own_rules') : t('firewall.empty')); ?>
            <?php if ($diffOnly && $card['closed_common'] !== []): ?>
                <div class="u-card-pad">
                    <div class="u-fw-700 u-fs-13 u-mb-8"><?php echo t('firewall.closed_common_title'); ?></div>
                    <?php foreach ($card['closed_common'] as $r): ?>
                        <div class="u-d-flex u-ai-center u-gap-8 u-fs-12 u-mb-6">
                            <span class="u-fw-700"><?php echo $h($r['port'] !== '' ? $r['port'] : $r['value']); ?></span>
                            <?php if ($r['protocol'] !== ''): ?><span class="badge badge-info"><?php echo strtoupper($h($r['protocol'])); ?></span><?php endif; ?>
                            <span class="u-muted"><?php echo $h($r['source'] !== '' ? $r['source'] : $r['use']); ?></span>
                            <?php if ($canEdit && empty($r['custom'])) {
                                echo $button($iface, ['common_on_card' => 1, 'iface' => $iface, 'kind' => $r['kind'], 'value' => $r['value'], 'on' => 1], 'btn-xs btn-success', 'fa-check', t('firewall.open_here'));
                            } ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($canEdit) { echo $addForm($iface); } ?>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
