<div class="card page-header-card">
    <?php echo uiPageHeaderOpen('fa-mug-hot', t('pause_reports.header_title'), 'pauseReportHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>
<?php require dirname(__DIR__, 2) . '/export_buttons.php'; ?>
            
<?php echo uiPageHeaderClose(); ?>

        <?php echo uiHelpBoxOpen('pauseReportHelpBox', t('pause_reports.help_title')); ?>
        <?php echo t('pause_reports.help_body'); ?>
<?php echo uiHelpBoxClose(); ?>
</div>

<!-- Statistics Overview Cards -->
<div class="u-d-grid u-gtc-repeat-4-1fr u-gap-20 u-mb-20">
    <div class="card u-mb-0">
        <div class="u-muted u-fs-13 u-fw-600"><?php echo t('pause_reports.stat_total_breaks'); ?></div>
        <div class="u-fs-28 u-fw-800 u-mt-6 u-primary">
            <?php echo $total_breaks; ?>
        </div>
        <?php if ($active_breaks > 0): ?>
            <div class="u-fs-12 u-warning u-mt-4 u-fw-600">
                <i class="fas fa-running"></i> <?php echo sprintf(t('pause_reports.stat_active_now'), $active_breaks); ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card u-mb-0">
        <div class="u-muted u-fs-13 u-fw-600"><?php echo t('pause_reports.stat_total_duration'); ?></div>
        <div class="u-fs-28 u-fw-800 u-mt-6 u-warning">
            <?php echo PauseReportRepository::formatSeconds($total_sec); ?>
        </div>
    </div>

    <div class="card u-mb-0">
        <div class="u-muted u-fs-13 u-fw-600"><?php echo t('pause_reports.stat_avg_duration'); ?></div>
        <div class="u-fs-28 u-fw-700 u-mt-8 u-c-info">
            <?php echo PauseReportRepository::formatSeconds($avg_sec); ?>
        </div>
    </div>

    <div class="card u-mb-0">
        <div class="u-muted u-fs-13 u-fw-600"><?php echo t('pause_reports.stat_top_reason'); ?></div>
        <div class="u-fs-24 u-fw-700 u-mt-8 u-success u-nowrap u-of-hidden u-text-overflow-ellipsis">
            <?php echo htmlspecialchars($top_reason); ?>
        </div>
    </div>
</div>

<div class="card">

    <!-- Filter Form -->
    <form method="GET" autocomplete="off" class="u-d-flex u-wrap u-gap-12 u-mb-20 u-ai-flex-end">
        <div class="u-d-flex u-col u-gap-4">
            <label class="u-muted u-fw-600 u-fs-12"><?php echo t('pause_reports.field_start_date'); ?></label>
            <input type="date" name="start_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($start_date); ?>">
        </div>

        <div class="u-d-flex u-col u-gap-4">
            <label class="u-muted u-fw-600 u-fs-12"><?php echo t('pause_reports.field_end_date'); ?></label>
            <input type="date" name="end_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($end_date); ?>">
        </div>

        <?php if ($can_view_all): ?>
            <div class="u-d-flex u-col u-gap-4">
                <label class="u-muted u-fw-600 u-fs-12"><?php echo t('pause_reports.field_agent_filter'); ?></label>
                <select name="agent_filter" class="form-control form-control-sm u-minw-180">
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

        <div class="u-d-flex u-col u-gap-4">
            <label class="u-muted u-fw-600 u-fs-12"><?php echo t('pause_reports.field_reason_filter'); ?></label>
            <select name="reason_filter" class="form-control form-control-sm u-minw-160">
                <option value=""><?php echo t('pause_reports.all_reasons'); ?></option>
                <?php foreach ($reasons_list as $r):
                    $sel = $reason_filter === $r ? 'selected' : '';
                ?>
                    <option value="<?php echo htmlspecialchars($r); ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($r); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-icon btn-primary btn-sm" title="<?php echo t('pause_reports.filter_tooltip'); ?>"><i class="fas fa-filter"></i></button>
        <a href="/pause-reports" class="btn btn-icon btn-secondary btn-sm" title="<?php echo t('pause_reports.reset_tooltip'); ?>"><i class="fas fa-undo"></i></a>
    </form>

    <!-- Detailed Logs Table -->
    <?php echo uiTableOpen([
        t('pause_reports.col_id'),
        t('pause_reports.col_agent'),
        t('pause_reports.col_extension'),
        t('pause_reports.col_reason'),
        t('pause_reports.col_start'),
        t('pause_reports.col_end'),
        t('pause_reports.col_total_duration'),
        t('pause_reports.col_status'),
    ]); ?>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="8" class="u-text-center u-p-30 u-muted">
                            <i class="fas fa-coffee u-fs-32 u-mb-12" style="display: block;"></i>
                            <?php echo t('pause_reports.empty'); ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $l):
                        $is_active = ($l['status'] === 'PAUSED');
                    ?>
                        <tr>
                            <td>#<?php echo $l['id']; ?></td>
                            <td class="u-fw-700"><?php echo htmlspecialchars($l['agent_name'] ?: '-'); ?></td>
                            <td>
                                <span class="badge badge-info"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($l['agent_extension']); ?></span>
                            </td>
                            <td>
                                <span class="badge badge-warning u-fs-13">
                                    <i class="fas fa-coffee"></i> <?php echo htmlspecialchars($l['pause_reason']); ?>
                                </span>
                            </td>
                            <td><?php echo date('d.m.Y H:i:s', strtotime($l['start_time'])); ?></td>
                            <td>
                                <?php echo $l['end_time'] ? date('d.m.Y H:i:s', strtotime($l['end_time'])) : '<span class="u-warning u-fw-700">' . t('pause_reports.ongoing') . '</span>'; ?>
                            </td>
                            <td class="u-fw-700">
                                <?php echo PauseReportRepository::formatSeconds($l['duration_sec']); ?>
                            </td>
                            <td>
                                <?php if ($is_active): ?>
                                    <span class="badge badge-warning u-animation-pulse-1-5s-infinite"><i class="fas fa-spinner fa-spin"></i> <?php echo t('pause_reports.status_active'); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-success"><i class="fas fa-check"></i> <?php echo t('pause_reports.status_completed'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php echo uiTableClose(); ?>
</div>
