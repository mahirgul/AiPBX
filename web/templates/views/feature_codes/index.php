<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-hashtag" style="color: var(--primary);"></i> <?php echo t('fc.header_title'); ?>
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('featureCodesHelpBox')" title="Modül Rehberi">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>

    <div class="module-help-box" id="featureCodesHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('fc.help_title'); ?></h4>
        <?php echo t('fc.help_body'); ?><br>
        <?php echo t('fc.help_body2'); ?><br>
        <?php echo t('fc.help_body3'); ?>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?php echo t('fc.col_title'); ?></th>
                    <th><?php echo t('fc.col_code'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('fc.col_allowed_roles'); ?></th>
                    <th><?php echo t('fc.col_status'); ?></th>
                    <th class="text-right"><?php echo t('fc.col_actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($codes)): ?>
                    <?php echo uiTableEmptyRow(5, t('fc.empty'), 'fa-hashtag'); ?>
                <?php else: ?>
                    <?php foreach ($codes as $fc): ?>
                        <tr>
                            <td style="font-weight: 700; color: var(--text-main);"><?php echo htmlspecialchars($fc['title']); ?></td>
                            <td><code style="font-size: 14px; font-weight: 700; color: var(--primary);"><?php
                                $clean_c = rtrim(ltrim($fc['code'], '_'), '.X');
                                $suffix = '';
                                if (strpos($fc['code'], '_') === 0) {
                                    $suffix = ($fc['feature_key'] === 'queue_pause') ? '<mola id>' : '<kuyruk id>';
                                }
                                echo htmlspecialchars($clean_c . $suffix);
                            ?></code></td>
                            <td class="col-hide-mobile">
                                <?php if (!empty($fc['allowed_roles'])): ?>
                                    <?php foreach (explode(',', $fc['allowed_roles']) as $r): ?>
                                        <span class="badge badge-warning"><?php echo htmlspecialchars($r); ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);"><?php echo t('fc.everyone'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo uiStatusToggleForm($fc['id'], $fc['is_active'], 'feature_id'); ?></td>
                            <td class="text-right">
                                <div class="table-actions-cell">
                                    <?php echo uiEditButton($fc, 'openEditFeatureCodeModal'); ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit Feature Code Modal -->
<div class="modal-overlay" id="featureCodeModal">
    <div class="modal-card" style="max-width: 520px;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;" id="featureCodeModalTitle"><i class="fas fa-edit" style="color: var(--primary);"></i> <?php echo t('fc.modal_edit_title'); ?></h3>
            <button class="btn btn-secondary" onclick="closeFeatureCodeModal()" style="padding: 6px 12px;" title="<?php echo t('fc.close_tooltip'); ?>"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_feature_code" value="1">
                <input type="hidden" name="feature_id" id="modal_feature_id" value="">

                <div class="form-group">
                    <label class="form-label"><?php echo t('fc.field_title'); ?></label>
                    <input type="text" name="title" id="modal_title" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label form-label-help">
                        <span><?php echo t('fc.field_code'); ?></span>
                        <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('fc.code_help'); ?></span></span>
                    </label>
                    <input type="text" name="code" id="modal_code" class="form-control" placeholder="*78" required>
                </div>

                <div class="form-group">
                    <label class="form-label form-label-help">
                        <span><?php echo t('fc.field_allowed_roles'); ?></span>
                        <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('fc.allowed_roles_help'); ?></span></span>
                    </label>
                    <input type="text" name="allowed_roles" id="modal_allowed_roles" class="form-control" placeholder="<?php echo t('fc.allowed_roles_placeholder'); ?>">
                    <small style="color: var(--text-muted);"><?php echo t('fc.available_roles'); ?> <?php echo htmlspecialchars(implode(', ', $valid_role_keys)); ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label" style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="is_active" id="modal_is_active" value="1" style="width: auto;"> <?php echo t('fc.field_active'); ?>
                    </label>
                </div>

                <?php echo uiModalFooter('closeFeatureCodeModal()'); ?>
            </form>
        </div>
    </div>
</div>

<script src="/assets/js/feature_codes.js?v=<?php echo time(); ?>"></script>
