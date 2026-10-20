<?php
/**
 * Ingress Call Normalization / Dialplan Rules View (/dialplan-rules)
 */
$can_edit = hasModulePermission('outbound_routes', 'edit');
?>

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
                <form method="POST" style="display: inline;" onsubmit="return confirm('<?php echo htmlspecialchars(t('dialplan_rules.confirm_load_defaults')); ?>');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCSRFToken()); ?>">
                    <input type="hidden" name="action" value="load_defaults">
                    <button type="submit" class="btn btn-secondary btn-sm" title="<?php echo t('dialplan_rules.btn_load_defaults'); ?>">
                        <i class="fas fa-magic"></i> <?php echo t('dialplan_rules.btn_load_defaults'); ?>
                    </button>
                </form>
                <button type="button" class="btn btn-primary btn-sm" onclick="openCreateRuleModal('<?php echo htmlspecialchars($active_tab); ?>')" title="<?php echo t('common.add_new'); ?>">
                    <i class="fas fa-plus-circle"></i> <?php echo t('dialplan_rules.btn_add_rule'); ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Collapsible Help Box -->
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
</div>

<!-- Live Simulation Tester Widget -->
<div class="card mb-4" style="background: var(--bg-card); border-left: 4px solid var(--color-primary);">
    <div class="card-header" style="border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
        <div class="card-title" style="font-size: 14px;">
            <i class="fas fa-flask u-primary"></i> <?php echo t('dialplan_rules.sim_title'); ?>
            <span class="text-muted u-fs-12" style="font-weight: normal; margin-left: 6px;">(<?php echo t('dialplan_rules.sim_subtitle'); ?>)</span>
        </div>
    </div>
    <div style="padding: 16px;">
        <div style="display: grid; grid-template-columns: 200px 1fr 1fr; gap: 16px; align-items: start;">
            <div>
                <label class="form-label u-fs-12 u-strong"><?php echo t('dialplan_rules.col_client_type'); ?></label>
                <select id="sim_select_client" class="form-control" onchange="runLiveSimulation()">
                    <option value="mobile">📱 <?php echo t('dialplan_rules.tab_mobile'); ?></option>
                    <option value="desktop">☎️ <?php echo t('dialplan_rules.tab_desktop'); ?></option>
                    <option value="web">💻 <?php echo t('dialplan_rules.tab_web'); ?></option>
                </select>
            </div>
            <div>
                <label class="form-label u-fs-12 u-strong"><?php echo t('dialplan_rules.sim_input_label'); ?></label>
                <div class="input-group">
                    <input type="text" id="sim_input_number" class="form-control" placeholder="<?php echo t('dialplan_rules.sim_placeholder'); ?>" value="+905379902352">
                </div>
                <div class="u-fs-11 text-muted u-mt-4">
                    <?php echo t('dialplan_rules.sim_hint'); ?>: <code>+905379902352</code>, <code>005379902352</code>, <code>5379902352</code>, <code>0012125551234</code>
                </div>
            </div>
            <div>
                <label class="form-label u-fs-12 u-strong"><?php echo t('dialplan_rules.sim_result_label'); ?></label>
                <div id="sim_result_container">
                    <div class="text-muted u-fs-12" style="padding: 12px; text-align: center;">
                        <i class="fas fa-keyboard"></i> <?php echo t('dialplan_rules.sim_waiting'); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs for Client Types -->
<div class="u-flex-gap mb-3" style="border-bottom: 2px solid var(--border-color); padding-bottom: 8px;">
    <button type="button" class="btn btn-sm tab-btn <?php echo $active_tab === 'mobile' ? 'active btn-primary' : 'btn-secondary'; ?>" id="tab-btn-mobile" onclick="switchTab('mobile')">
        <i class="fas fa-mobile-alt"></i> <?php echo t('dialplan_rules.tab_mobile'); ?>
        <span class="badge badge-secondary u-fs-10" style="margin-left: 4px;"><?php echo count($rules_by_type['mobile'] ?? []); ?></span>
    </button>
    <button type="button" class="btn btn-sm tab-btn <?php echo $active_tab === 'desktop' ? 'active btn-primary' : 'btn-secondary'; ?>" id="tab-btn-desktop" onclick="switchTab('desktop')">
        <i class="fas fa-desktop"></i> <?php echo t('dialplan_rules.tab_desktop'); ?>
        <span class="badge badge-secondary u-fs-10" style="margin-left: 4px;"><?php echo count($rules_by_type['desktop'] ?? []); ?></span>
    </button>
    <button type="button" class="btn btn-sm tab-btn <?php echo $active_tab === 'web' ? 'active btn-primary' : 'btn-secondary'; ?>" id="tab-btn-web" onclick="switchTab('web')">
        <i class="fas fa-globe"></i> <?php echo t('dialplan_rules.tab_web'); ?>
        <span class="badge badge-secondary u-fs-10" style="margin-left: 4px;"><?php echo count($rules_by_type['web'] ?? []); ?></span>
    </button>
    <button type="button" class="btn btn-sm tab-btn <?php echo $active_tab === 'all' ? 'active btn-primary' : 'btn-secondary'; ?>" id="tab-btn-all" onclick="switchTab('all')">
        <i class="fas fa-list"></i> <?php echo t('dialplan_rules.tab_all'); ?>
        <span class="badge badge-secondary u-fs-10" style="margin-left: 4px;"><?php echo count($all_rules); ?></span>
    </button>
