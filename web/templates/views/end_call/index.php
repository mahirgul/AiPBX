<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-phone-slash u-danger"></i> <?php echo t('end_call.header_title'); ?>
        </div>
        <div class="u-flex-gap">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('endCallHelpBox')" title="<?php echo t('common.module_guide'); ?>">
                <i class="fas fa-question-circle"></i>
            </button>
            <?php if (hasModulePermission('end_call', 'edit')): ?>
                <button class="btn btn-primary btn-sm" onclick="openCreateHangupModal()" title="<?php echo t('end_call.new_tooltip'); ?>">
                    <i class="fas fa-plus-circle"></i>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="endCallHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('end_call.help_title'); ?></h4>
        <?php echo t('end_call.help_body'); ?><br>
        <?php echo t('end_call.help_body2'); ?>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="col-hide-mobile u-w-50">#</th>
                    <th class="col-hide-mobile"><?php echo t('end_call.col_key'); ?></th>
                    <th><?php echo t('end_call.col_title'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('internal_number.col'); ?></th>
                    <th><?php echo t('end_call.col_action'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('end_call.col_announcement'); ?></th>
                    <th class="text-right"><?php echo t('end_call.col_actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($actions)): ?>
                    <?php echo uiTableEmptyRow(6, t('end_call.empty'), 'fa-phone-slash'); ?>
                <?php else: ?>
                    <?php foreach ($actions as $act): ?>
                        <tr>
                            <td class="col-hide-mobile text-muted u-fs-12">#<?php echo $act['id']; ?></td>
                            <td class="col-hide-mobile"><code><?php echo htmlspecialchars($act['action_key']); ?></code></td>
                            <td class="u-strong"><?php echo htmlspecialchars($act['title']); ?></td>
                            <td class="col-hide-mobile">
                                <?php echo !empty($act['internal_number'])
                                    ? '<span class="badge badge-info">' . htmlspecialchars($act['internal_number']) . '</span>'
                                    : t('internal_number.none'); ?>
                            </td>
                            <td>
                                <?php if ($act['action_type'] === 'busy'): ?>
                                    <span class="badge badge-warning"><i class="fas fa-user-slash"></i> <?php echo t('end_call.action_busy'); ?></span>
                                <?php elseif ($act['action_type'] === 'congestion'): ?>
                                    <span class="badge badge-secondary"><i class="fas fa-exclamation-triangle"></i> <?php echo t('end_call.action_congestion'); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><i class="fas fa-phone-slash"></i> <?php echo t('end_call.action_hangup'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="col-hide-mobile">
                                <?php if (!empty($act['anc_title'])): ?>
                                    <span class="badge badge-info"><i class="fas fa-volume-up"></i> <?php echo htmlspecialchars($act['anc_title']); ?></span>
                                <?php else: ?>
                                    <span class="u-muted"><?php echo t('end_call.no_announcement'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <?php echo uiRowActions($act, 'openEditHangupModal', 'hangup_id', 'delete_hangup_action', sprintf(t('end_call.delete_confirm'), $act['title']), t('end_call.delete'), 'fa-trash-alt', t('end_call.edit'), 'fa-edit', [], !in_array($act['action_key'], ['hangup', 'busy', 'congestion'])); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create / Edit Hangup Action Modal -->
<div class="modal-overlay" id="hangupModal">
    <div class="modal-card u-maxw-520">
        <div class="modal-header">
            <h3 class="u-title" id="hangupModalTitle"><i class="fas fa-plus-circle u-primary"></i> <?php echo t('end_call.modal_new_title'); ?></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="closeHangupModal()" title="<?php echo t('end_call.close_tooltip'); ?>"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_hangup_action" value="1">
                <input type="hidden" name="hangup_id" id="modal_hangup_id" value="">

                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('end_call.field_action_key'); ?></label>
                        <input type="text" name="action_key" id="modal_action_key" class="form-control" placeholder="<?php echo t('end_call.field_action_key_placeholder'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('end_call.field_title'); ?></label>
                        <input type="text" name="title" id="modal_title" class="form-control" placeholder="<?php echo t('end_call.field_title_placeholder'); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('internal_number.field'); ?></label>
                    <input type="text" name="internal_number" id="modal_internal_number"
                           class="form-control" inputmode="numeric" pattern="[0-9]{2,6}" maxlength="6"
                           placeholder="<?php echo t('common.eg'); ?> 1010">
                    <small class="u-hint"><?php echo t('internal_number.help'); ?></small>
                </div>

                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('end_call.field_action_type'); ?></label>
                        <select name="action_type" id="modal_action_type" class="form-control">
                            <option value="hangup"><?php echo t('end_call.option_hangup'); ?></option>
                            <option value="busy"><?php echo t('end_call.option_busy'); ?></option>
                            <option value="congestion"><?php echo t('end_call.option_congestion'); ?></option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('end_call.field_announcement'); ?></label>
                        <select name="announcement_id" id="modal_announcement_id" class="form-control">
                            <option value=""><?php echo t('end_call.no_announcement_option'); ?></option>
                            <?php foreach ($announcements as $anc): ?>
                                <option value="<?php echo $anc['id']; ?>"><?php echo htmlspecialchars($anc['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php echo uiModalFooter('closeHangupModal()'); ?>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo asset('/assets/js/end_call.js'); ?>"></script>
