<?php
/** @var string $csrf @var list<array<string, mixed>> $reasons */
$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-mug-hot', t('pause_reports.col_reason', 'Break Reasons'), 'pauseReasonsHelpBox'); ?>
        <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($reasons); ?></span>
    <?php echo uiPageHeaderActions(); ?>
        <a href="/pause-reports" class="btn btn-secondary btn-sm"><i class="fas fa-chart-column"></i> <?php echo $h(t('pause_reports.title')); ?></a>
        <button type="button" class="btn btn-primary btn-sm" onclick="openPauseReasonModal(null)">
            <i class="fas fa-plus-circle"></i> <?php echo $h(t('common.add_new')); ?>
        </button>
    <?php echo uiPageHeaderClose(); ?>

    <?php echo uiHelpBoxOpen('pauseReasonsHelpBox', t('pause_reports.col_reason', 'Break Reasons')); ?>
        <?php echo $h(t('pause_reports.help_body')); ?>
    <?php echo uiHelpBoxClose(); ?>

    <?php echo uiTableOpen([
        ['label' => '#', 'class' => 'u-w-60'],
        t('pause_reports.col_reason'),
        t('common.status'),
        ['label' => t('common.actions'), 'class' => 'text-right'],
    ]); ?>
        <?php if (empty($reasons)): ?>
            <?php echo uiTableEmptyRow(4, t('common.no_records'), 'fa-mug-hot'); ?>
        <?php else: ?>
            <?php foreach ($reasons as $r): ?>
                <tr>
                    <td><code class="u-fw-700 u-primary"><?php echo $h($r['code']); ?></code></td>
                    <td>
                        <span class="u-fs-18 u-mr-6"><?php echo $h(!empty($r['icon']) ? $r['icon'] : '☕'); ?></span>
                        <strong><?php echo $h($r['name']); ?></strong>
                        <?php if (!empty($r['max_minutes'])): ?>
                            <span class="badge badge-info u-fs-11 u-ml-6"><?php echo (int) $r['max_minutes']; ?> <?php echo $h(t('common.min_short')); ?></span>
                            <?php if (!empty($r['warn_on_exceed'])): ?>
                                <span class="badge badge-warning u-fs-11" title="<?php echo $h(t('pause_reasons.warn_on_exceed')); ?>"><i class="fas fa-exclamation-triangle"></i></span>
                            <?php endif; ?>
                            <?php if (!empty($r['auto_unpause'])): ?>
                                <span class="badge badge-success u-fs-11" title="<?php echo $h(t('pause_reasons.auto_unpause')); ?>"><i class="fas fa-rotate-left"></i></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo uiStatusToggleForm((int) $r['id'], (int) $r['is_active'], 'reason_id'); ?></td>
                    <td class="text-right">
                        <div class="table-actions-cell">
                            <?php echo uiEditButton($r, 'openPauseReasonModal'); ?>
                            <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo jsArg(t('common.confirm_delete', 'Delete?')); ?>);">
                                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                                <input type="hidden" name="delete_reason" value="1">
                                <input type="hidden" name="reason_id" value="<?php echo (int) $r['id']; ?>">
                                <button type="submit" class="btn btn-icon btn-danger btn-sm" title="<?php echo $h(t('common.delete')); ?>"><i class="fas fa-trash-alt"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php echo uiTableClose(); ?>
</div>

<div class="modal-overlay" id="pauseReasonModal">
    <div class="modal-card u-maxw-480">
        <div class="modal-header">
            <h3 class="u-title" id="pauseReasonModalTitle"><i class="fas fa-mug-hot u-primary"></i> <?php echo $h(t('pause_reports.col_reason', 'Break Reasons')); ?></h3>
            <button type="button" class="btn btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('pauseReasonModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" autocomplete="off">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                <input type="hidden" name="save_reason" value="1">
                <input type="hidden" name="id" id="pr_id" value="0">
                <div class="u-grid-2 u-gap-12">
                    <div class="form-group">
                        <label class="form-label" for="pr_code"><?php echo $h(t('pause_reasons.code')); ?></label>
                        <input type="text" name="code" id="pr_code" class="form-control u-mono" maxlength="16" required placeholder="1">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="pr_sort"><?php echo $h(t('pause_reasons.sort_order')); ?></label>
                        <input type="number" name="sort_order" id="pr_sort" class="form-control" value="10">
                    </div>
                </div>
                <div class="u-grid-2 u-gap-12">
                    <div class="form-group">
                        <label class="form-label" for="pr_name"><?php echo $h(t('pause_reports.col_reason')); ?></label>
                        <input type="text" name="name" id="pr_name" class="form-control" maxlength="80" required placeholder="<?php echo $h(t('pause_reasons.name_placeholder')); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="pr_icon"><?php echo $h(t('pause_reasons.icon_unicode')); ?></label>
                        <input type="text" name="icon" id="pr_icon" class="form-control" maxlength="16" placeholder="☕ 🍽️ 📅 🎓">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="pr_max"><?php echo $h(t('pause_reasons.max_minutes')); ?></label>
                    <input type="number" name="max_minutes" id="pr_max" class="form-control" min="1" max="1440" placeholder="15">
                </div>
                <div class="form-group">
                    <label class="u-check-label u-mb-6">
                        <input type="checkbox" name="warn_on_exceed" id="pr_warn" value="1" class="u-check" checked>
                        <span><?php echo $h(t('pause_reasons.warn_on_exceed_label')); ?></span>
                    </label>
                    <label class="u-check-label u-mb-6">
                        <input type="checkbox" name="auto_unpause" id="pr_auto_unpause" value="1" class="u-check">
                        <span><?php echo $h(t('pause_reasons.auto_unpause_label')); ?></span>
                    </label>
                    <label class="u-check-label">
                        <input type="checkbox" name="is_active" id="pr_active" value="1" class="u-check" checked>
                        <span><?php echo $h(t('common.active')); ?></span>
                    </label>
                </div>
            </div>
            <div class="modal-footer u-d-flex u-gap-8 u-jc-flex-end">
                <button type="button" class="btn btn-secondary" onclick="UIHelper.closeOverlayModal('pauseReasonModal')"><?php echo $h(t('common.cancel')); ?></button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo $h(t('common.save')); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
window.openPauseReasonModal = function (row) {
    document.getElementById('pr_id').value = row ? (row.id || 0) : 0;
    document.getElementById('pr_code').value = row ? (row.code || '') : '';
    document.getElementById('pr_name').value = row ? (row.name || '') : '';
    document.getElementById('pr_icon').value = row ? (row.icon || '☕') : '☕';
    document.getElementById('pr_max').value = row && row.max_minutes ? row.max_minutes : '';
    document.getElementById('pr_sort').value = row ? (row.sort_order ?? 10) : 10;
    document.getElementById('pr_warn').checked = row ? Number(row.warn_on_exceed ?? 1) === 1 : true;
    document.getElementById('pr_auto_unpause').checked = row ? Number(row.auto_unpause ?? 0) === 1 : false;
    document.getElementById('pr_active').checked = row ? Number(row.is_active) === 1 : true;
    UIHelper.openOverlayModal('pauseReasonModal');
};
</script>
