<?php use PBX\Destinations\DestinationRegistry; ?>

<!-- Tabs Header -->
<div class="tc-tabs">
    <button class="tc-tab-btn active" id="tab-btn-tcs" onclick="switchTcTab('tcs')">
        <i class="fas fa-clock"></i> <?php echo t('tc.tab_conditions'); ?>
    </button>
    <button class="tc-tab-btn" id="tab-btn-tgs" onclick="switchTcTab('tgs')">
        <i class="fas fa-calendar-alt"></i> <?php echo t('tc.tab_groups'); ?> (<?php echo count($time_groups); ?>)
    </button>
</div>

<!-- TAB 1: Zaman Koşulları Listesi -->
<div class="tc-tab-pane" id="tab-pane-tcs" style="display: block;">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-clock" style="color: var(--warning);"></i> <?php echo t('tc.header'); ?>
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="button" class="btn-help" onclick="toggleModuleHelp('tcHelpBox')" title="Modül Rehberi">
                    <i class="fas fa-question-circle"></i>
                </button>
                <?php if (hasModulePermission('time_conditions', 'edit')): ?>
                    <button class="btn btn-primary btn-sm" onclick="openCreateTcModal()" title="Yeni Ekle">
                        <i class="fas fa-plus-circle"></i>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Collapsible Help Box -->
        <div class="module-help-box" id="tcHelpBox">
            <h4><i class="fas fa-info-circle"></i> <?php echo t('tc.help_title'); ?></h4>
            <?php echo t('tc.help_body'); ?><br>
            - <strong><?php echo t('tc.help_rules'); ?></strong><br>
            - <strong><?php echo t('tc.help_default'); ?></strong>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="col-hide-mobile" style="width: 50px;">#</th>
                        <th><?php echo t('tc.col_condition'); ?></th>
                        <th class="col-hide-mobile"><?php echo t('internal_number.col'); ?></th>
                        <th><?php echo t('tc.col_rules'); ?></th>
                        <th class="col-hide-mobile"><?php echo t('tc.col_default_target'); ?></th>
                        <th><?php echo t('tc.col_status'); ?></th>
                        <th class="text-right"><?php echo t('tc.col_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tcs)): ?>
                        <?php echo uiTableEmptyRow(6, t('tc.empty'), 'fa-clock'); ?>
                    <?php else: ?>
                        <?php $tcDestCache = []; ?>
                        <?php foreach ($tcs as $tc):
                            $nmMod = DestinationRegistry::getModule($tc['nomatch_dest_type']);
                            $nmName = $nmMod ? $nmMod->getName() : $tc['nomatch_dest_type'];
                            $nmLabel = DestinationRegistry::resolveLabel($tc['nomatch_dest_type'], $tc['nomatch_dest_id'], $tcDestCache);
                            $rules = !empty($tc['rules_json']) ? json_decode($tc['rules_json'], true) : [];
                            if (empty($rules) || !is_array($rules)) {
                                $rules = [[
                                    'time_group_id' => $tc['time_group_id'],
                                    'match_dest_type' => $tc['match_dest_type'],
                                    'match_dest_id' => $tc['match_dest_id'],
                                    'nomatch_dest_type' => '',
                                    'nomatch_dest_id' => ''
                                ]];
                            }
                        ?>
                            <tr>
                                <td class="col-hide-mobile text-muted" style="font-size: 12px;">#<?php echo $tc['id']; ?></td>
                                <td style="font-weight: 700; color: var(--text-main);"><?php echo htmlspecialchars($tc['title']); ?></td>
                                <td class="col-hide-mobile">
                                    <?php echo !empty($tc['internal_number'])
                                        ? '<span class="badge badge-info">' . htmlspecialchars($tc['internal_number']) . '</span>'
                                        : t('internal_number.none'); ?>
                                </td>
                                <td>
                                    <div style="display: flex; flex-direction: column; gap: 6px;">
                                        <?php foreach ($rules as $idx => $r):
                                            $tgInfo = $tg_map[$r['time_group_id']] ?? null;
                                            $tgTitle = $tgInfo ? $tgInfo['title'] : t('tc.group_prefix') . " #{$r['time_group_id']}";
                                            $mMod = DestinationRegistry::getModule($r['match_dest_type']);
                                            $mName = $mMod ? $mMod->getName() : $r['match_dest_type'];
                                            $mLabel = DestinationRegistry::resolveLabel($r['match_dest_type'], $r['match_dest_id'], $tcDestCache);
                                        ?>
                                            <div style="font-size: 12px; background: rgba(0,0,0,0.02); padding: 4px 8px; border-radius: 4px; border: 1px solid var(--border-color); display: flex; align-items: center; gap: 6px; white-space: nowrap;">
                                                <strong>#<?php echo ($idx + 1); ?>:</strong>
                                                <span class="badge badge-info" style="font-size: 10px;"><i class="fas fa-calendar-check"></i> <?php echo htmlspecialchars($tgTitle); ?></span>
                                                <span>➔</span>
                                                <span class="badge badge-success" style="font-size: 10px;"><i class="fas fa-check"></i> <?php echo htmlspecialchars($mName); ?>: <?php echo $mLabel !== null ? htmlspecialchars($mLabel) : '<span title="' . htmlspecialchars(sprintf(t('tc.not_found_tooltip'), $r['match_dest_id'])) . '"><i class="fas fa-exclamation-triangle"></i> ' . htmlspecialchars(t('tc.not_found')) . '</span>'; ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td class="col-hide-mobile">
                                    <span class="badge badge-danger"><i class="fas fa-moon"></i> <?php echo htmlspecialchars($nmName); ?>: <?php echo $nmLabel !== null ? htmlspecialchars($nmLabel) : '<span title="' . htmlspecialchars(sprintf(t('tc.not_found_tooltip'), $tc['nomatch_dest_id'])) . '"><i class="fas fa-exclamation-triangle"></i> ' . htmlspecialchars(t('tc.not_found')) . '</span>'; ?></span>
                                </td>
                                <td>
                                    <?php echo uiStatusToggleForm($tc['id'], $tc['is_active'], 'tc_id'); ?>
                                </td>
                                <td class="text-right">
                                    <?php echo uiRowActions($tc, 'openEditTcModal', 'tc_id', 'delete_time_condition', t('tc.confirm_delete')); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- TAB 2: Zaman Grupları / Mesai Şablonları Listesi -->
<div class="tc-tab-pane" id="tab-pane-tgs" style="display: none;">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-calendar-alt" style="color: var(--primary);"></i> <?php echo t('tc.groups_title'); ?>
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="button" class="btn-help" onclick="toggleModuleHelp('tcHelpBox')" title="Modül Rehberi">
                    <i class="fas fa-question-circle"></i>
                </button>
                <?php if (hasModulePermission('time_conditions', 'edit')): ?>
                    <button class="btn btn-primary btn-sm" onclick="openCreateTgModal()" title="Yeni Ekle">
                        <i class="fas fa-plus-circle"></i>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="col-hide-mobile" style="width: 50px;">#</th>
                        <th><?php echo t('tc.col_group'); ?></th>
                        <th><?php echo t('tc.col_hours'); ?></th>
                        <th class="col-hide-mobile"><?php echo t('tc.col_days'); ?></th>
                        <th class="col-hide-mobile"><?php echo t('tc.col_holidays'); ?></th>
                        <th><?php echo t('tc.col_status'); ?></th>
                        <th class="text-right"><?php echo t('tc.col_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($time_groups)): ?>
                        <?php echo uiTableEmptyRow(7, t('tc.groups_empty'), 'fa-calendar-alt'); ?>
                    <?php else: ?>
                        <?php foreach ($time_groups as $tg):
                            $d_arr = explode(',', $tg['days_of_week'] ?? '');
                            $d_labels = [];
                            foreach ($d_arr as $d) {
                                if (isset($day_names[$d])) $d_labels[] = $day_names[$d];
                            }
                            $h_arr = !empty($tg['holidays_json']) ? json_decode($tg['holidays_json'], true) : [];
                        ?>
                            <tr>
                                <td class="col-hide-mobile text-muted" style="font-size: 12px;">#<?php echo $tg['id']; ?></td>
                                <td style="font-weight: 700; color: var(--text-main);"><?php echo htmlspecialchars($tg['title']); ?></td>
                                <td>
                                    <span class="badge badge-info">
                                        <i class="fas fa-clock"></i> <?php echo htmlspecialchars(substr($tg['time_start'], 0, 5)); ?> - <?php echo htmlspecialchars(substr($tg['time_end'], 0, 5)); ?>
                                    </span>
                                </td>
                                <td class="col-hide-mobile">
                                    <span class="badge badge-secondary"><?php echo implode(', ', $d_labels); ?></span>
                                </td>
                                <td class="col-hide-mobile">
                                    <?php if (!empty($h_arr)): ?>
                                        <small style="color: var(--text-muted);"><?php echo sprintf(t('tc.holidays_count'), count($h_arr)); ?></small>
                                    <?php else: ?>
                                        <small style="color: var(--text-muted);">-</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo uiStatusToggleForm($tg['id'], $tg['is_active'] ?? 1, 'tg_id', 'toggle_tg_status'); ?>
                                </td>
                                <td class="text-right">
                                    <?php echo uiRowActions($tg, 'openEditTgModal', 'tg_id', 'delete_time_group', sprintf(t('tc.confirm_delete_group'), $tg['title'])); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal 1: Create / Edit Time Condition -->
<div class="modal-overlay" id="tcModal">
    <div class="modal-card" style="max-width: 680px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;" id="tcModalTitle"><i class="fas fa-plus-circle" style="color: var(--primary);"></i> <?php echo t('tc.new_condition'); ?></h3>
            <button class="btn btn-secondary" onclick="closeTcModal()" style="padding: 6px 12px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_time_condition" value="1">
                <input type="hidden" name="tc_id" id="modal_tc_id" value="0">

                <div class="form-group">
                    <label class="form-label"><?php echo t('tc.field_title'); ?></label>
                    <input type="text" name="title" id="modal_title" class="form-control" required placeholder="ör: Ana Hat Zaman Koşulu">
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('internal_number.field'); ?></label>
                    <input type="text" name="internal_number" id="modal_internal_number"
                           class="form-control" inputmode="numeric" pattern="[0-9]{2,6}" maxlength="6"
                           placeholder="ör: 1010">
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('internal_number.help'); ?></small>
                </div>

                <div class="form-group" style="margin-top: 10px; margin-bottom: 15px;">
                    <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" id="modal_is_active" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
                        <span style="font-weight: 600;"><?php echo t('tc.field_active'); ?></span>
                    </label>
                </div>

                <!-- Dynamic Rules Container -->
                <div style="margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <label class="form-label" style="margin-bottom: 0;"><i class="fas fa-list-ol"></i> <?php echo t('tc.rules_label'); ?></label>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="addTcRuleRow()" title="Kural Ekle">
                            <i class="fas fa-plus-circle"></i>
                        </button>
                    </div>

                    <div id="tc_rules_container" style="display: flex; flex-direction: column; gap: 8px;">
                        <!-- Dynamic Rule Rows Injected via JS -->
                    </div>
                </div>

                <!-- Fallback General NoMatch Destination -->
                <div style="background: rgba(239, 68, 68, 0.05); padding: 14px; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.2); margin-top: 16px;">
                    <div style="font-weight: 700; color: var(--danger); font-size: 13px; margin-bottom: 8px;">
                        <i class="fas fa-moon"></i> <?php echo t('tc.default_target_box'); ?>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label"><?php echo t('tc.field_dest_type'); ?></label>
                            <select name="nomatch_dest_type" id="modal_nomatch_dest_type" class="form-control">
                                <?php foreach ($modules as $m): ?>
                                    <option value="<?php echo htmlspecialchars($m['key']); ?>"><?php echo htmlspecialchars($m['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label"><?php echo t('tc.field_dest_id'); ?></label>
                            <select name="nomatch_dest_id" id="modal_nomatch_dest_id" class="form-control">
                                <option value=""><?php echo t('tc.loading'); ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <?php echo uiModalFooter('closeTcModal()'); ?>
            </form>
        </div>
    </div>
</div>

<!-- Modal 2: Create / Edit Time Group -->
<div class="modal-overlay" id="tgModal">
    <div class="modal-card" style="max-width: 540px;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;" id="tgModalTitle"><i class="fas fa-plus-circle" style="color: var(--primary);"></i> <?php echo t('tc.new_group'); ?></h3>
            <button class="btn btn-secondary" onclick="closeTgModal()" style="padding: 6px 12px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_time_group" value="1">
                <input type="hidden" name="tg_id" id="modal_tg_id" value="0">

                <div class="form-group">
                    <label class="form-label"><?php echo t('tc.field_group_title'); ?></label>
                    <input type="text" name="title" id="modal_tg_title" class="form-control" required placeholder="ör: Standart Hafta İçi Mesaisi">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('tc.field_time_start'); ?></label>
                        <input type="time" name="time_start" id="modal_tg_time_start" class="form-control" required value="08:30">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('tc.field_time_end'); ?></label>
                        <input type="time" name="time_end" id="modal_tg_time_end" class="form-control" required value="17:30">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('tc.field_days'); ?></label>
                    <div class="tc-days-grid">
                        <label class="tc-day-checkbox"><input type="checkbox" name="days[]" value="1" id="day_chk_1" checked> <?php echo t('tc.day_mon'); ?></label>
                        <label class="tc-day-checkbox"><input type="checkbox" name="days[]" value="2" id="day_chk_2" checked> <?php echo t('tc.day_tue'); ?></label>
                        <label class="tc-day-checkbox"><input type="checkbox" name="days[]" value="3" id="day_chk_3" checked> <?php echo t('tc.day_wed'); ?></label>
                        <label class="tc-day-checkbox"><input type="checkbox" name="days[]" value="4" id="day_chk_4" checked> <?php echo t('tc.day_thu'); ?></label>
                        <label class="tc-day-checkbox"><input type="checkbox" name="days[]" value="5" id="day_chk_5" checked> <?php echo t('tc.day_fri'); ?></label>
                        <label class="tc-day-checkbox"><input type="checkbox" name="days[]" value="6" id="day_chk_6"> <?php echo t('tc.day_sat'); ?></label>
                        <label class="tc-day-checkbox"><input type="checkbox" name="days[]" value="7" id="day_chk_7"> <?php echo t('tc.day_sun'); ?></label>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('tc.field_holidays'); ?></label>
                    <input type="text" name="holidays" id="modal_tg_holidays" class="form-control" placeholder="ör: 2026-01-01, 2026-04-23, 2026-05-19, 2026-07-15, 2026-08-30, 2026-10-29">
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('tc.holidays_help'); ?></small>
                </div>

                <div class="form-group" style="margin-top: 10px;">
                    <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" id="modal_tg_is_active" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
                        <span style="font-weight: 600;"><?php echo t('tc.field_group_active'); ?></span>
                    </label>
                </div>

                <?php echo uiModalFooter('closeTgModal()'); ?>
            </form>
        </div>
    </div>
</div>

<!-- Pass Time Groups and Destination Modules to JS -->
<script>
window.TIME_GROUPS_LIST = <?php echo json_encode($time_groups, JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.DEST_MODULES_LIST = <?php echo json_encode($modules, JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>

<script src="/assets/js/destinations_helper.js?v=<?php echo time(); ?>"></script>
<script src="/assets/js/time_conditions.js?v=<?php echo time(); ?>"></script>
