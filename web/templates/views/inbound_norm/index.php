<?php
/**
 * Inbound Call Normalization (/inbound-normalization): rules per number, and the simulator.
 * Laid out like Outbound Call Normalization. Script: assets/js/inbound_norm.js,
 * styles: assets/css/pages/dialplan_rules.css.
 */
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$tabs = [
    'all' => ['fa-list', t('inbound_norm.tab_all')],
    'caller' => ['fa-user', t('inbound_norm.target_caller')],
    'did' => ['fa-phone-alt', t('inbound_norm.target_did')],
];
$trunkOptions = '<option value="">' . t('inbound_norm.all_trunks') . '</option>';
foreach ($trunks as $trunk) {
    $trunkOptions .= '<option value="' . $h($trunk) . '">' . $h($trunk) . '</option>';
}
?>
<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/dialplan_rules.css'); ?>">
<div class="card mb-3">
    <?php echo uiPageHeaderOpen('fa-sliders-h', t('inbound_norm.title'), 'inboundNormHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>

            <?php if ($canEdit): ?>
                <button type="button" class="btn btn-primary btn-sm" onclick="openCreateInboundRule(document.querySelector('.settings-tab-btn.active')?.dataset.tab || 'all')">
                    <i class="fas fa-plus-circle"></i> <?php echo t('inbound_norm.btn_add'); ?>
                </button>
            <?php endif; ?>
<?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('inboundNormHelpBox', t('inbound_norm.title')); ?>
        <p><?php echo t('inbound_norm.help'); ?></p>
        <p class="u-mt-8"><?php echo t('common.help_number_order'); ?></p>
        <div class="u-mt-10"><strong><?php echo t('common.help_limits'); ?></strong><ul class="u-m-4-0-0-20"><li><?php echo t('inbound_norm.limit_order'); ?></li></ul></div>
<?php echo uiHelpBoxClose(); ?>

    <div class="dpr-settings">
        <?php $onoff = ['label' => t('inbound_norm.enabled_label'), 'desc' => t('inbound_norm.enabled_desc'), 'on' => $enabled, 'can_edit' => $canEdit,
            'csrf' => $csrf, 'confirm_off' => t('inbound_norm.confirm_turn_off'), 'fields' => ['set_enabled' => '1']];
        require __DIR__ . '/../../onoff_setting.php'; ?>
<?php if ($canEdit): ?>
        <div class="dpr-setting">
            <div>
                <div class="dpr-setting-title"><?php echo t('inbound_norm.presets_title'); ?></div>
                <div class="dpr-setting-desc"><?php echo t('inbound_norm.presets_help'); ?></div>
            </div>
            <form method="POST" class="dpr-country-form" onsubmit="return confirmLoadDefaults(this);" data-confirm-tmpl="<?php echo $h(t('inbound_norm.confirm_presets')); ?>" data-msg-required="<?php echo $h(t('dialplan_rules.err_country_required')); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                <input type="hidden" name="load_presets" value="1">
                <select name="country_code" class="form-control form-control-sm">
                    <option value=""><?php echo t('dialplan_rules.country_none'); ?></option>
                    <?php foreach ($countries as $cc => $name): ?><option value="<?php echo $h($cc); ?>"><?php echo $h($name); ?> (+<?php echo $h($cc); ?>)</option><?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-magic"></i> <?php echo t('inbound_norm.presets_load'); ?></button>
            </form>
        </div>
<?php endif; ?>
    </div>
</div>

<!-- The filter tabs and their table in one card. -->
<div class="card tabbed-page">
<div class="settings-tabs">
    <?php foreach ($tabs as $key => [$icon, $label]): ?>
        <?php $count = $key === 'all' ? count($rules) : count(array_filter($rules, static fn(array $r): bool => $r['target'] === $key)); ?>
        <button type="button" class="settings-tab-btn <?php echo $key === 'all' ? 'active' : ''; ?>" data-tab="<?php echo $key; ?>" onclick="showInboundTab('<?php echo $key; ?>', this)">
            <i class="fas <?php echo $icon; ?>"></i> <?php echo $label; ?> <span class="dpr-count"><?php echo $count; ?></span>
        </button>
    <?php endforeach; ?>
</div>

<?php foreach ($tabs as $key => $_tab): ?>
    <?php $shown = $key === 'all' ? $rules : array_values(array_filter($rules, static fn(array $r): bool => $r['target'] === $key)); ?>
    <div id="inbound_tab_<?php echo $key; ?>" class="settings-tab-pane <?php echo $key === 'all' ? 'active' : ''; ?>" style="display: <?php echo $key === 'all' ? 'block' : 'none'; ?>;">
        <div class="card mb-3"><?php echo uiTableOpen([
    t('dialplan_rules.col_name'),
    t('dialplan_rules.col_pattern'),
    ['label' => t('dialplan_rules.col_transform'), 'class' => 'col-hide-mobile'],
    t('inbound_norm.field_trunk'),
    $key === 'all' ? t('inbound_norm.field_target') : null,
    t('dialplan_rules.col_status'),
    $canEdit ? ['label' => t('dialplan_rules.col_actions'), 'class' => 'text-right'] : null,
], ['search' => false]); ?>
                <?php if (!$shown): ?>
                    <?php echo uiTableEmptyRow(5 + ($canEdit ? 1 : 0) + ($key === 'all' ? 1 : 0), t('inbound_norm.empty')); ?>
                <?php endif; ?>
                <?php foreach ($shown as $r):
                    $steps = [];
                    if ((int) $r['strip_front'] > 0) { $steps[] = t('dialplan_rules.strip_front') . ' <b>' . (int) $r['strip_front'] . '</b>'; }
                    if ((string) $r['prepend'] !== '') { $steps[] = t('dialplan_rules.prepend') . ' <code>' . $h($r['prepend']) . '</code>'; }
                ?>
                    <tr class="<?php echo empty($r['is_active']) ? 'dpr-row-off' : ''; ?>">
                        <td><div class="u-strong"><?php echo $h($r['rule_name']); ?></div></td>
                        <td><code class="dpr-pattern"><?php echo $h($r['match_pattern']); ?></code></td>
                        <td class="col-hide-mobile u-fs-12"><?php echo $steps ? implode(' · ', $steps) : '<span class="text-muted">—</span>'; ?></td>
                        <td><?php echo $r['trunk_name'] !== null ? '<span class="badge badge-secondary">' . $h($r['trunk_name']) . '</span>' : '<span class="badge badge-secondary">' . t('inbound_norm.all_trunks') . '</span>'; ?></td>
                        <?php if ($key === 'all'): ?><td><?php echo t('inbound_norm.target_' . $r['target']); ?></td><?php endif; ?>
                        <td><?php echo uiStatusToggleForm($r['id'], $r['is_active'], 'rule_id'); ?></td>
                        <?php if ($canEdit): ?>
                            <td class="text-right"><div class="table-actions-cell">
                                <button type="button" class="btn btn-sm btn-icon" data-rule="<?php echo $h(json_encode($r)); ?>" onclick="openEditInboundRule(this)" title="<?php echo t('common.edit'); ?>"><i class="fas fa-edit"></i></button>
                                <button type="button" class="btn btn-sm btn-icon" data-rule="<?php echo $h(json_encode($r)); ?>" onclick="openCopyInboundRule(this)" title="<?php echo t('inbound_norm.copy'); ?>"><i class="fas fa-copy"></i></button>
                                <form method="POST" class="u-d-inline" onsubmit="return confirm(<?php echo $h(json_encode(sprintf(t('dialplan_rules.confirm_delete'), $r['rule_name']))); ?>);">
                                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                                    <input type="hidden" name="delete_rule" value="1">
                                    <input type="hidden" name="rule_id" value="<?php echo (int) $r['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-icon text-danger" title="<?php echo t('common.delete'); ?>"><i class="fas fa-trash-alt"></i></button>
                                </form>
                            </div></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php echo uiTableClose(); ?></div>
    </div>
<?php endforeach; ?>
</div>
<div class="u-fs-11 text-muted u-mb-20"><i class="fas fa-info-circle"></i> <?php echo t('inbound_norm.limit_order'); ?></div>

<div class="card mb-3">
    <div class="card-header"><div class="card-title"><i class="fas fa-flask u-primary"></i> <?php echo t('inbound_norm.simulate_title'); ?></div></div>
    <div class="dpr-sim">
        <div class="dpr-sim-input">
            <div class="u-grid-2">
                <input type="text" id="inbound_sim_number" class="form-control" maxlength="40" placeholder="+4366412345678" oninput="debounceInboundSim()">
                <select id="inbound_sim_trunk" class="form-control" onchange="runInboundSim()"><?php echo $trunkOptions; ?></select>
            </div>
        </div>
        <?php echo uiTableOpen([
            t('inbound_norm.field_target'),
            t('dialplan_rules.sim_col_rule'),
            ['label' => t('inbound_norm.sim_col_result'), 'class' => 'text-right'],
        ], ['id' => 'inbound_sim_table', 'search' => false, 'class' => 'dpr-sim-table', 'attrs' => 'data-txt-waiting="' . $h(t('dialplan_rules.sim_waiting')) . '"' . ' ' . 'data-txt-no-rule="' . $h(t('inbound_norm.no_rule')) . '"' . ' ' . 'data-txt-caller="' . $h(t('inbound_norm.target_caller')) . '"' . ' ' . 'data-txt-did="' . $h(t('inbound_norm.target_did')) . '"']); ?><tr><td colspan="3" class="text-muted text-center"><?php echo t('dialplan_rules.sim_waiting'); ?></td></tr><?php echo uiTableClose(); ?>
    </div>
</div>

<div class="modal-overlay" id="inboundRuleModal">
    <div class="modal-card u-maxw-580">
        <div class="modal-header">
            <h3 class="u-title" id="inboundRuleModalTitle"><?php echo t('inbound_norm.new_title'); ?></h3>
            <button type="button" class="btn btn-icon btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('inboundRuleModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" id="inboundRuleForm">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
            <input type="hidden" name="save_rule" value="1">
            <input type="hidden" name="rule_id" id="inbound_rule_id" value="0">
            <div class="modal-body">
                <div class="mb-3 u-d-grid u-gtc-1fr-1fr u-gap-16">
                    <div class="form-group"><label class="form-label u-strong"><?php echo t('inbound_norm.field_target'); ?></label>
                        <select name="target" id="inbound_target" class="form-control"><option value="caller"><?php echo t('inbound_norm.target_caller'); ?></option><option value="did"><?php echo t('inbound_norm.target_did'); ?></option></select></div>
                    <div class="form-group"><label class="form-label u-strong"><?php echo t('inbound_norm.field_trunk'); ?></label>
                        <select name="trunk_name" id="inbound_trunk" class="form-control"><?php echo $trunkOptions; ?></select></div>
                </div>
                <div class="form-group mb-3"><label class="form-label u-strong"><?php echo t('dialplan_rules.col_name'); ?> <span class="text-danger">*</span></label>
                    <input type="text" name="rule_name" id="inbound_rule_name" class="form-control" maxlength="120" required></div>
                <div class="form-group mb-3"><label class="form-label u-strong"><?php echo t('dialplan_rules.field_pattern'); ?> <span class="text-danger">*</span></label>
                    <input type="text" name="match_pattern" id="inbound_pattern" class="form-control" maxlength="40" required placeholder="_+43."></div>
                <div class="mb-3 u-d-grid u-gtc-1fr-1fr u-gap-16">
                    <div class="form-group"><label class="form-label"><?php echo t('dialplan_rules.strip_front'); ?></label>
                        <input type="number" min="0" max="20" name="strip_front" id="inbound_strip" class="form-control" value="0" oninput="updateInboundPreview()">
                        <div class="u-fs-11 text-muted"><?php echo t('dialplan_rules.strip_front_hint'); ?></div></div>
                    <div class="form-group"><label class="form-label"><?php echo t('dialplan_rules.prepend'); ?></label>
                        <input type="text" name="prepend" id="inbound_prepend" class="form-control" maxlength="30" placeholder="0" oninput="updateInboundPreview()">
                        <div class="u-fs-11 text-muted"><?php echo t('dialplan_rules.prepend_hint'); ?></div></div>
                </div>
                <div class="dpr-preview mb-3">
                    <div class="u-fs-12 u-strong mb-2"><i class="fas fa-magic"></i> <?php echo t('dialplan_rules.modal_preview_title'); ?></div>
                    <div class="dpr-preview-row">
                        <input type="text" id="inbound_preview_test" class="form-control form-control-sm" placeholder="<?php echo t('dialplan_rules.modal_preview_ph'); ?>" oninput="updateInboundPreview()">
                        <i class="fas fa-arrow-right text-muted"></i>
                        <span class="dpr-preview-result" id="inbound_preview_result">—</span>
                    </div>
                </div>
                <div class="form-check"><label class="custom-control">
                    <input type="checkbox" name="is_active" id="inbound_is_active" class="custom-control-input" value="1" checked>
                    <span class="custom-control-label"><?php echo t('dialplan_rules.field_active'); ?></span>
                </label></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="UIHelper.closeOverlayModal('inboundRuleModal')"><?php echo t('common.cancel'); ?></button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
            </div>
        </form>
    </div>
</div>
<script>
    window.INBOUND_NORM_I18N = <?php echo json_encode(['new' => t('inbound_norm.new_title'), 'edit' => t('inbound_norm.edit_title'), 'copy' => t('inbound_norm.copy_title'), 'copy_suffix' => t('dialplan_rules.copy_suffix')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
</script>
<script src="<?php echo asset('/assets/js/inbound_norm.js'); ?>"></script>
