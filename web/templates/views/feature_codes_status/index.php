<div class="card">
    <?php echo uiPageHeader('fa-users', t('fc_status.header_title'), 'featureCodesStatusHelpBox'); ?>
<?php echo uiHelpBoxOpen('featureCodesStatusHelpBox', t('common.module_guide')); ?><?php echo t('feature_codes_status.help_body'); ?><?php echo uiHelpBoxClose(); ?>
    <?php echo uiTableOpen([
        t('fc_status.col_extension'),
        t('fc_status.col_fullname'),
        t('fc_status.col_pickup_group'),
        t('fc_status.col_dnd'),
        t('fc_status.col_forward'),
    ]); ?>
                <?php if (empty($users)): ?>
                    <?php echo uiTableEmptyRow(5, t('fc_status.empty'), 'fa-users'); ?>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td class="u-fw-700"><?php echo htmlspecialchars($u['extension']); ?></td>
                            <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                            <td>
                                <?php if (!empty($u['pickup_group'])): ?>
                                    <span class="badge badge-info"><?php echo htmlspecialchars($u['pickup_group']); ?></span>
                                <?php else: ?>
                                    <span class="u-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($u['dnd_enabled'])): ?>
                                    <span class="badge badge-danger"><i class="fas fa-bell-slash"></i> <?php echo t('fc_status.dnd_on'); ?></span>
                                <?php else: ?>
                                    <span class="u-muted"><?php echo t('fc_status.dnd_off'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                    $hasAny = false;
                                    if (!empty($u['call_forward_number'])) {
                                        $hasAny = true;
                                        echo '<span class="badge badge-warning u-mr-4" title="' . htmlspecialchars(t('my_phone.cf_always_label')) . '"><i class="fas fa-forward"></i> ' . htmlspecialchars($u['call_forward_number']) . '</span>';
                                    }
                                    if (!empty($u['cf_busy_number'])) {
                                        $hasAny = true;
                                        echo '<span class="badge badge-info u-mr-4" title="' . htmlspecialchars(t('my_phone.cf_busy_label')) . '"><i class="fas fa-phone-slash"></i> ' . htmlspecialchars($u['cf_busy_number']) . '</span>';
                                    }
                                    if (!empty($u['cf_noanswer_number'])) {
                                        $hasAny = true;
                                        echo '<span class="badge badge-secondary" title="' . htmlspecialchars(t('my_phone.cf_noanswer_label')) . ' (' . intval($u['cf_noanswer_timeout'] ?? 20) . 's)"><i class="fas fa-phone-volume"></i> ' . htmlspecialchars($u['cf_noanswer_number']) . '</span>';
                                    }
                                    if (!$hasAny) {
                                        echo '<span class="u-muted">-</span>';
                                    }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php echo uiTableClose(); ?>
</div>
