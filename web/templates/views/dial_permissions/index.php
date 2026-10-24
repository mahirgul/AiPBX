<?php
/**
 * Calling Permission Groups & Rules View
 */
$selected_group = null;
foreach ($groups as $g) {
    if ((int)$g['id'] === (int)$selected_group_id) {
        $selected_group = $g;
        break;
    }
}
if (!$selected_group && !empty($groups)) {
    $selected_group = $groups[0];
    $selected_group_id = (int)$selected_group['id'];
}
$ng = $night_guard ?? NightGuardService::DEFAULTS;
$canEdit = hasModulePermission('dial_permissions', 'edit');
?>

<form method="POST" class="card mb-3" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCSRFToken()); ?>">
    <input type="hidden" name="save_night_guard" value="1">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-moon u-primary"></i> <?php echo t('night_guard.title'); ?>
            <span class="badge <?php echo $ng['night_guard_enabled'] === '1' ? 'badge-success' : 'badge-primary'; ?>" style="margin-left: 8px; font-size: 11px;"><?php echo t($ng['night_guard_enabled'] === '1' ? 'night_guard.on' : 'night_guard.off'); ?></span>
        </div>
    </div>
    <p class="u-muted u-fs-12" style="margin: 0 0 12px;"><?php echo t('night_guard.help'); ?></p>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px;">
        <div class="form-group">
            <label class="form-label"><?php echo t('night_guard.field_enabled'); ?></label>
            <select name="night_guard_enabled" class="form-control" <?php echo $canEdit ? '' : 'disabled'; ?>>
                <option value="0" <?php echo $ng['night_guard_enabled'] !== '1' ? 'selected' : ''; ?>><?php echo t('night_guard.off'); ?></option>
                <option value="1" <?php echo $ng['night_guard_enabled'] === '1' ? 'selected' : ''; ?>><?php echo t('night_guard.on'); ?></option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label"><?php echo t('night_guard.field_hours'); ?></label>
            <div style="display: flex; gap: 6px; align-items: center;">
                <input type="time" name="night_guard_from" class="form-control" value="<?php echo htmlspecialchars($ng['night_guard_from']); ?>">
                <span>–</span>
                <input type="time" name="night_guard_to" class="form-control" value="<?php echo htmlspecialchars($ng['night_guard_to']); ?>">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label"><?php echo t('night_guard.field_weekend'); ?></label>
            <select name="night_guard_weekend" class="form-control">
                <option value="1" <?php echo $ng['night_guard_weekend'] === '1' ? 'selected' : ''; ?>><?php echo t('night_guard.weekend_yes'); ?></option>
                <option value="0" <?php echo $ng['night_guard_weekend'] !== '1' ? 'selected' : ''; ?>><?php echo t('night_guard.weekend_no'); ?></option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label"><?php echo t('night_guard.field_prefixes'); ?></label>
            <input type="text" name="night_guard_prefixes" class="form-control" value="<?php echo htmlspecialchars($ng['night_guard_prefixes']); ?>" placeholder="00, 0900">
            <small class="u-hint"><?php echo t('night_guard.prefixes_help'); ?></small>
        </div>
        <div class="form-group">
            <label class="form-label"><?php echo t('night_guard.field_action'); ?></label>
            <select name="night_guard_action" class="form-control">
                <option value="block" <?php echo $ng['night_guard_action'] !== 'pin' ? 'selected' : ''; ?>><?php echo t('night_guard.action_block'); ?></option>
                <option value="pin" <?php echo $ng['night_guard_action'] === 'pin' ? 'selected' : ''; ?>><?php echo t('night_guard.action_pin'); ?></option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label"><?php echo t('night_guard.field_pin'); ?></label>
            <input type="password" name="night_guard_pin" class="form-control" inputmode="numeric" autocomplete="new-password" placeholder="<?php echo $ng['night_guard_pin'] !== '' ? '••••' : ''; ?>">
            <small class="u-hint"><?php echo t('night_guard.pin_help'); ?></small>
        </div>
    </div>
    <?php if ($canEdit): ?>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
    <?php endif; ?>
</form>

