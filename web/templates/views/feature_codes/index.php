<div class="card">
    <?php echo uiPageHeaderOpen('fa-hashtag', t('fc.header_title'), 'featureCodesHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>

<?php echo uiPageHeaderClose(); ?>

    <?php echo uiHelpBoxOpen('featureCodesHelpBox', t('fc.help_title')); ?>
        <?php echo t('fc.help_body'); ?><br>
        <?php echo t('fc.help_body2'); ?><br>
        <?php echo t('fc.help_body3'); ?>
<?php echo uiHelpBoxClose(); ?>

    <?php echo uiTableOpen([
        t('fc.col_title'),
        t('fc.col_code'),
        ['label' => t('fc.col_allowed_roles'), 'class' => 'col-hide-mobile'],
        t('fc.col_status'),
        ['label' => t('fc.col_actions'), 'class' => 'text-right'],
    ]); ?>
                <?php if (empty($codes)): ?>
                    <?php echo uiTableEmptyRow(5, t('fc.empty'), 'fa-hashtag'); ?>
                <?php else: ?>
                    <?php foreach ($codes as $fc): ?>
                        <tr>
                            <td class="u-strong"><?php echo htmlspecialchars($fc['title']); ?></td>
                            <td><code class="u-fs-14 u-fw-700 u-primary"><?php
                                $clean_c = rtrim(ltrim($fc['code'], '_'), '.X');
                                $suffix = '';
                                if (strpos($fc['code'], '_') === 0) {
                                    if ($fc['feature_key'] === 'conference_join') {
                                        $suffix = '<' . t('fc.room_id_suffix', 'oda no') . '>';
                                    } elseif ($fc['feature_key'] === 'queue_pause') {
                                        $suffix = '<mola id>';
                                    } else {
                                        $suffix = '<kuyruk id>';
                                    }
                                }
                                echo htmlspecialchars($clean_c . $suffix);
                            ?></code></td>
                            <td class="col-hide-mobile">
                                <?php if (!empty($fc['allowed_roles'])): ?>
                                    <?php foreach (explode(',', $fc['allowed_roles']) as $r): ?>
                                        <span class="badge badge-warning"><?php echo htmlspecialchars($r); ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="u-muted"><?php echo t('fc.everyone'); ?></span>
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
            <?php echo uiTableClose(); ?>
</div>

<!-- Edit Feature Code Modal -->
<div class="modal-overlay" id="featureCodeModal">
    <div class="modal-card u-maxw-520">
        <div class="modal-header">
            <h3 class="u-title" id="featureCodeModalTitle"><i class="fas fa-edit u-primary"></i> <?php echo t('fc.modal_edit_title'); ?></h3>
            <button class="btn btn-icon btn-secondary u-btn-pad" onclick="closeFeatureCodeModal()" title="<?php echo t('fc.close_tooltip'); ?>"><i class="fas fa-times"></i></button>
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
                    <small class="u-muted"><?php echo t('fc.available_roles'); ?> <?php echo htmlspecialchars(implode(', ', $valid_role_keys)); ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label u-flex-center">
                        <input type="checkbox" name="is_active" id="modal_is_active" value="1" class="u-w-auto"> <?php echo t('fc.field_active'); ?>
                    </label>
                </div>

                <?php echo uiModalFooter('closeFeatureCodeModal()'); ?>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo asset('/assets/js/feature_codes.js'); ?>"></script>
