<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-layer-group" style="color: var(--primary);"></i> <?php echo t('queues.title'); ?>
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('queueHelpBox')" title="Modül Rehberi">
                <i class="fas fa-question-circle"></i>
            </button>
            <?php if (hasModulePermission('queues', 'edit')): ?>
                <button class="btn btn-primary btn-sm" onclick="openCreateQueueModal()" title="<?php echo t('queues.new_tooltip'); ?>">
                    <i class="fas fa-plus-circle"></i>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="queueHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('queues.help_title'); ?></h4>
        <?php echo t('queues.help_body'); ?><br>
        - <strong><?php echo t('queues.help_advanced'); ?></strong>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="col-hide-mobile" style="width: 50px;">#</th>
                    <th><?php echo t('queues.col_queue'); ?></th>
                    <th><?php echo t('queues.col_desc'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('internal_number.col'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('queues.col_strategy'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('queues.col_supervisor'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('queues.col_timings'); ?></th>
                    <th><?php echo t('queues.col_agent'); ?></th>
                    <th><?php echo t('queues.col_status'); ?></th>
                    <th class="text-right"><?php echo t('queues.col_actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($queues)): ?>
                    <?php echo uiTableEmptyRow(9, t('queues.empty'), 'fa-layer-group'); ?>
                <?php else: ?>
                    <?php foreach ($queues as $q):
                        $members = !empty($q['members_json']) ? json_decode($q['members_json'], true) : [];
                    ?>
                        <tr>
                            <td class="col-hide-mobile text-muted" style="font-size: 12px;">#<?php echo $q['id']; ?></td>
                            <td><span class="badge badge-info"><i class="fas fa-headset"></i> <?php echo htmlspecialchars($q['queue_name']); ?></span></td>
                            <td style="font-weight: 700; color: var(--text-main);"><?php echo htmlspecialchars($q['title']); ?></td>
                            <td class="col-hide-mobile">
                                <?php echo !empty($q['internal_number'])
                                    ? '<span class="badge badge-info">' . htmlspecialchars($q['internal_number']) . '</span>'
                                    : t('internal_number.none'); ?>
                            </td>
                            <td><span class="badge badge-success"><?php echo htmlspecialchars($q['strategy']); ?></span></td>
                            <td class="col-hide-mobile">
                                <?php
                                $sups = !empty($q['supervisors_json']) ? (json_decode($q['supervisors_json'], true) ?: []) : (!empty($q['supervisor_extension']) ? [$q['supervisor_extension']] : []);
                                if (!empty($sups)):
                                    foreach ($sups as $s_ext):
                                        $s_name = QueueRepository::supervisorName($s_ext);
                                ?>
                                        <span class="badge badge-warning" style="margin-right: 2px; margin-bottom: 2px;" title="<?php echo t('queues.supervisor_badge_tooltip'); ?>"><i class="fas fa-user-shield"></i> <?php echo htmlspecialchars($s_name); ?> (<?php echo htmlspecialchars($s_ext); ?>)</span>
                                <?php
                                    endforeach;
                                else:
                                ?>
                                    <span style="color: var(--text-muted); font-size: 12px;"><?php echo t('queues.not_assigned'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="col-hide-mobile">
                                <?php echo t('queues.timing_ring'); ?>: <strong><?php echo $q['timeout']; ?>s</strong> |
                                <?php echo t('queues.timing_retry'); ?>: <strong><?php echo $q['retry']; ?>s</strong> |
                                <?php echo t('queues.timing_wrapup'); ?>: <strong><?php echo $q['wrapuptime']; ?>s</strong>
                            </td>
                            <td>
                                <span class="badge badge-warning"><?php echo is_array($members) ? count($members) : 0; ?> <?php echo t('queues.agent_count_suffix'); ?></span>
                            </td>
                            <td>
                                <?php echo uiStatusToggleForm($q['id'], $q['is_active'], 'queue_id'); ?>
                            </td>
                            <td class="text-right">
                                <?php echo uiRowActions($q, 'openEditQueueModal', 'queue_id', 'delete_queue', sprintf(t('queues.delete_confirm'), $q['title'], $q['queue_name'])); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create / Edit Queue Modal -->
<div class="modal-overlay" id="queueModal">
    <div class="modal-card" style="max-width: 720px;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;" id="queueModalTitle"><i class="fas fa-layer-group" style="color: var(--primary);"></i> <?php echo t('queues.modal_new_title'); ?></h3>
            <button class="btn btn-secondary" onclick="closeQueueModal()" style="padding: 6px 12px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_queue" value="1">
                <input type="hidden" name="queue_id" id="modal_queue_id" value="">

                <div class="form-group" style="margin-bottom: 10px;">
                    <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" id="modal_is_active" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
                        <span style="font-weight: 600;"><?php echo t('queues.field_active'); ?></span>
                    </label>
                </div>

                <!-- 1. Temel Tanımlamalar -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('queues.field_queue_name'); ?></label>
                        <input type="text" name="queue_name" id="modal_queue_name" class="form-control" placeholder="<?php echo t('queues.field_queue_name_placeholder'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('queues.field_queue_title'); ?></label>
                        <input type="text" name="title" id="modal_queue_title" class="form-control" placeholder="<?php echo t('queues.field_queue_title_placeholder'); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('internal_number.field'); ?></label>
                    <input type="text" name="internal_number" id="modal_internal_number"
                           class="form-control" inputmode="numeric" pattern="[0-9]{2,6}" maxlength="6"
                           placeholder="ör: 1010">
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('internal_number.help'); ?></small>
                </div>

                <!-- 2. Strateji ve Zamanlar -->
                <div class="form-group">
                    <label class="form-label form-label-help">
                        <span><?php echo t('queues.field_strategy'); ?></span>
                        <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('queues.strategy_help'); ?></span></span>
                    </label>
                    <select name="strategy" id="modal_strategy" class="form-control">
                        <option value="rrmemory"><?php echo t('queues.strategy_rrmemory'); ?></option>
                        <option value="leastrecent"><?php echo t('queues.strategy_leastrecent'); ?></option>
                        <option value="fewestcalls"><?php echo t('queues.strategy_fewestcalls'); ?></option>
                        <option value="random"><?php echo t('queues.strategy_random'); ?></option>
                        <option value="ringall"><?php echo t('queues.strategy_ringall'); ?></option>
                        <option value="linear"><?php echo t('queues.strategy_linear'); ?></option>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('queues.field_timeout'); ?></label>
                        <input type="number" name="timeout" id="modal_timeout" class="form-control" value="15" min="5" max="60" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('queues.field_wrapuptime'); ?></label>
                        <input type="number" name="wrapuptime" id="modal_wrapuptime" class="form-control" value="10" min="0" max="60" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('queues.field_retry'); ?></label>
                        <input type="number" name="retry" id="modal_retry" class="form-control" value="5" min="1" max="30" required>
                    </div>
                </div>

                <!-- 3. Anons ve MOH Ayarları -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('queues.field_musicclass'); ?></label>
                        <select name="musicclass" id="modal_musicclass" class="form-control">
                            <?php foreach ($moh_classes as $mc_name): ?>
                                <option value="<?php echo htmlspecialchars($mc_name); ?>"><?php echo htmlspecialchars($mc_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('queues.field_maxlen'); ?></label>
                        <input type="number" name="maxlen" id="modal_maxlen" class="form-control" value="0" min="0" max="500" placeholder="<?php echo t('queues.maxlen_placeholder'); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="fas fa-language"></i> <?php echo t('queues.field_language'); ?></label>
                    <select name="language" id="modal_language" class="form-control">
                        <option value=""><?php echo t('queues.language_default'); ?></option>
                        <?php foreach (getAvailableLanguages() as $lang_code): ?>
                            <option value="<?php echo htmlspecialchars($lang_code); ?>"><?php echo htmlspecialchars(LANGUAGE_LABELS[$lang_code] ?? $lang_code); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('queues.language_help'); ?></small>
                </div>

                <!-- 4. Gelişmiş Asterisk Kuyruk Davranışları -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label form-label-help">
                            <span><?php echo t('queues.field_announce_frequency'); ?></span>
                            <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('queues.announce_frequency_help'); ?></span></span>
                        </label>
                        <input type="number" name="announce_frequency" id="modal_announce_frequency" class="form-control" value="30" min="0" max="300" placeholder="<?php echo t('queues.announce_frequency_placeholder'); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label form-label-help">
                            <span><?php echo t('queues.field_announce_holdtime'); ?></span>
                            <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('queues.announce_holdtime_help'); ?></span></span>
                        </label>
                        <select name="announce_holdtime" id="modal_announce_holdtime" class="form-control">
                            <option value="yes"><?php echo t('queues.announce_holdtime_yes'); ?></option>
                            <option value="no"><?php echo t('queues.announce_holdtime_no'); ?></option>
                            <option value="once"><?php echo t('queues.announce_holdtime_once'); ?></option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label form-label-help">
                            <span><?php echo t('queues.field_ringinuse'); ?></span>
                            <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('queues.ringinuse_help'); ?></span></span>
                        </label>
                        <select name="ringinuse" id="modal_ringinuse" class="form-control">
                            <option value="no"><?php echo t('queues.ringinuse_no'); ?></option>
                            <option value="yes"><?php echo t('queues.ringinuse_yes'); ?></option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label form-label-help">
                            <span><?php echo t('queues.field_joinempty'); ?></span>
                            <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('queues.joinempty_help'); ?></span></span>
                        </label>
                        <select name="joinempty" id="modal_joinempty" class="form-control">
                            <option value="yes"><?php echo t('queues.joinempty_yes'); ?></option>
                            <option value="no"><?php echo t('queues.joinempty_no'); ?></option>
                            <option value="strict"><?php echo t('queues.joinempty_strict'); ?></option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label form-label-help">
                        <span><?php echo t('queues.field_leavewhenempty'); ?></span>
                        <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('queues.leavewhenempty_help'); ?></span></span>
                    </label>
                    <select name="leavewhenempty" id="modal_leavewhenempty" class="form-control">
                        <option value="no"><?php echo t('queues.leavewhenempty_no'); ?></option>
                        <option value="yes"><?php echo t('queues.leavewhenempty_yes'); ?></option>
                        <option value="strict"><?php echo t('queues.leavewhenempty_strict'); ?></option>
                    </select>
                </div>

                <!-- 5. Zaman Aşımı, Yönlendirme & Ses Kaydı -->
                <div class="form-group" style="margin-top: 4px;">
                    <label class="form-label" style="font-weight: 700; color: var(--text-main);"><i class="fas fa-hourglass-half" style="color: var(--warning);"></i> <?php echo t('queues.section_timeout'); ?></label>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('queues.field_max_wait_seconds'); ?></label>
                        <input type="number" name="max_wait_seconds" id="modal_max_wait_seconds" class="form-control" value="300" min="30" max="1800" required>
                        <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('queues.max_wait_help'); ?></small>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('queues.field_fallback_action'); ?></label>
                        <select name="fallback_action" id="modal_fallback_action" class="form-control" onchange="toggleQueueFallbackTarget()">
                            <option value="hangup"><?php echo t('queues.fallback_hangup'); ?></option>
                            <option value="closed_msg"><?php echo t('queues.fallback_closed_msg'); ?></option>
                            <option value="forward"><?php echo t('queues.fallback_forward'); ?></option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group" id="modal_fallback_target_group" style="display:none;">
                        <label class="form-label"><?php echo t('queues.field_fallback_target'); ?></label>
                        <select name="fallback_target" id="modal_fallback_target" class="form-control">
                            <option value=""><?php echo t('queues.select_placeholder'); ?></option>
                            <?php foreach ($all_agents as $ag): ?>
                                <option value="<?php echo htmlspecialchars($ag['extension']); ?>"><?php echo htmlspecialchars($ag['extension'] . ' - ' . $ag['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('queues.field_record_enabled'); ?></label>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin-top: 8px;">
                            <input type="checkbox" name="record_enabled" id="modal_record_enabled" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
                            <span style="font-weight: 600; font-size: 13px;"><?php echo t('queues.record_enabled_label'); ?></span>
                        </label>
                    </div>
                </div>
                <input type="hidden" name="record_format" value="wav">

                <!-- 6. Temsilciler ve Yöneticiler -->
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-users-cog"></i> <?php echo t('queues.section_members'); ?></label>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; max-height: 180px; overflow-y: auto; background: var(--bg-sidebar); padding: 12px; border-radius: 8px; border: 1px solid var(--border-color);">
                        <?php foreach ($all_agents as $agent):
                            $is_admin = ($agent['role'] === 'admin');
                            $role_badge = $is_admin ? '<span class="badge badge-warning" style="font-size: 10px; padding: 2px 6px;">' . t('queues.role_admin') . '</span>' : '<span class="badge badge-info" style="font-size: 10px; padding: 2px 6px;">' . t('queues.role_agent') . '</span>';
                        ?>
                            <label style="display: flex; align-items: center; justify-content: space-between; font-size: 12px; cursor: pointer; padding: 6px 10px; background: var(--bg-input); border-radius: 6px; border: 1px solid var(--border-color);">
                                <span style="display: flex; align-items: center; gap: 8px;">
                                    <input type="checkbox" name="members[]" class="modal-agent-checkbox" value="<?php echo htmlspecialchars($agent['extension']); ?>">
                                    <strong><?php echo htmlspecialchars($agent['extension']); ?></strong> - <?php echo htmlspecialchars($agent['full_name']); ?>
                                </span>
                                <?php echo $role_badge; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 7. Kuyruk Yöneticileri (Supervisors) - Çoklu Seçim (Tüm Kullanıcılar) -->
                <div class="form-group" style="background: rgba(245, 158, 11, 0.08); padding: 12px; border-radius: 8px; border: 1px solid rgba(245, 158, 11, 0.3); margin-top: 14px;">
                    <label class="form-label" style="font-weight: 700; color: var(--warning); display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
                        <i class="fas fa-user-shield"></i> <?php echo t('queues.section_supervisors'); ?>
                    </label>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; max-height: 140px; overflow-y: auto; background: var(--bg-sidebar); padding: 10px; border-radius: 6px; border: 1px solid var(--border-color);">
                        <?php foreach ($all_agents as $agent_user):
                            $is_adm = ($agent_user['role'] === 'admin' || $agent_user['role'] === 'cc_manager');
                            $u_badge = $is_adm ? '<span class="badge badge-warning" style="font-size: 9px; padding: 2px 5px;">' . t('queues.role_manager') . '</span>' : '<span class="badge badge-info" style="font-size: 9px; padding: 2px 5px;">' . t('queues.role_user') . '</span>';
                        ?>
                            <label style="display: flex; align-items: center; justify-content: space-between; font-size: 12px; cursor: pointer; padding: 5px 8px; background: var(--bg-input); border-radius: 6px; border: 1px solid var(--border-color);">
                                <span style="display: flex; align-items: center; gap: 8px;">
                                    <input type="checkbox" name="supervisors[]" class="modal-supervisor-checkbox" value="<?php echo htmlspecialchars($agent_user['extension']); ?>">
                                    <strong><?php echo htmlspecialchars($agent_user['extension']); ?></strong> - <?php echo htmlspecialchars($agent_user['full_name']); ?>
                                </span>
                                <?php echo $u_badge; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <small style="color: var(--text-muted); font-size: 11px; margin-top: 6px; display: block;">
                        <?php echo t('queues.supervisors_help'); ?>
                    </small>
                </div>

                <?php echo uiModalFooter('closeQueueModal()'); ?>
            </form>
        </div>
    </div>
</div>

<script src="/assets/js/queues.js?v=<?php echo time(); ?>"></script>
