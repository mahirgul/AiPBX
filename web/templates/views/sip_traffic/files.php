<?php
/** @var string $csrf @var array $files @var array $settings */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$fmt = fn(int $t) => $t ? date('Y-m-d H:i:s', $t) : '—';
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-route', t('siptrace.title'), 'sipFilesHelp'); ?>
<?php echo uiPageHeaderActions(); ?>
        <a href="/sip-live" class="btn btn-secondary btn-sm"><i class="fas fa-satellite-dish"></i> <?php echo t('sidebar.item_sip_live'); ?></a>
        <a href="/sip-capture-settings" class="btn btn-secondary btn-sm"><i class="fas fa-sliders"></i> <?php echo t('sidebar.item_sip_capture_settings'); ?></a>
<?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('sipFilesHelp', t('siptrace.help_title')); ?>
        <p><?php echo t('siptrace.help'); ?></p>
    <?php echo uiHelpBoxClose(); ?>
    <?php if (!$settings['capture'] || !$settings['files']): ?>
        <div class="alert alert-warning"><i class="fas fa-info-circle"></i> <?php echo t('siptrace.off_notice'); ?>
            <a href="/sip-capture-settings"><?php echo t('sidebar.item_sip_capture_settings'); ?></a></div>
    <?php endif; ?>
    <?php echo uiTableOpen([t('siptrace.col_from'), t('siptrace.col_to'), t('siptrace.col_size'), ['label' => t('common.actions'), 'class' => 'text-right']], ['id' => 'sipFiles']); ?>
        <?php if (!$files): ?>
            <?php echo uiTableEmptyRow(4, t('siptrace.no_files'), 'fa-route'); ?>
        <?php endif; ?>
        <?php foreach ($files as $f): ?>
            <tr>
                <td class="u-mono u-fs-13"><?php echo $h($fmt($f['start'])); ?></td>
                <td class="u-mono u-fs-13"><?php echo $h($fmt($f['end'])); ?>
                    <?php if ($f['open']): ?> <span class="badge badge-success"><?php echo t('siptrace.being_written'); ?></span><?php endif; ?></td>
                <td><?php echo $h(number_format($f['size'] / 1048576, 2) . ' MB'); ?></td>
                <td class="text-right">
                    <a href="/sip-calls?file=<?php echo urlencode($f['name']); ?>" class="btn btn-xs btn-primary"><i class="fas fa-list"></i> <?php echo t('siptrace.show_calls'); ?></a>
                    <form method="POST" class="u-d-inline" data-no-spa="true">
                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                        <input type="hidden" name="name" value="<?php echo $h($f['name']); ?>">
                        <button type="submit" name="download" value="1" class="btn btn-xs btn-secondary" title="<?php echo t('siptrace.download_hint'); ?>"><i class="fas fa-download"></i> .pcap</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php echo uiTableClose(); ?>
</div>
