<?php
/**
 * Call normalization rules (/dialplan-rules): settings (on/off, country presets),
 * rules per device, and the simulator. Script: assets/js/dialplan_rules.js,
 * styles: assets/css/pages/dialplan_rules.css.
 */
$can_edit = hasModulePermission('outbound_routes', 'edit');
$csrf = htmlspecialchars(getCSRFToken());
$deviceTabs = [
    'all'     => ['fa-list', t('dialplan_rules.tab_all')],
    'mobile'  => ['fa-mobile-alt', t('dialplan_rules.tab_mobile')],
    'desktop' => ['fa-desktop', t('dialplan_rules.tab_desktop')],
    'web'     => ['fa-globe', t('dialplan_rules.tab_web')],
];
$deviceBadge = static function (string $type): string {
    return match ($type) {
        'mobile'  => '<span class="badge badge-primary"><i class="fas fa-mobile-alt"></i> ' . t('dialplan_rules.tab_mobile') . '</span>',
        'desktop' => '<span class="badge badge-info"><i class="fas fa-desktop"></i> ' . t('dialplan_rules.tab_desktop') . '</span>',
        'web'     => '<span class="badge badge-secondary"><i class="fas fa-globe"></i> ' . t('dialplan_rules.tab_web') . '</span>',
        default   => '<span class="badge badge-dark"><i class="fas fa-asterisk"></i> ' . t('dialplan_rules.type_all') . '</span>',
    };
};
?>
<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/dialplan_rules.css'); ?>">

<div class="card mb-3">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-sliders-h u-primary"></i> <?php echo t('dialplan_rules.title'); ?>
        </div>
        <div class="u-flex-gap">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('dialplanRulesHelpBox')" title="<?php echo t('common.module_guide'); ?>">
                <i class="fas fa-question-circle"></i>
            </button>
            <?php if ($can_edit): ?>
                <button type="button" class="btn btn-primary btn-sm" onclick="openCreateRuleModal(document.querySelector('.settings-tab-btn.active')?.dataset.tab || 'all')">
                    <i class="fas fa-plus-circle"></i> <?php echo t('dialplan_rules.btn_add_rule'); ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="module-help-box" id="dialplanRulesHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('dialplan_rules.help_title'); ?></h4>
        <p><?php echo t('dialplan_rules.help_body'); ?></p>
        <ul>
            <li><strong><?php echo t('dialplan_rules.help_mobile_title'); ?>:</strong> <?php echo t('dialplan_rules.help_mobile_desc'); ?></li>
            <li><strong><?php echo t('dialplan_rules.help_desktop_title'); ?>:</strong> <?php echo t('dialplan_rules.help_desktop_desc'); ?></li>
            <li><strong><?php echo t('dialplan_rules.help_web_title'); ?>:</strong> <?php echo t('dialplan_rules.help_web_desc'); ?></li>
            <li><strong><?php echo t('dialplan_rules.help_internal_title'); ?>:</strong> <?php echo t('dialplan_rules.help_internal_desc'); ?></li>
        </ul>
    </div>

    <!-- Settings: on/off for all rules, country presets -->
    <div class="dpr-settings">
        <div class="dpr-setting">
            <div>
                <div class="dpr-setting-title">
                    <?php echo t('dialplan_rules.enabled_label'); ?>
                    <span class="badge <?php echo $rules_enabled ? 'badge-success' : 'badge-danger'; ?>"><?php echo t($rules_enabled ? 'dialplan_rules.state_on' : 'dialplan_rules.state_off'); ?></span>
                </div>
                <div class="dpr-setting-desc"><?php echo t('dialplan_rules.enabled_desc'); ?></div>
            </div>
            <?php if ($can_edit): ?>
                <form method="POST" <?php if ($rules_enabled): ?>onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(t('dialplan_rules.confirm_turn_off')), ENT_QUOTES); ?>);"<?php endif; ?>>
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="set_enabled">
                    <input type="hidden" name="enabled" value="<?php echo $rules_enabled ? '0' : '1'; ?>">
                    <button type="submit" class="btn btn-sm <?php echo $rules_enabled ? 'btn-secondary' : 'btn-success'; ?>">
                        <i class="fas fa-power-off"></i> <?php echo t($rules_enabled ? 'dialplan_rules.btn_turn_off' : 'dialplan_rules.btn_turn_on'); ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <div class="dpr-setting">
            <div>
                <div class="dpr-setting-title"><?php echo t('dialplan_rules.country_label'); ?></div>
                <div class="dpr-setting-desc"><?php echo t('dialplan_rules.country_desc'); ?></div>
            </div>
            <?php if ($can_edit): ?>
                <form method="POST" class="dpr-country-form" onsubmit="return confirmLoadDefaults(this);"
                      data-confirm-tmpl="<?php echo htmlspecialchars(t('dialplan_rules.confirm_load_defaults_cc')); ?>"
                      data-msg-required="<?php echo htmlspecialchars(t('dialplan_rules.err_country_required')); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="load_defaults">
                    <select name="country_code" class="form-control form-control-sm">
                        <option value=""><?php echo t('dialplan_rules.country_none'); ?></option>
                        <?php foreach (($all_countries ?? []) as $cCode => $cInfo): ?>
                            <option value="<?php echo htmlspecialchars((string)$cCode); ?>" <?php echo ((string)$cCode === $country_code) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(trim(($cInfo['flag'] ?? '') . ' ' . DialplanRuleService::getCountryName((string)$cCode) . ' (+' . $cCode . ')' . (isset($cInfo['trunk_prefix'], $cInfo['intl_prefix']) ? '' : ' ' . t('dialplan_rules.no_presets_suffix')))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-secondary btn-sm">
                        <i class="fas fa-magic"></i> <?php echo t('dialplan_rules.btn_load_defaults'); ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$rules_enabled): ?>
    <div class="alert alert-warning mb-3"><i class="fas fa-power-off"></i> <?php echo t('dialplan_rules.disabled_banner'); ?></div>
