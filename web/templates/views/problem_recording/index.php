<?php
/** @var string $csrf @var array $diag */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$diag = $diag ?? ['running' => false, 'zips' => []];
?>
<div class="card">
    <?php echo uiPageHeader('fa-file-medical', t('diag.title')); ?>
    <p class="u-fs-13 u-muted"><?php echo t('diag.desc'); ?></p>
    <form method="POST" class="u-d-flex u-ai-center u-gap-8 u-wrap">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
        <?php if (!empty($diag['running'])): ?>
            <span class="badge badge-danger"><i class="fas fa-circle"></i> <?php echo $h(sprintf(t('diag.running_since'), date('H:i:s', (int) $diag['start']), (int) $diag['minutes'])); ?></span>
            <button type="submit" name="diag_stop" value="1" class="btn btn-primary btn-sm"><i class="fas fa-stop"></i> <?php echo t('diag.stop'); ?></button>
        <?php else: ?>
            <label class="u-fs-13" for="diag_minutes"><?php echo t('diag.minutes'); ?></label>
            <select name="minutes" id="diag_minutes" class="form-control form-control-sm u-w-auto">
                <?php foreach ([5, 10, 15, 30] as $m): ?>
                    <option value="<?php echo $m; ?>" <?php echo $m === 15 ? 'selected' : ''; ?>><?php echo $m; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="diag_start" value="1" class="btn btn-danger btn-sm"><i class="fas fa-circle"></i> <?php echo t('diag.start'); ?></button>
        <?php endif; ?>
    </form>
    <?php if (!empty($diag['zips'])): ?>
        <?php echo uiTableOpen([t('diag.col_file'), t('diag.col_size'), ['label' => t('common.actions'), 'class' => 'text-right']], ['search' => false]); ?>
            <?php foreach ($diag['zips'] as $z): ?>
                <tr>
                    <td><code><?php echo $h($z['name']); ?></code></td>
                    <td><?php echo $h(number_format($z['size'] / 1024, 0) . ' KB'); ?></td>
                    <td class="text-right">
                        <form method="POST" class="u-d-inline" data-no-spa="true">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                            <input type="hidden" name="name" value="<?php echo $h($z['name']); ?>">
                            <button type="submit" name="diag_download" value="1" class="btn btn-xs btn-primary"><i class="fas fa-download"></i> <?php echo t('diag.download'); ?></button>
                            <button type="submit" name="diag_delete" value="1" class="btn btn-icon btn-xs btn-danger" title="<?php echo t('common.delete'); ?>" onclick="return confirm(<?php echo jsArg(t('diag.confirm_delete')); ?>);"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php echo uiTableClose(); ?>
    <?php endif; ?>
    <p class="u-fs-12 u-muted u-mt-10"><i class="fas fa-user-shield"></i> <?php echo t('diag.privacy'); ?></p>
</div>
