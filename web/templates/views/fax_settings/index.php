<!-- Department & Email Routing Table Card -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-fax u-primary"></i> <?php echo t('fax_settings.title'); ?></div>
        <div class="u-flex-gap">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('faxSettingsHelpBox')" title="<?php echo t('common.module_guide'); ?>">
                <i class="fas fa-question-circle"></i>
            </button>
            <?php if (hasModulePermission('fax_settings', 'edit')): ?>
                <button class="btn btn-primary btn-sm" onclick="openCreateFaxDidModal()" title="<?php echo t('fax_settings.new_tooltip'); ?>">
                    <i class="fas fa-plus-circle"></i>
                </button>
            <?php endif; ?>
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
                    <th class="col-hide-mobile u-w-50">#</th>
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
                            <td class="col-hide-mobile text-muted u-fs-12">#<?php echo $m['id']; ?></td>
                            <td class="u-strong"><i class="fas fa-building u-primary u-mr-6"></i> <?php echo htmlspecialchars($m['department_name']); ?></td>
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
            <h3 class="u-title" id="didModalTitle"><i class="fas fa-fax u-primary"></i> <?php echo t('fax_settings.modal_new_title'); ?></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="closeFaxDidModal()"><i class="fas fa-times"></i></button>
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
                    <small class="u-hint"><?php echo t('fax_settings.did_help'); ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('fax_settings.field_fax_user'); ?></label>
                    <select name="assigned_user_id" id="modal_assigned_user_id" class="form-control">
                        <option value="0"><?php echo t('fax_settings.not_selected'); ?></option>
                        <?php foreach ($fax_users as $fu): ?>
                            <option value="<?php echo $fu['id']; ?>"><?php echo htmlspecialchars($fu['extension'] . ' - ' . $fu['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="u-hint"><?php echo t('fax_settings.fax_user_help'); ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('fax_settings.field_email'); ?></label>
                    <input type="email" name="notification_email" id="modal_notification_email" class="form-control" placeholder="fax@example.com">
                </div>

                <div class="form-group u-mt-10">
                    <label class="form-label u-check-label">
                        <input type="checkbox" name="is_active" id="modal_is_active" value="1" checked class="u-check">
                        <span class="u-fw-600"><?php echo t('fax_settings.field_active'); ?></span>
                    </label>
                </div>

                <?php echo uiModalFooter('closeFaxDidModal()'); ?>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo asset('/assets/js/fax_settings.js'); ?>"></script>

<div class="card" id="fax-cover">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-file-alt u-primary"></i> <?php echo t('fax_cover.title'); ?></div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('faxCoverHelpBox')"><i class="fas fa-question-circle"></i></button>
    </div>
    <div class="module-help-box" id="faxCoverHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('fax_cover.title'); ?></h4>
        <div style="margin-top: 10px;"><strong><?php echo t('common.help_limits'); ?></strong><ul style="margin: 4px 0 0 20px;"><li><?php echo t('fax_cover.limit_colour'); ?></li></ul></div>
    </div>
    <form method="POST" autocomplete="off" style="padding: 0 20px 20px;">
        <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
        <input type="hidden" name="save_fax_cover" value="1">
        <div class="form-group">
            <label class="form-label"><?php echo t('fax_cover.field_mode'); ?></label>
            <select name="fax_cover_mode" class="form-control">
                <?php foreach (['off', 'optional', 'required'] as $mode): ?>
                    <option value="<?php echo $mode; ?>" <?php echo $cover['mode'] === $mode ? 'selected' : ''; ?>><?php echo t('fax_cover.mode_' . $mode); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label"><?php echo t('fax_cover.field_company'); ?></label>
            <input type="text" name="fax_cover_company" class="form-control" maxlength="120" value="<?php echo htmlspecialchars($cover['company'], ENT_QUOTES); ?>">
        </div>
        <div class="form-group">
            <label class="form-label"><?php echo t('fax_cover.field_notice'); ?></label>
            <textarea name="fax_cover_notice" class="form-control" rows="3" maxlength="1000"><?php echo htmlspecialchars($cover['notice'], ENT_QUOTES); ?></textarea>
        </div>
        <label style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px; font-size: 13px;">
            <input type="checkbox" name="fax_cover_logo" value="1" <?php echo $cover['logo'] ? 'checked' : ''; ?>> <?php echo t('fax_cover.field_logo'); ?>
        </label>
        <small class="u-hint u-fs-11" style="display: block; margin-bottom: 12px;"><?php echo t($coverLogoFound ? 'fax_cover.logo_help' : 'fax_cover.logo_missing'); ?></small>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
        <button type="submit" class="btn btn-secondary" name="cover_preview" value="1" formaction="/fax-send" formtarget="_blank" data-no-spa="true"><i class="fas fa-eye"></i> <?php echo t('fax_cover.preview'); ?></button>
    </form>
</div>
