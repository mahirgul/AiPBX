<!-- Statistics Overview Cards -->
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 20px;">
    <div class="card" style="margin-bottom: 0;">
        <div style="color: var(--text-muted); font-size: 13px; font-weight: 600;"><?php echo t('pause_reports.stat_total_breaks'); ?></div>
        <div style="font-size: 28px; font-weight: 800; margin-top: 6px; color: var(--primary);">
            <?php echo $total_breaks; ?>
        </div>
        <?php if ($active_breaks > 0): ?>
            <div style="font-size: 12px; color: var(--warning); margin-top: 4px; font-weight: 600;">
                <i class="fas fa-running"></i> <?php echo sprintf(t('pause_reports.stat_active_now'), $active_breaks); ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card" style="margin-bottom: 0;">
        <div style="color: var(--text-muted); font-size: 13px; font-weight: 600;"><?php echo t('pause_reports.stat_total_duration'); ?></div>
        <div style="font-size: 28px; font-weight: 800; margin-top: 6px; color: var(--warning);">
            <?php echo PauseReportRepository::formatSeconds($total_sec); ?>
        </div>
    </div>

    <div class="card" style="margin-bottom: 0;">
        <div style="color: var(--text-muted); font-size: 13px; font-weight: 600;"><?php echo t('pause_reports.stat_avg_duration'); ?></div>
        <div style="font-size: 28px; font-weight: 800; margin-top: 6px; color: var(--info);">
            <?php echo PauseReportRepository::formatSeconds($avg_sec); ?>
        </div>
    </div>

    <div class="card" style="margin-bottom: 0;">
        <div style="color: var(--text-muted); font-size: 13px; font-weight: 600;"><?php echo t('pause_reports.stat_top_reason'); ?></div>
        <div style="font-size: 22px; font-weight: 800; margin-top: 6px; color: var(--success); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
            <?php echo htmlspecialchars($top_reason); ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-mug-hot" style="color: var(--warning);"></i> <?php echo t('pause_reports.header_title'); ?>
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('pauseReportHelpBox')" title="Modül Rehberi">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="pauseReportHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('pause_reports.help_title'); ?></h4>
        <?php echo t('pause_reports.help_body'); ?>
    </div>

    <!-- Filter Form -->
    <form method="GET" autocomplete="off" style="display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; align-items: flex-end;">
        <div style="display: flex; flex-direction: column; gap: 4px;">
            <label style="font-size: 12px; color: var(--text-muted); font-weight: 600;"><?php echo t('pause_reports.field_start_date'); ?></label>
            <input type="date" name="start_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($start_date); ?>">
        </div>

        <div style="display: flex; flex-direction: column; gap: 4px;">
            <label style="font-size: 12px; color: var(--text-muted); font-weight: 600;"><?php echo t('pause_reports.field_end_date'); ?></label>
            <input type="date" name="end_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($end_date); ?>">
        </div>

        <?php if ($can_view_all): ?>
            <div style="display: flex; flex-direction: column; gap: 4px;">
                <label style="font-size: 12px; color: var(--text-muted); font-weight: 600;"><?php echo t('pause_reports.field_agent_filter'); ?></label>
                <select name="agent_filter" class="form-control form-control-sm" style="min-width: 180px;">
                    <option value=""><?php echo t('pause_reports.all_agents'); ?></option>
                    <?php foreach ($agents as $a):
                        $sel = $agent_filter === $a['agent_extension'] ? 'selected' : '';
                    ?>
                        <option value="<?php echo htmlspecialchars($a['agent_extension']); ?>" <?php echo $sel; ?>>
                            <?php echo htmlspecialchars($a['agent_name'] ? "{$a['agent_name']} ({$a['agent_extension']})" : $a['agent_extension']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div style="display: flex; flex-direction: column; gap: 4px;">
            <label style="font-size: 12px; color: var(--text-muted); font-weight: 600;"><?php echo t('pause_reports.field_reason_filter'); ?></label>
            <select name="reason_filter" class="form-control form-control-sm" style="min-width: 160px;">
                <option value=""><?php echo t('pause_reports.all_reasons'); ?></option>
                <?php foreach ($reasons_list as $r):
                    $sel = $reason_filter === $r ? 'selected' : '';
                ?>
                    <option value="<?php echo htmlspecialchars($r); ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($r); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-primary btn-sm" title="<?php echo t('pause_reports.filter_tooltip'); ?>"><i class="fas fa-filter"></i></button>
        <a href="/pause-reports" class="btn btn-secondary btn-sm" title="<?php echo t('pause_reports.reset_tooltip'); ?>"><i class="fas fa-undo"></i></a>
    </form>

    <!-- Detailed Logs Table -->
    <div class="table-responsive">
        <table class="data-table" data-no-dt="true">
            <thead>
                <tr>
                    <th><?php echo t('pause_reports.col_id'); ?></th>
                    <th><?php echo t('pause_reports.col_agent'); ?></th>
                    <th><?php echo t('pause_reports.col_extension'); ?></th>
                    <th><?php echo t('pause_reports.col_reason'); ?></th>
                    <th><?php echo t('pause_reports.col_start'); ?></th>
                    <th><?php echo t('pause_reports.col_end'); ?></th>
                    <th><?php echo t('pause_reports.col_total_duration'); ?></th>
                    <th><?php echo t('pause_reports.col_status'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 30px; color: var(--text-muted);">
                            <i class="fas fa-coffee" style="font-size: 32px; margin-bottom: 10px; display: block;"></i>
                            <?php echo t('pause_reports.empty'); ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $l):
                        $is_active = ($l['status'] === 'PAUSED');
                    ?>
                        <tr>
                            <td>#<?php echo $l['id']; ?></td>
                            <td style="font-weight: 700;"><?php echo htmlspecialchars($l['agent_name'] ?: '-'); ?></td>
                            <td>
                                <span class="badge badge-info"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($l['agent_extension']); ?></span>
                            </td>
                            <td>
                                <span class="badge badge-warning" style="font-size: 13px;">
                                    <i class="fas fa-coffee"></i> <?php echo htmlspecialchars($l['pause_reason']); ?>
                                </span>
                            </td>
                            <td><?php echo date('d.m.Y H:i:s', strtotime($l['start_time'])); ?></td>
                            <td>
                                <?php echo $l['end_time'] ? date('d.m.Y H:i:s', strtotime($l['end_time'])) : '<span style="color: var(--warning); font-weight:700;">' . t('pause_reports.ongoing') . '</span>'; ?>
                            </td>
                            <td style="font-weight: 700;">
                                <?php echo PauseReportRepository::formatSeconds($l['duration_sec']); ?>
                            </td>
                            <td>
                                <?php if ($is_active): ?>
                                    <span class="badge badge-warning" style="animation: pulse 1.5s infinite;"><i class="fas fa-spinner fa-spin"></i> <?php echo t('pause_reports.status_active'); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-success"><i class="fas fa-check"></i> <?php echo t('pause_reports.status_completed'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