<div class="card mb-3">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-shield-alt u-primary"></i> <?php echo t('dial_permissions.title'); ?>
        </div>
        <div class="u-flex-center">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('permHelpBox')" title="<?php echo t('common.module_guide'); ?>">
                <i class="fas fa-question-circle"></i>
            </button>
            <?php if (hasModulePermission('dial_permissions', 'edit')): ?>
                <button class="btn btn-primary btn-sm" onclick="openCreateGroupModal()" title="<?php echo t('dial_permissions.new_group_btn'); ?>">
                    <i class="fas fa-plus-circle"></i>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="permHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('dial_permissions.help_title'); ?></h4>
        <p><?php echo t('dial_permissions.help_body'); ?></p>
        <ul>
            <li><strong><?php echo t('dial_permissions.help_default'); ?></strong> <?php echo t('dial_permissions.help_default_desc'); ?></li>
            <li><strong><?php echo t('dial_permissions.help_prefix'); ?></strong> <?php echo t('dial_permissions.help_prefix_desc'); ?></li>
            <li><strong><?php echo t('dial_permissions.help_exact'); ?></strong> <?php echo t('dial_permissions.help_exact_desc'); ?></li>
        </ul>
    </div>
</div>

<div style="display: grid; grid-template-columns: 340px 1fr; gap: 20px; align-items: start;">
    <!-- Left column: permission group list -->
    <div class="card" style="padding: 16px;">
        <div class="card-header u-mb-14">
            <div class="card-title">
                <i class="fas fa-layer-group u-primary"></i> <?php echo t('dial_permissions.groups_list'); ?>
            </div>
            <span class="badge badge-secondary"><?php echo count($groups); ?></span>
        </div>

        <div style="display: flex; flex-direction: column; gap: 8px;">
            <?php foreach ($groups as $g): ?>
                <?php 
                $isSelected = ((int)$g['id'] === (int)$selected_group_id);
                $isDefault = ((int)$g['id'] === 1);
                ?>
                <div style="padding: 12px 14px; border-radius: 10px; border: 1px solid <?php echo $isSelected ? 'var(--primary)' : 'var(--border-color)'; ?>; background: <?php echo $isSelected ? 'rgba(var(--primary-rgb, 2, 132, 199), 0.08)' : 'var(--bg-card)'; ?>; cursor: pointer; transition: all 0.2s;" onclick="location.href='/dial-permissions?group_id=<?php echo $g['id']; ?>'">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                        <span class="u-fw-700 u-fs-14 u-text-main">
                            <?php echo htmlspecialchars($g['group_name']); ?>
                            <?php if ($isDefault): ?>
                                <span class="badge badge-info u-fs-10"><?php echo t('dial_permissions.default_badge'); ?></span>
                            <?php endif; ?>
                        </span>
                        <?php if ($g['default_action'] === 'allow'): ?>
                            <span class="badge badge-success u-fs-10"><?php echo t('dial_permissions.default_allow'); ?></span>
                        <?php else: ?>
                            <span class="badge badge-danger u-fs-10"><?php echo t('dial_permissions.default_deny'); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($g['description'])): ?>
                        <div class="u-fs-12 u-muted u-mb-8"><?php echo htmlspecialchars($g['description']); ?></div>
                    <?php endif; ?>
                    <div class="u-flex-between u-muted u-fs-11">
                        <span><i class="fas fa-list-ol"></i> <?php echo (int)$g['rules_count']; ?> <?php echo t('dial_permissions.rules'); ?></span>
                        <span><i class="fas fa-phone-alt"></i> <?php echo (int)$g['users_count']; ?> <?php echo t('dial_permissions.members'); ?></span>
                        <?php if (hasModulePermission('dial_permissions', 'edit')): ?>
                            <div style="display: inline-flex; gap: 4px;" onclick="event.stopPropagation();">
                                <button type="button" class="btn btn-secondary btn-sm" style="padding: 2px 6px; font-size: 10px;" onclick='openEditGroupModal(<?php echo jsArg($g); ?>)' title="<?php echo t('common.edit'); ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if (!$isDefault): ?>
                                    <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(t('dial_permissions.confirm_delete_group')), ENT_QUOTES); ?>);">
                                        <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                        <input type="hidden" name="delete_group" value="1">
                                        <input type="hidden" name="group_id" value="<?php echo $g['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" style="padding: 2px 6px; font-size: 10px;" title="<?php echo t('common.delete'); ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Right column: rules of the selected group -->
    <div class="card u-p-20">
        <div class="card-header u-mb-16">
            <div>
                <div class="card-title">
                    <i class="fas fa-sliders-h u-primary"></i> 
                    <span><?php echo htmlspecialchars($selected_group['group_name'] ?? ''); ?></span>
                    <span style="font-size: 13px; font-weight: normal; color: var(--text-muted); margin-left: 8px;">
                        (<?php echo t('dial_permissions.base_mode'); ?> 
                        <strong><?php echo ($selected_group['default_action'] ?? 'allow') === 'allow' ? t('dial_permissions.mode_blacklist') : t('dial_permissions.mode_whitelist'); ?></strong>)
                    </span>
                </div>
            </div>
            <?php if (hasModulePermission('dial_permissions', 'edit') && $selected_group): ?>
                <div class="u-flex-gap">
                    <button class="btn btn-primary btn-sm" onclick="openCreateRuleModal(<?php echo $selected_group['id']; ?>)">
                        <i class="fas fa-plus"></i> <?php echo t('dial_permissions.add_rule_btn'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <?php if (hasModulePermission('dial_permissions', 'edit') && $selected_group): ?>
            <!-- Quick rule buttons -->
            <div style="background: var(--bg-input); padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 12px;">
                <span class="u-fw-600 u-text-main"><i class="fas fa-bolt text-warning"></i> <?php echo t('dial_permissions.quick_rules'); ?></span>
                <button type="button" class="btn btn-secondary btn-sm" onclick="quickAddRule(<?php echo $selected_group['id']; ?>, '0', 'prefix', 'deny', <?php echo htmlspecialchars(json_encode(t('dial_permissions.qr_long')), ENT_QUOTES); ?>)" style="padding: 2px 8px; font-size: 11px;">
                    <i class="fas fa-ban text-danger"></i> <?php echo t('dial_permissions.qb_zero'); ?>
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="quickAddRule(<?php echo $selected_group['id']; ?>, '05', 'prefix', 'deny', <?php echo htmlspecialchars(json_encode(t('dial_permissions.qr_mobile')), ENT_QUOTES); ?>)" style="padding: 2px 8px; font-size: 11px;">
                    <i class="fas fa-ban text-danger"></i> <?php echo t('dial_permissions.qb_mobile'); ?>
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="quickAddRule(<?php echo $selected_group['id']; ?>, '00', 'prefix', 'deny', <?php echo htmlspecialchars(json_encode(t('dial_permissions.qr_intl')), ENT_QUOTES); ?>)" style="padding: 2px 8px; font-size: 11px;">
                    <i class="fas fa-ban text-danger"></i> <?php echo t('dial_permissions.qb_intl'); ?>
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="quickAddRule(<?php echo $selected_group['id']; ?>, '0900', 'prefix', 'deny', <?php echo htmlspecialchars(json_encode(t('dial_permissions.qr_premium')), ENT_QUOTES); ?>)" style="padding: 2px 8px; font-size: 11px;">
                    <i class="fas fa-ban text-danger"></i> <?php echo t('dial_permissions.qb_premium'); ?>
                </button>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 70px;"><?php echo t('dial_permissions.col_priority'); ?></th>
                        <th><?php echo t('dial_permissions.col_pattern'); ?></th>
                        <th><?php echo t('dial_permissions.col_type'); ?></th>
                        <th><?php echo t('dial_permissions.col_action'); ?></th>
                        <th><?php echo t('dial_permissions.col_desc'); ?></th>
                        <th class="text-right" style="width: 100px;"><?php echo t('dial_permissions.col_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rules)): ?>
                        <?php echo uiTableEmptyRow(6, t('dial_permissions.no_rules'), 'fa-filter'); ?>
                    <?php else: ?>
                        <?php foreach ($rules as $r): ?>
                            <tr>
                                <td><span class="badge badge-secondary"><?php echo (int)$r['priority']; ?></span></td>
                                <td>
                                    <strong style="font-size: 14px; letter-spacing: 0.5px; font-family: monospace;">
                                        <?php echo htmlspecialchars($r['pattern']); ?>
                                        <?php if ($r['pattern_type'] === 'prefix'): ?>*<?php endif; ?>
                                    </strong>
                                </td>
                                <td>
                                    <?php if ($r['pattern_type'] === 'prefix'): ?>
                                        <span class="badge badge-info"><i class="fas fa-arrow-right"></i> <?php echo t('dial_permissions.type_prefix'); ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary"><i class="fas fa-equals"></i> <?php echo t('dial_permissions.type_exact'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($r['action'] === 'allow'): ?>
                                        <span class="badge badge-success"><i class="fas fa-check"></i> <?php echo t('dial_permissions.action_allow'); ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-danger"><i class="fas fa-ban"></i> <?php echo t('dial_permissions.action_deny'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="u-muted u-fs-12"><?php echo htmlspecialchars($r['description'] ?? '-'); ?></td>
                                <td class="text-right">
                                    <?php if (hasModulePermission('dial_permissions', 'edit')): ?>
                                        <div style="display: inline-flex; gap: 4px;">
                                            <button type="button" class="btn btn-secondary btn-sm" onclick='openEditRuleModal(<?php echo jsArg($r); ?>)' title="<?php echo t('common.edit'); ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(t('dial_permissions.confirm_delete_rule')), ENT_QUOTES); ?>);">
                                                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                                <input type="hidden" name="delete_rule" value="1">
                                                <input type="hidden" name="rule_id" value="<?php echo $r['id']; ?>">
                                                <input type="hidden" name="group_id" value="<?php echo (int) $selected_group_id; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" title="<?php echo t('common.delete'); ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: add / edit permission group -->
<div class="modal-overlay" id="groupModal">
    <div class="modal-card" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="u-title" id="groupModalTitle"><i class="fas fa-shield-alt u-primary"></i> <?php echo t('dial_permissions.group_modal'); ?></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('groupModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_group" value="1">
                <input type="hidden" name="id" id="modal_group_id" value="0">

                <div class="form-group">
                    <label class="form-label"><?php echo t('dial_permissions.group_name'); ?></label>
                    <input type="text" name="group_name" id="modal_group_name" class="form-control" placeholder="<?php echo t('dial_permissions.group_name_ph'); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('dial_permissions.description'); ?></label>
                    <input type="text" name="description" id="modal_group_desc" class="form-control" placeholder="<?php echo t('dial_permissions.group_desc_ph'); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('dial_permissions.default_action'); ?></label>
                    <select name="default_action" id="modal_default_action" class="form-control">
                        <option value="allow"><?php echo t('dial_permissions.default_allow_opt'); ?></option>
                        <option value="deny"><?php echo t('dial_permissions.default_deny_opt'); ?></option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label u-check-label">
                        <input type="checkbox" name="is_active" id="modal_group_active" value="1" checked class="u-check">
                        <span class="u-fw-600"><?php echo t('common.active'); ?></span>
                    </label>
                </div>

                <?php echo uiModalFooter("UIHelper.closeOverlayModal('groupModal')", t('common.save'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>

<!-- Modal: add / edit rule -->
<div class="modal-overlay" id="ruleModal">
    <div class="modal-card u-maxw-520">
        <div class="modal-header">
            <h3 class="u-title" id="ruleModalTitle"><i class="fas fa-filter u-primary"></i> <?php echo t('dial_permissions.rule'); ?></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('ruleModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" id="ruleForm" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_rule" value="1">
                <input type="hidden" name="id" id="modal_rule_id" value="0">
                <input type="hidden" name="group_id" id="modal_rule_group_id" value="<?php echo $selected_group_id; ?>">

                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('dial_permissions.pattern'); ?></label>
                        <input type="text" name="pattern" id="modal_rule_pattern" class="form-control" placeholder="<?php echo t('dial_permissions.pattern_ph'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('dial_permissions.col_type'); ?></label>
                        <select name="pattern_type" id="modal_rule_pattern_type" class="form-control">
                            <option value="prefix"><?php echo t('dial_permissions.match_prefix'); ?></option>
                            <option value="exact"><?php echo t('dial_permissions.match_exact'); ?></option>
                        </select>
                    </div>
                </div>

                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('dial_permissions.action'); ?></label>
                        <select name="action" id="modal_rule_action" class="form-control">
                            <option value="deny"><?php echo t('dial_permissions.action_deny_opt'); ?></option>
                            <option value="allow"><?php echo t('dial_permissions.action_allow_opt'); ?></option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('dial_permissions.priority'); ?></label>
                        <input type="number" name="priority" id="modal_rule_priority" class="form-control" value="10" min="1" max="999">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('dial_permissions.desc_optional'); ?></label>
                    <input type="text" name="description" id="modal_rule_desc" class="form-control" placeholder="<?php echo t('dial_permissions.rule_desc_ph'); ?>">
                </div>

                <?php echo uiModalFooter("UIHelper.closeOverlayModal('ruleModal')", t('common.save'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo asset('/assets/js/dial_permissions.js'); ?>"></script>
