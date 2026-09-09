<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-users" style="color: var(--primary);"></i> <?php echo t('fc_status.header_title'); ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?php echo t('fc_status.col_extension'); ?></th>
                    <th><?php echo t('fc_status.col_fullname'); ?></th>
                    <th><?php echo t('fc_status.col_pickup_group'); ?></th>
                    <th><?php echo t('fc_status.col_dnd'); ?></th>
                    <th><?php echo t('fc_status.col_forward'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <?php echo uiTableEmptyRow(5, t('fc_status.empty'), 'fa-users'); ?>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td style="font-weight: 700;"><?php echo htmlspecialchars($u['extension']); ?></td>
                            <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                            <td>
                                <?php if (!empty($u['pickup_group'])): ?>
                                    <span class="badge badge-info"><?php echo htmlspecialchars($u['pickup_group']); ?></span>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($u['dnd_enabled'])): ?>
                                    <span class="badge badge-danger"><i class="fas fa-bell-slash"></i> <?php echo t('fc_status.dnd_on'); ?></span>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);"><?php echo t('fc_status.dnd_off'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                    $hasAny = false;
                                    if (!empty($u['call_forward_number'])) {
                                        $hasAny = true;
                                        echo '<span class="badge badge-warning" title="' . htmlspecialchars(t('my_phone.cf_always_label')) . '" style="margin-right: 4px;"><i class="fas fa-forward"></i> ' . htmlspecialchars($u['call_forward_number']) . '</span>';
                                    }
                                    if (!empty($u['cf_busy_number'])) {
                                        $hasAny = true;
                                        echo '<span class="badge badge-info" title="' . htmlspecialchars(t('my_phone.cf_busy_label')) . '" style="margin-right: 4px;"><i class="fas fa-phone-slash"></i> ' . htmlspecialchars($u['cf_busy_number']) . '</span>';
                                    }
                                    if (!empty($u['cf_noanswer_number'])) {
                                        $hasAny = true;
                                        echo '<span class="badge badge-secondary" title="' . htmlspecialchars(t('my_phone.cf_noanswer_label')) . ' (' . intval($u['cf_noanswer_timeout'] ?? 20) . 's)"><i class="fas fa-phone-volume"></i> ' . htmlspecialchars($u['cf_noanswer_number']) . '</span>';
                                    }
                                    if (!$hasAny) {
                                        echo '<span style="color: var(--text-muted);">-</span>';
                                    }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
