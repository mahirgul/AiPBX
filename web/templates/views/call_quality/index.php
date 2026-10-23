<?php
/** @var string $csrf @var array $settings @var array $status @var array $summary @var array $bad */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$pct = fn($v) => CallQualityService::pct($v);
$badge = fn(string $level) => ['poor' => 'badge-danger', 'fair' => 'badge-warning'][$level] ?? 'badge-success';
?>
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-chart-line u-primary"></i> <?php echo t('call_quality.header_title'); ?></div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('cqHelpBox')" title="<?php echo t('common.module_guide'); ?>">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>
    <div class="module-help-box" id="cqHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('call_quality.help_title'); ?></h4>
        <?php echo sprintf(t('call_quality.help_body'), $h(CallQualityService::FAIR_PCT)); ?>
    </div>
    <div style="padding: 0 4px 8px;">
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
    <div class="table-responsive">
        <table class="data-table">
            <thead><tr>
                <th><?php echo t('call_quality.col_leg'); ?></th>
                <th><?php echo t('call_quality.col_calls'); ?></th>
                <th><?php echo t('call_quality.col_poor'); ?></th>
                <th><?php echo t('call_quality.col_fair'); ?></th>
                <th><?php echo t('call_quality.col_loss_in'); ?></th>
                <th><?php echo t('call_quality.col_loss_out'); ?></th>
                <th><?php echo t('call_quality.col_jitter'); ?></th>
            </tr></thead>
            <tbody>
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
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-triangle-exclamation"></i> <?php echo t('call_quality.bad_title'); ?></div></div>
    <div class="table-responsive">
        <table class="data-table">
            <thead><tr>
                <th><?php echo t('call_quality.col_time'); ?></th>
                <th><?php echo t('call_quality.col_level'); ?></th>
                <th><?php echo t('call_quality.col_leg'); ?></th>
                <th><?php echo t('call_quality.col_media_from'); ?></th>
                <th><?php echo t('call_quality.col_loss_in_max'); ?></th>
                <th><?php echo t('call_quality.col_loss_out_max'); ?></th>
                <th><?php echo t('call_quality.col_reports'); ?></th>
                <th>Call-ID</th>
            </tr></thead>
            <tbody>
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
            </tbody>
        </table>
    </div>
</div>

<form method="POST" autocomplete="off" class="card">
    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
    <input type="hidden" name="save_call_quality" value="1">
    <div class="card-header"><div class="card-title"><i class="fas fa-sliders-h"></i> <?php echo t('call_quality.settings_title'); ?></div></div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
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
    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
</form>