<?php endif; ?>

<!-- Rules per device -->
<div class="settings-tabs u-mb-12">
    <?php foreach ($deviceTabs as $typeKey => [$icon, $label]): ?>
        <?php $n = ($typeKey === 'all') ? count($all_rules) : count($rules_by_type[$typeKey] ?? []); ?>
        <button type="button" class="settings-tab-btn <?php echo $active_tab === $typeKey ? 'active' : ''; ?>" data-tab="<?php echo $typeKey; ?>" onclick="switchSettingsTab('<?php echo $typeKey; ?>', this)">
            <i class="fas <?php echo $icon; ?>"></i> <?php echo $label; ?> <span class="dpr-count"><?php echo $n; ?></span>
        </button>
    <?php endforeach; ?>
</div>

<?php foreach ($deviceTabs as $typeKey => $_tab): ?>
    <?php $currentRules = ($typeKey === 'all') ? $all_rules : ($rules_by_type[$typeKey] ?? []); ?>
    <div id="tab_<?php echo $typeKey; ?>" class="settings-tab-pane <?php echo $active_tab === $typeKey ? 'active' : ''; ?>" style="display: <?php echo $active_tab === $typeKey ? 'block' : 'none'; ?>;">
        <div class="card mb-3">
            <div class="table-responsive">
                <table class="data-table no-dt" id="table-<?php echo $typeKey; ?>">
                    <thead>
                        <tr>
                            <th><?php echo t('dialplan_rules.col_name'); ?></th>
                            <th><?php echo t('dialplan_rules.col_pattern'); ?></th>
                            <th class="col-hide-mobile"><?php echo t('dialplan_rules.col_transform'); ?></th>
                            <?php if ($typeKey === 'all'): ?><th><?php echo t('dialplan_rules.col_client_type'); ?></th><?php endif; ?>
                            <th><?php echo t('dialplan_rules.col_status'); ?></th>
                            <?php if ($can_edit): ?><th class="text-right"><?php echo t('dialplan_rules.col_actions'); ?></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($currentRules)): ?>
                            <?php echo uiTableEmptyRow(4 + ($can_edit ? 1 : 0) + ($typeKey === 'all' ? 1 : 0), t(empty($all_rules) ? 'dialplan_rules.empty_hint' : 'dialplan_rules.empty'), 'fa-sliders-h'); ?>
                        <?php else: ?>
                            <?php foreach ($currentRules as $r): ?>
                                <?php
                                    $steps = [];
                                    if ((int)$r['strip_front'] > 0) { $steps[] = t('dialplan_rules.strip_front') . ' <b>' . (int)$r['strip_front'] . '</b>'; }
                                    if ((int)$r['strip_back'] > 0) { $steps[] = t('dialplan_rules.strip_back') . ' <b>' . (int)$r['strip_back'] . '</b>'; }
                                    if ((string)$r['prepend'] !== '') { $steps[] = t('dialplan_rules.prepend') . ' <code>' . htmlspecialchars((string)$r['prepend']) . '</code>'; }
                                    if ((string)$r['append'] !== '') { $steps[] = t('dialplan_rules.append') . ' <code>' . htmlspecialchars((string)$r['append']) . '</code>'; }
                                ?>
                                <tr class="<?php echo empty($r['is_active']) ? 'dpr-row-off' : ''; ?>">
                                    <td>
                                        <div class="u-strong"><?php echo htmlspecialchars($r['rule_name']); ?></div>
                                        <?php if (!empty($r['description'])): ?>
                                            <div class="u-fs-11 text-muted"><?php echo htmlspecialchars($r['description']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><code class="dpr-pattern"><?php echo htmlspecialchars($r['match_pattern']); ?></code></td>
                                    <td class="col-hide-mobile u-fs-12"><?php echo $steps ? implode(' · ', $steps) : '<span class="text-muted">—</span>'; ?></td>
                                    <?php if ($typeKey === 'all'): ?><td><?php echo $deviceBadge((string)$r['client_type']); ?></td><?php endif; ?>
                                    <td><?php echo uiStatusToggleForm($r['id'], $r['is_active'], 'rule_id'); ?></td>
                                    <?php if ($can_edit): ?>
                                        <td class="text-right">
                                            <div class="table-actions-cell">
                                                <button type="button" class="btn btn-sm btn-icon" onclick='openEditRuleModal(<?php echo json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' title="<?php echo htmlspecialchars(t('common.edit')); ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-icon" onclick='openCopyRuleModal(<?php echo json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' title="<?php echo htmlspecialchars(t('common.copy')); ?>">
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(sprintf(t('dialplan_rules.confirm_delete'), $r['rule_name'])), ENT_QUOTES); ?>);">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                    <input type="hidden" name="action" value="delete_rule">
                                                    <input type="hidden" name="rule_id" value="<?php echo (int)$r['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-icon text-danger" title="<?php echo htmlspecialchars(t('common.delete')); ?>">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<div class="u-fs-11 text-muted u-mb-20"><i class="fas fa-info-circle"></i> <?php echo t('dialplan_rules.order_note'); ?></div>