</div>

<!-- Tab Panes -->
<?php foreach (['mobile', 'desktop', 'web', 'all'] as $typeKey): ?>
    <?php 
        $currentRules = ($typeKey === 'all') ? $all_rules : ($rules_by_type[$typeKey] ?? []);
        $tableId = 'table-' . $typeKey;
        $isDisplayed = ($active_tab === $typeKey);
    ?>
    <div class="dialplan-tab-pane" id="pane-<?php echo $typeKey; ?>" style="display: <?php echo $isDisplayed ? 'block' : 'none'; ?>;">
        <div class="card">
            <div class="table-responsive">
                <table class="data-table" id="<?php echo $tableId; ?>">
                    <thead>
                        <tr>
                            <?php if ($can_edit): ?><th class="row-drag-handle"></th><?php endif; ?>
                            <th class="col-hide-mobile u-w-50">#</th>
                            <th><?php echo t('dialplan_rules.col_name'); ?></th>
                            <th><?php echo t('dialplan_rules.col_pattern'); ?></th>
                            <th class="col-hide-mobile"><?php echo t('dialplan_rules.col_transform'); ?></th>
                            <?php if ($typeKey === 'all'): ?>
                                <th><?php echo t('dialplan_rules.col_client_type'); ?></th>
                            <?php endif; ?>
                            <th><?php echo t('dialplan_rules.col_status'); ?></th>
                            <th class="text-right"><?php echo t('dialplan_rules.col_actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($currentRules)): ?>
                            <?php echo uiTableEmptyRow(6 + ($can_edit ? 1 : 0) + ($typeKey === 'all' ? 1 : 0), t('dialplan_rules.empty'), 'fa-sliders-h'); ?>
                        <?php else: ?>
                            <?php foreach ($currentRules as $r): ?>
                                <tr data-id="<?php echo (int)$r['id']; ?>">
                                    <?php if ($can_edit): ?>
                                        <td class="row-drag-handle" title="<?php echo htmlspecialchars(t('common.drag_to_reorder')); ?>">
                                            <i class="fas fa-grip-vertical"></i>
                                        </td>
                                    <?php endif; ?>
                                    <td class="col-hide-mobile text-muted u-fs-12">#<?php echo $r['id']; ?></td>
                                    <td>
                                        <div class="u-strong"><?php echo htmlspecialchars($r['rule_name']); ?></div>
                                        <?php if (!empty($r['description'])): ?>
                                            <div class="u-fs-11 text-muted"><?php echo htmlspecialchars($r['description']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-info"><code><?php echo htmlspecialchars($r['match_pattern']); ?></code></span>
                                    </td>
                                    <td class="col-hide-mobile u-fs-11">
                                        <?php if (!empty($r['strip_front'])): ?>
                                            <span class="badge badge-secondary"><?php echo t('dialplan_rules.strip_front'); ?>: <?php echo intval($r['strip_front']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($r['prepend'])): ?>
                                            <span class="badge badge-secondary"><?php echo t('dialplan_rules.prepend'); ?>: <code><?php echo htmlspecialchars($r['prepend']); ?></code></span>
                                        <?php endif; ?>
                                        <?php if (!empty($r['strip_back'])): ?>
                                            <span class="badge badge-secondary"><?php echo t('dialplan_rules.strip_back'); ?>: <?php echo intval($r['strip_back']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($r['append'])): ?>
                                            <span class="badge badge-secondary"><?php echo t('dialplan_rules.append'); ?>: <code><?php echo htmlspecialchars($r['append']); ?></code></span>
                                        <?php endif; ?>
                                        <?php if (empty($r['strip_front']) && empty($r['strip_back']) && empty($r['prepend']) && empty($r['append'])): ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($typeKey === 'all'): ?>
                                        <td>
                                            <?php
                                                $ctBadge = match($r['client_type']) {
                                                    'mobile' => '<span class="badge badge-primary"><i class="fas fa-mobile-alt"></i> ' . t('dialplan_rules.tab_mobile') . '</span>',
                                                    'desktop' => '<span class="badge badge-info"><i class="fas fa-desktop"></i> ' . t('dialplan_rules.tab_desktop') . '</span>',
                                                    'web' => '<span class="badge badge-secondary"><i class="fas fa-globe"></i> ' . t('dialplan_rules.tab_web') . '</span>',
                                                    default => '<span class="badge badge-dark"><i class="fas fa-asterisk"></i> ' . t('dialplan_rules.type_all') . '</span>',
                                                };
                                                echo $ctBadge;
                                            ?>
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <?php echo uiStatusToggleForm($r['id'], $r['is_active'], 'rule_id'); ?>
                                    </td>
                                    <td class="text-right">
                                        <div class="table-actions-cell">
                                            <?php if ($can_edit): ?>
                                                <button type="button" class="btn btn-sm btn-icon" onclick='openEditRuleModal(<?php echo json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' title="<?php echo htmlspecialchars(t('common.edit')); ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-icon" onclick='openCopyRuleModal(<?php echo json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' title="<?php echo htmlspecialchars(t('common.copy')); ?>">
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo htmlspecialchars(sprintf(t('dialplan_rules.confirm_delete'), $r['rule_name'])); ?>');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCSRFToken()); ?>">
                                                    <input type="hidden" name="action" value="delete_rule">
                                                    <input type="hidden" name="rule_id" value="<?php echo (int)$r['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-icon text-danger" title="<?php echo htmlspecialchars(t('common.delete')); ?>">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<!-- Create / Edit Rule Modal -->
<div class="modal-overlay" id="ruleModal">
    <div class="modal-card" style="max-width: 580px;">
        <div class="modal-header">
            <h3 class="modal-title" id="ruleModalTitle"><?php echo t('dialplan_rules.modal_title_new'); ?></h3>
            <button type="button" class="modal-close" onclick="UIHelper.closeModal('ruleModal')">&times;</button>
        </div>
        <form method="POST" id="ruleForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCSRFToken()); ?>">
            <input type="hidden" name="action" value="save_rule">
            <input type="hidden" name="rule_id" id="modal_rule_id" value="0">

            <div class="modal-body">
                <div class="form-group mb-3">
                    <label class="form-label u-strong"><?php echo t('dialplan_rules.field_client_type'); ?> <span class="text-danger">*</span></label>
                    <select name="client_type" id="modal_client_type" class="form-control" required>
                        <option value="mobile">📱 <?php echo t('dialplan_rules.tab_mobile'); ?></option>
                        <option value="desktop">☎️ <?php echo t('dialplan_rules.tab_desktop'); ?></option>
                        <option value="web">💻 <?php echo t('dialplan_rules.tab_web'); ?></option>
                        <option value="all">🌐 <?php echo t('dialplan_rules.type_all'); ?></option>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label u-strong"><?php echo t('dialplan_rules.field_name'); ?> <span class="text-danger">*</span></label>
                    <input type="text" name="rule_name" id="modal_rule_name" class="form-control" placeholder="<?php echo t('dialplan_rules.name_ph'); ?>" required>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label u-strong"><?php echo t('dialplan_rules.field_pattern'); ?> <span class="text-danger">*</span></label>
                    <input type="text" name="match_pattern" id="modal_match_pattern" class="form-control" placeholder="_+90. / _00. / _5XXXXXXXXX" required>
                    <div class="u-fs-11 text-muted u-mt-4">
                        <?php echo t('dialplan_rules.pattern_hint'); ?>: <code>_+90.</code>, <code>_+X.</code>, <code>_00.</code>, <code>_5XXXXXXXXX</code>, <code>_9.</code>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;" class="mb-3">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('dialplan_rules.strip_front'); ?></label>
                        <input type="number" min="0" max="20" name="strip_front" id="modal_strip_front" class="form-control" value="0">
                        <div class="u-fs-11 text-muted"><?php echo t('dialplan_rules.strip_front_hint'); ?></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('dialplan_rules.prepend'); ?></label>
                        <input type="text" name="prepend" id="modal_prepend" class="form-control" placeholder="0 / 00 / +">
                        <div class="u-fs-11 text-muted"><?php echo t('dialplan_rules.prepend_hint'); ?></div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;" class="mb-3">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('dialplan_rules.strip_back'); ?></label>
                        <input type="number" min="0" max="20" name="strip_back" id="modal_strip_back" class="form-control" value="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('dialplan_rules.append'); ?></label>
                        <input type="text" name="append" id="modal_append" class="form-control">
                    </div>
                </div>

                <!-- Live preview inside modal -->
                <div class="card mb-3" style="background: var(--bg-surface-secondary); padding: 12px;">
                    <div class="u-fs-12 u-strong mb-2"><i class="fas fa-magic"></i> <?php echo t('dialplan_rules.modal_preview_title'); ?></div>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <input type="text" id="modal_preview_test" class="form-control form-control-sm" style="max-width: 180px;" value="+905379902352" placeholder="<?php echo t('dialplan_rules.modal_preview_ph'); ?>">
                        <i class="fas fa-arrow-right text-muted"></i>
                        <span style="font-family: monospace; font-size: 15px; font-weight: bold; color: var(--color-primary);" id="modal_preview_result">05379902352</span>
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

<script src="<?php echo asset('/assets/js/dialplan_rules.js'); ?>"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        runLiveSimulation();
    });
</script>
