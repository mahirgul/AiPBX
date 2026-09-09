<?php
$total_count = 0;
foreach ($pending as $rows) { $total_count += count($rows); }

$action_badge_map = ['create' => 'success', 'update' => 'warning', 'delete' => 'danger'];
$action_label_map = [
    'create' => t('pending_sync.action_create'),
    'update' => t('pending_sync.action_update'),
    'delete' => t('pending_sync.action_delete'),
];
?>
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-cloud-upload-alt" style="color: var(--primary);"></i> <?php echo t('pending_sync.header_title'); ?>
        </div>
    </div>

    <div class="module-help-box" style="display: block; margin-bottom: 20px;">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('pending_sync.help_title'); ?></h4>
        <?php echo t('pending_sync.help_body'); ?>
    </div>

    <?php if ($total_count === 0): ?>
        <div style="text-align: center; color: var(--text-muted); padding: 40px 20px;">
            <i class="fas fa-check-circle" style="font-size: 40px; margin-bottom: 12px; display: block; color: var(--success);"></i>
            <?php echo t('pending_sync.empty'); ?>
        </div>
    <?php else: ?>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCSRFToken()); ?>">
            <input type="hidden" name="apply" value="1">

            <?php foreach ($pending as $domain => $rows): ?>
                <div style="margin-bottom: 20px;">
                    <div style="font-weight: 700; font-size: 14px; margin-bottom: 8px; color: var(--text-main);">
                        <i class="fas fa-folder-open" style="color: var(--primary);"></i>
                        <?php echo htmlspecialchars(t('pending_sync.domain_' . $domain, $domain)); ?>
                        <span class="badge badge-secondary"><?php echo count($rows); ?></span>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table" data-no-dt="true">
                            <thead>
                                <tr>
                                    <th><?php echo t('pending_sync.col_change'); ?></th>
                                    <th><?php echo t('pending_sync.col_action'); ?></th>
                                    <th class="col-hide-mobile"><?php echo t('pending_sync.col_changed_by'); ?></th>
                                    <th class="col-hide-mobile"><?php echo t('pending_sync.col_changed_at'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($r['entity_label']); ?></td>
                                        <td><?php echo uiStatusBadge($r['action'], $action_badge_map, 'info', $action_label_map[$r['action']] ?? $r['action']); ?></td>
                                        <td class="col-hide-mobile"><?php echo htmlspecialchars($r['changed_by_name'] ?? t('pending_sync.unknown_user')); ?></td>
                                        <td class="col-hide-mobile" style="white-space: nowrap; color: var(--text-muted); font-size: 12px;"><?php echo htmlspecialchars($r['changed_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>

            <div style="display: flex; justify-content: flex-end; border-top: 1px solid var(--border-color); padding-top: 16px;">
                <button type="submit" class="btn btn-primary" onclick="return confirm('<?php echo htmlspecialchars(t('pending_sync.confirm_apply'), ENT_QUOTES); ?>');">
                    <i class="fas fa-paper-plane"></i> <?php echo t('pending_sync.submit_button'); ?>
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>
