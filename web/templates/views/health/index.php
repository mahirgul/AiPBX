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
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-heart-pulse u-primary"></i> <?php echo t('health.header_title'); ?>
            <span class="badge <?php echo $problems ? 'badge-danger' : 'badge-success'; ?>" style="margin-left: 8px; font-size: 11px;">
                <?php echo $problems ? $h(sprintf(t('health.sum_state_problems'), $problems)) : t('health.sum_state_ok'); ?>
            </span>
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('healthHelpBox')" title="<?php echo t('common.module_guide'); ?>">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>
    <div class="module-help-box" id="healthHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('health.help_title'); ?></h4>
        <?php echo t('health.help_body'); ?>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?php echo t('health.col_check'); ?></th>
                    <th><?php echo t('health.col_status'); ?></th>
                    <th><?php echo t('health.col_result'); ?></th>
                    <th><?php echo t('health.col_checked'); ?></th>
                    <th><?php echo t('health.col_interval'); ?></th>
                </tr>
            </thead>
            <tbody>
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
                        <td class="u-fs-12 u-muted" style="white-space: nowrap;"><?php echo $c['checked_at'] ? $h(date('d.m H:i', strtotime($c['checked_at']))) : '—'; ?></td>
                        <td>
                            <select name="interval[<?php echo $h($key); ?>]" class="form-control" style="min-width: 120px;">
                                <?php foreach (HealthChecks::INTERVALS as $m): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $c['interval'] === $m ? 'selected' : ''; ?>><?php echo $h($intervalLabel($m)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div style="padding: 12px 20px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
        <button type="submit" name="save_health" value="1" class="btn btn-primary"><i class="fas fa-check"></i> <?php echo t('health.save'); ?></button>
        <button type="submit" name="run_all" value="1" class="btn btn-secondary"><i class="fas fa-rotate"></i> <?php echo t('health.run_now'); ?></button>
        <?php if ($runQueued): ?><span class="u-muted u-fs-12"><i class="fas fa-hourglass-half"></i> <?php echo t('health.run_queued'); ?></span><?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-envelope u-primary"></i> <?php echo t('health.alarms_title'); ?></div>
    </div>
    <div style="padding: 0 20px 20px;">
        <p class="u-muted u-fs-12" style="margin-top: 0;"><?php echo t('health.alarms_help'); ?></p>
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
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-chart-column u-primary"></i> <?php echo t('health.summary_title'); ?></div>
    </div>
    <div style="padding: 0 20px 20px;">
        <p class="u-muted u-fs-12" style="margin-top: 0;"><?php echo t('health.summary_help'); ?></p>
        <label class="u-check-label-6" style="display: block; margin-bottom: 8px;">
            <input type="checkbox" name="health_summary_enabled" value="1" class="u-accent" <?php echo $settings['health_summary_enabled'] === '1' ? 'checked' : ''; ?>>
            <?php echo t('health.summary_enabled'); ?>
        </label>
        <div class="form-group" style="max-width: 200px;">
            <label class="form-label"><?php echo t('health.summary_time'); ?></label>
            <input type="time" name="health_summary_time" class="form-control" value="<?php echo $h($settings['health_summary_time']); ?>">
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <button type="submit" name="save_health" value="1" class="btn btn-primary"><i class="fas fa-check"></i> <?php echo t('health.save'); ?></button>
            <button type="submit" name="send_summary" value="1" class="btn btn-secondary"><i class="fas fa-paper-plane"></i> <?php echo t('health.send_summary'); ?></button>
        </div>
        <small class="u-hint u-fs-11"><?php echo t('health.templates_hint'); ?></small>
    </div>
</div>
</form>