<!-- Simulator -->
<div class="card mb-3">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-flask u-primary"></i> <?php echo t('dialplan_rules.sim_title'); ?></div>
    </div>
    <div class="dpr-sim">
        <div class="dpr-sim-input">
            <input type="text" id="sim_input_number" class="form-control" placeholder="<?php echo t('dialplan_rules.sim_placeholder'); ?>" oninput="debounceSimulation()" autocomplete="off">
            <div class="dpr-sim-examples">
                <span class="text-muted"><?php echo t('dialplan_rules.sim_hint'); ?>:</span>
                <?php foreach (['+12125551234', '+442071234567', '0049301234567', '00442071234567'] as $ex): ?>
                    <button type="button" class="dpr-chip" onclick="setSimNumber('<?php echo $ex; ?>')"><?php echo $ex; ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <table class="data-table no-dt dpr-sim-table" id="sim_result_table"
               data-txt-waiting="<?php echo htmlspecialchars(t('dialplan_rules.sim_waiting')); ?>"
               data-txt-no-rule="<?php echo htmlspecialchars(t('dialplan_rules.sim_no_rule')); ?>"
               data-txt-disabled="<?php echo htmlspecialchars(t('dialplan_rules.disabled_banner')); ?>"
               data-txt-desktop="<?php echo htmlspecialchars(t('dialplan_rules.tab_desktop')); ?>"
               data-txt-mobile="<?php echo htmlspecialchars(t('dialplan_rules.tab_mobile')); ?>"
               data-txt-web="<?php echo htmlspecialchars(t('dialplan_rules.tab_web')); ?>">
            <thead>
                <tr>
                    <th><?php echo t('dialplan_rules.col_client_type'); ?></th>
                    <th><?php echo t('dialplan_rules.sim_col_rule'); ?></th>
                    <th class="text-right"><?php echo t('dialplan_rules.sim_col_result'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="3" class="text-muted text-center"><?php echo t('dialplan_rules.sim_waiting'); ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Create / Edit Rule Modal -->
<div class="modal-overlay" id="ruleModal">
    <div class="modal-card" style="max-width: 580px;">
        <div class="modal-header">
            <h3 class="modal-title" id="ruleModalTitle"><?php echo t('dialplan_rules.modal_title_new'); ?></h3>
            <button type="button" class="modal-close" onclick="UIHelper.closeModal('ruleModal')">&times;</button>
        </div>
        <form method="POST" id="ruleForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="save_rule">
            <input type="hidden" name="rule_id" id="modal_rule_id" value="0">

            <div class="modal-body">
                <div class="form-group mb-3">
                    <label class="form-label u-strong"><?php echo t('dialplan_rules.field_client_type'); ?> <span class="text-danger">*</span></label>
                    <select name="client_type" id="modal_client_type" class="form-control" required>
                        <option value="mobile"><?php echo t('dialplan_rules.tab_mobile'); ?></option>
                        <option value="desktop"><?php echo t('dialplan_rules.tab_desktop'); ?></option>
                        <option value="web"><?php echo t('dialplan_rules.tab_web'); ?></option>
                        <option value="all"><?php echo t('dialplan_rules.type_all'); ?></option>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label u-strong"><?php echo t('dialplan_rules.field_name'); ?> <span class="text-danger">*</span></label>
                    <input type="text" name="rule_name" id="modal_rule_name" class="form-control" placeholder="<?php echo t('dialplan_rules.name_ph'); ?>" required>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label u-strong"><?php echo t('dialplan_rules.field_pattern'); ?> <span class="text-danger">*</span></label>
                    <input type="text" name="match_pattern" id="modal_match_pattern" class="form-control" placeholder="_+X. / _000. / _9NXXNXXXXXX" required>
                    <div class="u-fs-11 text-muted u-mt-4">
                        <?php echo t('dialplan_rules.pattern_hint'); ?>: <code>_+X.</code>, <code>_+44.</code>, <code>_000.</code>, <code>_9.</code>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;" class="mb-3">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('dialplan_rules.strip_front'); ?></label>
                        <input type="number" min="0" max="32" name="strip_front" id="modal_strip_front" class="form-control" value="0">
                        <div class="u-fs-11 text-muted"><?php echo t('dialplan_rules.strip_front_hint'); ?></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('dialplan_rules.prepend'); ?></label>
                        <input type="text" name="prepend" id="modal_prepend" class="form-control" placeholder="0 / 00">
                        <div class="u-fs-11 text-muted"><?php echo t('dialplan_rules.prepend_hint'); ?></div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;" class="mb-3">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('dialplan_rules.strip_back'); ?></label>
                        <input type="number" min="0" max="32" name="strip_back" id="modal_strip_back" class="form-control" value="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('dialplan_rules.append'); ?></label>
                        <input type="text" name="append" id="modal_append" class="form-control">
                    </div>
                </div>

                <div class="dpr-preview mb-3">
                    <div class="u-fs-12 u-strong mb-2"><i class="fas fa-magic"></i> <?php echo t('dialplan_rules.modal_preview_title'); ?></div>
                    <div class="dpr-preview-row">
                        <input type="text" id="modal_preview_test" class="form-control form-control-sm" placeholder="<?php echo t('dialplan_rules.modal_preview_ph'); ?>">
                        <i class="fas fa-arrow-right text-muted"></i>
                        <span class="dpr-preview-result" id="modal_preview_result">—</span>
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label"><?php echo t('dialplan_rules.field_description'); ?></label>
                    <textarea name="description" id="modal_description" class="form-control" rows="2" placeholder="<?php echo t('dialplan_rules.desc_ph'); ?>"></textarea>
                </div>

                <div class="form-check">
                    <label class="custom-control custom-checkbox">
                        <input type="checkbox" name="is_active" id="modal_is_active" class="custom-control-input" value="1" checked>
                        <span class="custom-control-label"><?php echo t('dialplan_rules.field_active'); ?></span>
                    </label>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="UIHelper.closeModal('ruleModal')"><?php echo t('common.cancel'); ?></button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
    window.DPR_I18N = <?php echo json_encode([
        'modal_new'   => t('dialplan_rules.modal_title_new'),
        'modal_edit'  => t('dialplan_rules.modal_title_edit'),
        'modal_copy'  => t('dialplan_rules.modal_title_copy'),
        'copy_suffix' => t('dialplan_rules.copy_suffix'),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
</script>
<script src="<?php echo asset('/assets/js/dialplan_rules.js'); ?>"></script>
