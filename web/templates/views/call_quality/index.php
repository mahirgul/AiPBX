<?php
/** @var string $csrf @var array $settings @var array $status @var array $summary @var array $bad */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$pct = fn($v) => CallQualityService::pct($v);
$badge = fn(string $level) => ['poor' => 'badge-danger', 'fair' => 'badge-warning'][$level] ?? 'badge-success';
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-chart-line', t('call_quality.header_title'), 'cqHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>

<?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('cqHelpBox', t('call_quality.help_title')); ?>
        <?php echo sprintf(t('call_quality.help_body'), $h(CallQualityService::FAIR_PCT)); ?>
<?php echo uiHelpBoxClose(); ?>
    <div class="u-p-0-4-8">
        <?php if (!$status['capture']): ?>
            <p class="u-warning"><strong><?php echo t('call_quality.capture_off'); ?></strong>
                <a href="/asterisk-settings"><?php echo t('call_quality.capture_off_link'); ?> →</a></p>
        <?php elseif ($status['last_rtcp'] === null): ?>
            <p class="u-warning"><?php echo t('call_quality.no_reports'); ?></p>
        <?php else: ?>
            <p><?php echo $h(sprintf(t('call_quality.last_report'), $status['last_rtcp'])); ?></p>
        <?php endif; ?>
        <?php if ($status['pass_ago'] !== null): ?>
            <p class="u-muted u-fs-12"><?php echo $h(sprintf(t('call_quality.last_pass'), $status['pass_ago'])); ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-table"></i> <?php echo t('call_quality.summary_title'); ?></div></div>
    <?php echo uiTableOpen([
        t('call_quality.col_leg'),
        t('call_quality.col_calls'),
        t('call_quality.col_poor'),
        t('call_quality.col_fair'),
        t('call_quality.col_loss_in'),
        t('call_quality.col_loss_out'),
        t('call_quality.col_jitter'),
    ]); ?>
            <?php if (!$summary): ?>
                <?php echo uiTableEmptyRow(7, t('call_quality.empty_summary'), 'fa-chart-line'); ?>
            <?php endif; ?>
            <?php foreach ($summary as $r): ?>
                <tr>
                    <td><strong><?php echo $h(CallQualityService::legLabel($r)); ?></strong></td>
                    <td><?php echo (int) $r['legs']; ?></td>
                    <td><?php echo (int) $r['poor'] ? '<span class="badge badge-danger">' . (int) $r['poor'] . '</span>' : '0'; ?></td>
                    <td><?php echo (int) $r['fair']; ?></td>
                    <td><?php echo $h($pct($r['avg_in'])) . ' / ' . $h($pct($r['max_in'])); ?></td>
                    <td><?php echo $h($pct($r['avg_out'])) . ' / ' . $h($pct($r['max_out'])); ?></td>
                    <td><?php echo (int) $r['jit_in'] . ' / ' . (int) $r['jit_out']; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php echo uiTableClose(); ?>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-triangle-exclamation"></i> <?php echo t('call_quality.bad_title'); ?></div></div>
    <?php echo uiTableOpen([
        t('call_quality.col_time'),
        t('call_quality.col_level'),
        t('call_quality.col_leg'),
        t('call_quality.col_media_from'),
        t('call_quality.col_loss_in_max'),
        t('call_quality.col_loss_out_max'),
        t('call_quality.col_reports'),
        'Call-ID',
    ]); ?>
            <?php if (!$bad): ?>
                <?php echo uiTableEmptyRow(8, t('call_quality.empty_bad'), 'fa-circle-check'); ?>
            <?php endif; ?>
            <?php foreach ($bad as $r): ?>
                <tr>
                    <td class="u-fs-12"><?php echo $h($r['first_ts']); ?></td>
                    <td><span class="badge <?php echo $badge($r['level']); ?>"><?php echo $h(t('call_quality.level_' . $r['level'])); ?></span></td>
                    <td><?php echo $h(CallQualityService::legLabel($r)); ?></td>
                    <td class="u-fs-12"><?php echo $h($r['peer']); ?></td>
                    <td><?php echo $h($pct($r['loss_in_max'])); ?></td>
                    <td><?php echo $h($pct($r['loss_out_max'])); ?></td>
                    <td><?php echo (int) $r['reports_in'] . ' / ' . (int) $r['reports_out']; ?></td>
                    <td class="u-fs-11"><code><?php echo $h(mb_substr($r['call_id'], 0, 24)); ?></code></td>
                </tr>
            <?php endforeach; ?>
            <?php echo uiTableClose(); ?>
</div>

<form method="POST" autocomplete="off" class="card">
    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
    <input type="hidden" name="save_call_quality" value="1">
    <div class="card-header"><div class="card-title"><i class="fas fa-sliders-h"></i> <?php echo t('call_quality.settings_title'); ?></div></div>
    <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-220-1fr u-gap-16">
        <div class="form-group">
            <label class="form-label"><?php echo t('call_quality.field_poor_pct'); ?></label>
            <input type="number" name="poor_pct" class="form-control" min="1" max="50" value="<?php echo $h((int) $settings['poor_pct']); ?>" required>
            <small class="u-hint"><?php echo t('call_quality.field_poor_pct_help'); ?></small>
        </div>
        <div class="form-group">
            <label class="form-label"><?php echo t('call_quality.field_alert_legs'); ?></label>
            <input type="number" name="alert_legs" class="form-control" min="1" max="50" value="<?php echo $h($settings['alert_legs']); ?>" required>
            <small class="u-hint"><?php echo t('call_quality.field_alert_legs_help'); ?></small>
        </div>
        <div class="form-group">
            <label class="form-label"><?php echo t('call_quality.field_keep_days'); ?></label>
            <input type="number" name="keep_days" class="form-control" min="7" max="3650" value="<?php echo $h($settings['keep_days']); ?>" required>
            <small class="u-hint">7–3650</small>
        </div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button></div>
</form>
