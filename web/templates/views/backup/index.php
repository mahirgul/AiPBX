<?php
$state_labels = [
    'running' => t('backup.state_running'),
    'done' => t('backup.state_done'),
    'failed' => t('backup.state_failed'),
];
$state = $status['state'] ?? '';
$js_text = [];
foreach (['creating', 'btn_create', 'upload_choose', 'upload_progress', 'upload_checking', 'upload_failed', 'confirm_delete',
    'kind_uploaded', 'kind_safety', 'btn_download', 'btn_restore', 'btn_delete', 'empty', 'restore_title', 'state_signin',
    'state_running', 'state_done', 'state_failed'] as $k) {
    $js_text[$k] = t('backup.' . $k);
}
?>
<div class="card">
    <?php echo uiPageHeader('fa-box-archive', t('backup.page_title'), 'backupHelpBox'); ?>

    <?php echo uiHelpBoxOpen('backupHelpBox', t('backup.help_title')); ?>
        <?php echo t('backup.help_body'); ?>
<?php echo uiHelpBoxClose(); ?>

    <div class="u-flex-gap u-wrap u-ai-flex-end u-mb-20">
        <button type="button" class="btn btn-primary" id="bk-create" onclick="bkCreate()" <?php echo $creating ? 'disabled' : ''; ?>>
            <i class="fas fa-box-archive"></i> <span id="bk-create-label"><?php echo $creating ? t('backup.creating') : t('backup.btn_create'); ?></span>
        </button>
        <div class="form-group u-m-0">
            <label class="form-label u-fs-12" for="bk-keep"><?php echo t('backup.keep_days'); ?></label>
            <div class="u-flex-gap">
                <input type="number" id="bk-keep" class="form-control u-w-100-2" min="1" max="999" value="<?php echo (int) $keep_days; ?>">
                <button type="button" class="btn btn-icon btn-secondary" onclick="bkSaveKeep()"><i class="fas fa-save"></i></button>
            </div>
        </div>
    </div>

    <h4 class="u-fw-700 u-m-0-0-12"><?php echo t('backup.list_title'); ?></h4>
    <?php echo uiTableOpen([
        t('backup.col_name'),
        t('backup.col_date'),
        t('backup.col_size'),
        t('backup.col_version'),
        t('backup.col_actions'),
    ], ['body_id' => 'bk-rows', 'search' => false, 'frame' => 'u-mb-24']); ?>
                <?php foreach ($backups as $b): ?>
                    <tr data-name="<?php echo htmlspecialchars($b['name']); ?>">
                        <td>
                            <code><?php echo htmlspecialchars($b['name']); ?></code>
                            <?php if ($b['uploaded']): ?><span class="badge badge-warning"><?php echo t('backup.kind_uploaded'); ?></span><?php endif; ?>
                            <?php if ($b['safety']): ?><span class="badge badge-info"><?php echo t('backup.kind_safety'); ?></span><?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars(date('d.m.Y H:i', $b['time'])); ?></td>
                        <td><?php echo htmlspecialchars(BackupService::humanSize($b['bytes'])); ?></td>
                        <td><?php echo htmlspecialchars($b['version'] !== '' ? 'v' . $b['version'] : '—'); ?></td>
                        <td class="u-flex-gap u-wrap">
                            <button type="button" class="btn btn-secondary btn-sm" data-act="download"><i class="fas fa-download"></i> <?php echo t('backup.btn_download'); ?></button>
                            <button type="button" class="btn btn-warning btn-sm" data-act="restore"><i class="fas fa-rotate-left"></i> <?php echo t('backup.btn_restore'); ?></button>
                            <button type="button" class="btn btn-danger btn-sm" data-act="delete"><i class="fas fa-trash"></i> <?php echo t('backup.btn_delete'); ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($backups)): ?>
                    <tr><td colspan="5" class="u-muted"><?php echo t('backup.empty'); ?></td></tr>
                <?php endif; ?>
            <?php echo uiTableClose(); ?>

    <div id="bk-restore" class="card u-p-16 u-mb-24 u-bd-1-solid-danger" style="display: none;">
        <h4 class="u-fw-700 u-m-0-0-8" id="bk-restore-title"></h4>
        <p class="u-fs-13 u-danger"><i class="fas fa-triangle-exclamation"></i> <?php echo t('backup.restore_warning'); ?></p>
        <div class="form-group">
            <label class="form-label u-fs-12"><?php echo t('backup.restore_scope'); ?></label>
            <label class="u-check-label u-fs-13"><input type="radio" name="bk-scope" value="all" class="u-accent" checked> <?php echo t('backup.restore_all'); ?></label>
            <label class="u-check-label u-fs-13"><input type="radio" name="bk-scope" value="db" class="u-accent"> <?php echo t('backup.restore_db'); ?></label>
        </div>
        <div class="form-group">
            <label class="u-check-label u-fs-13"><input type="checkbox" id="bk-allow-calls" class="u-accent"> <?php echo t('backup.restore_allow_calls'); ?></label>
        </div>
        <div class="form-group">
            <label class="form-label u-fs-12" for="bk-confirm"><?php echo t('backup.restore_type'); ?></label>
            <input type="text" id="bk-confirm" class="form-control" autocomplete="off" spellcheck="false">
        </div>
        <?php if (!$sso_only): ?>
            <div class="form-group">
                <label class="form-label u-fs-12" for="bk-password"><?php echo t('backup.restore_password'); ?></label>
                <input type="password" id="bk-password" class="form-control" autocomplete="current-password">
            </div>
        <?php endif; ?>
        <div class="u-flex-gap">
            <button type="button" class="btn btn-danger" id="bk-restore-go" onclick="bkRestoreGo()"><i class="fas fa-rotate-left"></i> <?php echo t('backup.restore_start'); ?></button>
            <button type="button" class="btn btn-secondary" onclick="bkRestoreClose()"><?php echo t('backup.restore_cancel'); ?></button>
        </div>
    </div>

    <h4 class="u-fw-700 u-m-0-0-8"><?php echo t('backup.upload_title'); ?></h4>
    <p class="u-muted u-fs-12 u-m-0-0-12"><?php echo t('backup.upload_help'); ?></p>
    <div class="u-flex-gap u-wrap u-ai-center u-mb-24">
        <input type="file" id="bk-file" accept=".gz,.tgz,application/gzip">
        <button type="button" class="btn btn-secondary" id="bk-upload" onclick="bkUpload()"><i class="fas fa-upload"></i> <?php echo t('backup.btn_upload'); ?></button>
        <span class="u-fs-12 u-muted" id="bk-upload-state"></span>
    </div>

    <h4 class="u-fw-700 u-m-0-0-8"><?php echo t('backup.state_title'); ?></h4>
    <div class="u-fs-13 u-mb-10" id="bk-state">
        <?php if ($state !== ''): ?>
            <strong><?php echo htmlspecialchars($state_labels[$state] ?? $state); ?></strong> — <?php echo htmlspecialchars((string) ($status['step'] ?? '')); ?>
        <?php else: ?>—<?php endif; ?>
    </div>
    <details>
        <summary class="u-fs-12 u-muted"><?php echo t('backup.log_title'); ?></summary>
        <pre class="u-maxh-280 u-of-auto u-fs-12" id="bk-log"><?php echo htmlspecialchars($log_tail); ?></pre>
    </details>
</div>

<script>
window.BACKUP_PAGE = {
    running: <?php echo json_encode($state === 'running'); ?>,
    creating: <?php echo json_encode((bool) $creating); ?>,
    ssoOnly: <?php echo json_encode((bool) $sso_only); ?>,
    text: <?php echo json_encode($js_text, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>
};
</script>
<script src="<?php echo asset('/assets/js/backup.js'); ?>"></script>
