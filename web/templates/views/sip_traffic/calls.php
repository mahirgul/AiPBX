<?php
/** @var string $file @var string $filter @var array $calls @var bool $truncated */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$statusBadge = static function (int $code): string {
    if ($code === 0) {
        return '<span class="badge badge-secondary">—</span>';
    }
    $cls = $code < 300 ? 'badge-success' : ($code < 400 ? 'badge-info' : ($code === 401 || $code === 407 ? 'badge-warning' : 'badge-danger'));
    return '<span class="badge ' . $cls . '">' . $code . '</span>';
};
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-list', t('siptrace.calls_title')); ?>
<?php echo uiPageHeaderActions(); ?>
        <a href="/sip-traffic" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> <?php echo t('siptrace.back_files'); ?></a>
<?php echo uiPageHeaderClose(); ?>
    <p class="u-fs-12 u-muted u-mono"><?php echo $h($file); ?></p>
    <form method="GET" action="/sip-calls" class="u-d-flex u-gap-8 u-mb-12">
        <input type="hidden" name="file" value="<?php echo $h($file); ?>">
        <input type="text" name="q" value="<?php echo $h($filter); ?>" class="form-control form-control-sm" placeholder="<?php echo t('siptrace.filter_ph'); ?>">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i></button>
    </form>
    <?php if ($truncated): ?>
        <div class="alert alert-info u-fs-12"><?php echo $h(sprintf(t('siptrace.truncated'), SipCaptureService::MAX_MESSAGES)); ?></div>
    <?php endif; ?>
    <?php echo uiTableOpen([t('siptrace.col_time'), t('siptrace.col_method'), t('siptrace.col_caller'), t('siptrace.col_called'),
        t('siptrace.col_result'), ['label' => t('siptrace.col_messages'), 'class' => 'col-hide-mobile'], ['label' => t('siptrace.col_hosts'), 'class' => 'col-hide-mobile']], ['id' => 'sipCalls']); ?>
        <?php if (!$calls): ?>
            <?php echo uiTableEmptyRow(7, t('siptrace.no_calls'), 'fa-route'); ?>
        <?php endif; ?>
        <?php foreach ($calls as $c): ?>
            <tr class="u-pointer" onclick="location.href='/sip-call?file=<?php echo $h(urlencode($file)); ?>&amp;id=<?php echo $h(urlencode($c['call_id'])); ?>'">
                <td class="u-mono u-fs-12 u-nowrap"><?php echo $h(date('H:i:s', (int) $c['start'])); ?></td>
                <td><strong><?php echo $h($c['method'] ?: '—'); ?></strong></td>
                <td class="u-mono"><?php echo $h($c['from']); ?></td>
                <td class="u-mono"><?php echo $h($c['to']); ?></td>
                <td><?php echo $statusBadge((int) $c['status']); ?></td>
                <td class="col-hide-mobile"><?php echo (int) $c['count']; ?></td>
                <td class="col-hide-mobile u-mono u-fs-11"><?php echo $h(implode(', ', $c['hosts'])); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php echo uiTableClose(); ?>
</div>
