<!-- Department & Email Routing Table Card -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-fax" style="color: var(--primary);"></i> <?php echo t('fax_settings.title'); ?></div>
        <div style="display: flex; gap: 8px;">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('faxSettingsHelpBox')" title="Modül Rehberi">
                <i class="fas fa-question-circle"></i>
            </button>
            <button class="btn btn-primary btn-sm" onclick="openCreateFaxDidModal()" title="<?php echo t('fax_settings.new_tooltip'); ?>">
                <i class="fas fa-plus-circle"></i>
            </button>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="faxSettingsHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('fax_settings.help_title'); ?></h4>
        <?php echo t('fax_settings.help_body'); ?><br>
        <?php echo t('fax_settings.help_body2'); ?>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="col-hide-mobile" style="width: 50px;">#</th>
                    <th><?php echo t('fax_settings.col_dept'); ?></th>
                    <th><?php echo t('fax_settings.col_did'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('fax_settings.col_fax_user'); ?></th>
                    <th><?php echo t('fax_settings.col_email'); ?></th>
                    <th><?php echo t('fax_settings.col_status'); ?></th>
                    <th class="text-right"><?php echo t('fax_settings.col_actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($mappings)): ?>
                    <?php echo uiTableEmptyRow(7, t('fax_settings.empty'), 'fa-building'); ?>
                <?php else: ?>
                    <?php foreach ($mappings as $m): ?>
                        <tr>
                            <td class="col-hide-mobile text-muted" style="font-size: 12px;">#<?php echo $m['id']; ?></td>
                            <td style="font-weight: 700; color: var(--text-main);"><i class="fas fa-building" style="color: var(--primary); margin-right: 6px;"></i> <?php echo htmlspecialchars($m['department_name']); ?></td>
                            <td><?php echo !empty($m['did_extension']) ? '<span class="badge badge-info">' . htmlspecialchars($m['did_extension']) . '</span>' : '<span class="text-muted">-</span>'; ?></td>
                            <td class="col-hide-mobile"><?php echo !empty($m['assigned_user_id']) ? '<span class="badge badge-warning"><i class="fas fa-fax"></i> ' . htmlspecialchars($m['fax_user_extension'] . ' - ' . $m['fax_user_name']) . '</span>' : '<span class="text-muted">-</span>'; ?></td>
                            <td><?php echo htmlspecialchars($m['notification_email'] ?: '-'); ?></td>
                            <td>
                                <?php echo uiStatusToggleForm($m['id'], $m['is_active'] ?? 1, 'did_id'); ?>
                            </td>
                            <td class="text-right">
                                <?php echo uiRowActions($m, 'openEditFaxDidModal', 'did_id', 'delete_did', sprintf(t('fax_settings.delete_confirm'), $m['department_name'])); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Department Create / Edit Modal -->
<div class="modal-overlay" id="didModal">
    <div class="modal-card" style="max-width: 500px;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;" id="didModalTitle"><i class="fas fa-fax" style="color: var(--primary);"></i> <?php echo t('fax_settings.modal_new_title'); ?></h3>
            <button class="btn btn-secondary" onclick="closeFaxDidModal()" style="padding: 6px 12px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_did" value="1">
                <input type="hidden" name="did_id" id="modal_did_id" value="0">

                <div class="form-group">
                    <label class="form-label"><?php echo t('fax_settings.field_dept'); ?></label>
                    <input type="text" name="department_name" id="modal_department_name" class="form-control" required placeholder="<?php echo t('fax_settings.field_dept_placeholder'); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('fax_settings.field_did'); ?></label>
                    <input type="text" name="did_extension" id="modal_did_extension" class="form-control" placeholder="<?php echo t('fax_settings.field_did_placeholder'); ?>">
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('fax_settings.did_help'); ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('fax_settings.field_fax_user'); ?></label>
                    <select name="assigned_user_id" id="modal_assigned_user_id" class="form-control">
                        <option value="0"><?php echo t('fax_settings.not_selected'); ?></option>
                        <?php foreach ($fax_users as $fu): ?>
                            <option value="<?php echo $fu['id']; ?>"><?php echo htmlspecialchars($fu['extension'] . ' - ' . $fu['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('fax_settings.fax_user_help'); ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('fax_settings.field_email'); ?></label>
                    <input type="email" name="notification_email" id="modal_notification_email" class="form-control" placeholder="fax@example.com">
                </div>

                <div class="form-group" style="margin-top: 10px;">
                    <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" id="modal_is_active" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
                        <span style="font-weight: 600;"><?php echo t('fax_settings.field_active'); ?></span>
                    </label>
                </div>

                <?php echo uiModalFooter('closeFaxDidModal()'); ?>
            </form>
        </div>
    </div>
</div>

<script src="/assets/js/fax_settings.js?v=<?php echo time(); ?>"></script>
