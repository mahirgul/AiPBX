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
    <?php echo uiPageHeader('fa-cloud-upload-alt', t('pending_sync.header_title'), 'pendingSyncHelpBox'); ?>

    <?php echo uiHelpBoxOpen('pendingSyncHelpBox', t('pending_sync.help_title')); ?>
        <?php echo t('pending_sync.help_body'); ?>
<?php echo uiHelpBoxClose(); ?>

    <?php if ($total_count === 0): ?>
        <div class="u-ta-center u-muted u-p-40-20">
            <i class="fas fa-check-circle u-fs-40 u-mb-12 u-success" style="display: block;"></i>
            <?php echo t('pending_sync.empty'); ?>
        </div>
    <?php else: ?>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCSRFToken()); ?>">
            <input type="hidden" name="apply" value="1">

            <?php foreach ($pending as $domain => $rows): ?>
                <div class="u-mb-20">
                    <div class="u-fw-700 u-fs-14 u-mb-8 u-text-main">
                        <i class="fas fa-folder-open u-primary"></i>
                        <?php echo htmlspecialchars(t('pending_sync.domain_' . $domain, $domain)); ?>
                        <span class="badge badge-secondary"><?php echo count($rows); ?></span>
                    </div>
                    <?php echo uiTableOpen([
                        t('pending_sync.col_change'),
                        t('pending_sync.col_action'),
                        ['label' => t('pending_sync.col_changed_by'), 'class' => 'col-hide-mobile'],
                        ['label' => t('pending_sync.col_changed_at'), 'class' => 'col-hide-mobile'],
                    ], ['search' => false]); ?>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($r['entity_label']); ?>
                                            <?php if ($domain === 'readdress'): ?>
                                                <div class="u-muted u-fs-12"><?php echo t('netset.readdress_pending_note'); ?></div>
                                                <?php if (hasModulePermission('pending_sync', 'edit')): ?>
                                                    <button type="submit" form="readdressDiscard" name="discard_readdress" value="<?php echo htmlspecialchars($r['entity_id']); ?>" class="btn btn-secondary btn-xs u-mt-6" onclick="return confirm(<?php echo jsArg(t('netset.readdress_discard_confirm')); ?>);"><i class="fas fa-xmark"></i> <?php echo t('netset.readdress_discard'); ?></button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo uiStatusBadge($r['action'], $action_badge_map, 'info', $action_label_map[$r['action']] ?? $r['action']); ?></td>
                                        <td class="col-hide-mobile"><?php echo htmlspecialchars($r['changed_by_name'] ?? t('pending_sync.unknown_user')); ?></td>
                                        <td class="col-hide-mobile u-nowrap u-muted u-fs-12"><?php echo htmlspecialchars($r['changed_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php echo uiTableClose(); ?>
                </div>
            <?php endforeach; ?>

            <?php if (hasModulePermission('pending_sync', 'edit')): ?>
                <style>
                @media (max-width: 768px) {
                    .pending-sync-submit-box {
                        justify-content: stretch !important;
                    }
                    .pending-sync-submit-box button {
                        width: 100% !important;
                        justify-content: center !important;
                        padding: 12px !important;
                        font-size: 14px !important;
                    }
                }
                </style>
                <div class="pending-sync-submit-box u-d-flex u-jc-flex-end u-border-top-1-solid-border-color u-pt-16">
                    <button type="submit" class="btn btn-primary" onclick="return confirm(<?php echo jsArg(t('pending_sync.confirm_apply')); ?>);">
                        <i class="fas fa-paper-plane"></i> <?php echo t('pending_sync.submit_button'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </form>
        <form id="readdressDiscard" method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCSRFToken()); ?>">
        </form>
    <?php endif; ?>
</div>
