<?php
/** @var string $csrf @var array $checks @var array $settings @var array $recipientIds @var array $users @var bool $runQueued */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$badge = ['ok' => 'badge-success', 'warn' => 'badge-warning', 'fail' => 'badge-danger', 'info' => 'badge-info', 'off' => 'badge-primary', 'unknown' => 'badge-primary'];
$intervalLabel = fn(int $m) => $m === 0 ? t('health.interval_off') : ($m < 60 ? sprintf(t('health.interval_min'), $m) : ($m < 1440 ? sprintf(t('health.interval_hours'), $m / 60) : t('health.interval_daily')));
$problems = count(array_filter($checks, fn($c) => $c['interval'] > 0 && in_array($c['status'], ['warn', 'fail'], true)));
?>
<form method="POST" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">

<div class="card">
    <?php echo uiPageHeaderOpen('fa-heart-pulse', t('health.header_title'), 'healthHelpBox'); ?>
<span class="badge <?php echo $problems ? 'badge-danger' : 'badge-success'; ?> u-ml-8 u-fs-11">
                <?php echo $problems ? $h(sprintf(t('health.sum_state_problems'), $problems)) : t('health.sum_state_ok'); ?>
            </span>
<?php echo uiPageHeaderActions(); ?>

<?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('healthHelpBox', t('health.help_title')); ?>
        <?php echo t('health.help_body'); ?>
<?php echo uiHelpBoxClose(); ?>

    <?php echo uiTableOpen([
        t('health.col_check'),
        t('health.col_status'),
        t('health.col_result'),
        t('health.col_checked'),
        t('health.col_interval'),
    ]); ?>
                <?php foreach ($checks as $key => $c): ?>
                    <tr>
                        <td><strong><?php echo $h(t('health.check_' . $key)); ?></strong><br><span class="u-muted u-fs-11"><?php echo $h(t('health.desc_' . $key)); ?></span></td>
                        <td>
                            <?php if ($c['interval'] === 0): ?>
                                <span class="badge badge-primary"><?php echo t('health.interval_off'); ?></span>
                            <?php else: ?>
                                <span class="badge <?php echo $badge[$c['status']] ?? 'badge-primary'; ?>"><?php echo $h(t('health.status_' . $c['status'])); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="u-fs-12"><?php echo $h($c['message']); ?></td>
                        <td class="u-fs-12 u-muted u-nowrap"><?php echo $c['checked_at'] ? $h(date('d.m H:i', strtotime($c['checked_at']))) : '—'; ?></td>
                        <td>
                            <select name="interval[<?php echo $h($key); ?>]" class="form-control u-minw-120">
                                <?php foreach (HealthChecks::INTERVALS as $m): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $c['interval'] === $m ? 'selected' : ''; ?>><?php echo $h($intervalLabel($m)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php echo uiTableClose(); ?>
    <div class="u-p-12-20 u-d-flex u-gap-8 u-wrap u-ai-center">
        <button type="submit" name="save_health" value="1" class="btn btn-primary"><i class="fas fa-check"></i> <?php echo t('health.save'); ?></button>
        <button type="submit" name="run_all" value="1" class="btn btn-secondary"><i class="fas fa-rotate"></i> <?php echo t('health.run_now'); ?></button>
        <?php if ($runQueued): ?><span class="u-muted u-fs-12"><i class="fas fa-hourglass-half"></i> <?php echo t('health.run_queued'); ?></span><?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-envelope u-primary"></i> <?php echo t('health.alarms_title'); ?></div>
    </div>
    <div class="u-card-pad">
        <p class="u-muted u-fs-12 u-mt-0"><?php echo t('health.alarms_help'); ?></p>
        <div class="form-group">
            <label class="form-label"><?php echo t('health.recipients'); ?></label>
            <?php if ($users === []): ?>
                <p class="u-muted u-fs-12"><?php echo t('health.no_users_with_email'); ?></p>
            <?php endif; ?>
            <?php foreach ($users as $u): ?>
                <label class="u-check-label-6" style="display: block;">
                    <input type="checkbox" name="health_recipients[]" value="<?php echo (int) $u['id']; ?>" class="u-accent" <?php echo in_array((int) $u['id'], $recipientIds, true) ? 'checked' : ''; ?>>
                    <?php echo $h($u['full_name'] ?: $u['username']); ?> <span class="u-muted u-fs-12">· <?php echo $h($u['email']); ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <div class="u-grid-2">
            <div class="form-group">
                <label class="form-label"><?php echo t('health.extra_emails'); ?></label>
                <textarea name="health_extra_emails" class="form-control" rows="2" placeholder="it@example.com"><?php echo $h($settings['health_extra_emails']); ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('health.repeat'); ?></label>
                <select name="health_repeat_hours" class="form-control">
                    <?php foreach (HealthService::REPEAT_HOURS as $hrs): ?>
                        <option value="<?php echo $hrs; ?>" <?php echo (int) $settings['health_repeat_hours'] === $hrs ? 'selected' : ''; ?>><?php echo $h(sprintf(t('health.repeat_hours'), $hrs)); ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="u-hint u-fs-11"><?php echo t('health.repeat_help'); ?></small>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('health.quiet'); ?></label>
                <input type="text" name="health_quiet_hours" class="form-control"
                       placeholder="22:00-07:00" value="<?php echo $h($settings['health_quiet_hours']); ?>">
                <small class="u-hint u-fs-11"><?php echo t('health.quiet_help'); ?></small>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-chart-column u-primary"></i> <?php echo t('health.summary_title'); ?></div>
    </div>
    <div class="u-card-pad">
        <p class="u-muted u-fs-12 u-mt-0"><?php echo t('health.summary_help'); ?></p>
        <label class="u-check-label-6 u-mb-8" style="display: block;">
            <input type="checkbox" name="health_summary_enabled" value="1" class="u-accent" <?php echo $settings['health_summary_enabled'] === '1' ? 'checked' : ''; ?>>
            <?php echo t('health.summary_enabled'); ?>
        </label>
        <div class="form-group u-maxw-200">
            <label class="form-label"><?php echo t('health.summary_time'); ?></label>
            <input type="time" name="health_summary_time" class="form-control" value="<?php echo $h($settings['health_summary_time']); ?>">
        </div>
        <div class="u-d-flex u-gap-8 u-wrap">
            <button type="submit" name="save_health" value="1" class="btn btn-primary"><i class="fas fa-check"></i> <?php echo t('health.save'); ?></button>
            <button type="submit" name="send_summary" value="1" class="btn btn-secondary"><i class="fas fa-paper-plane"></i> <?php echo t('health.send_summary'); ?></button>
        </div>
        <small class="u-hint u-fs-11"><?php echo t('health.templates_hint'); ?></small>
    </div>
</div>
</form>
