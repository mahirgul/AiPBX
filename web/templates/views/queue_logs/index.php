<?php
$formatDuration = function(int $seconds): string {
    $m = intdiv($seconds, 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d', $m, $s);
};
?>

<div class="card page-header-card">
    <?php echo uiPageHeaderOpen('fa-list-alt', t('queue_logs.header_title'), 'qlogHelpBox'); ?>
<?php if ($view_mode === 'grouped'): ?>
                <span class="badge badge-info u-fs-11 u-ml-8">
                    <i class="fas fa-route"></i> <?php echo t('queue_logs.mode_grouped'); ?>
                </span>
            <?php else: ?>
                <span class="badge badge-secondary u-fs-11 u-ml-8">
                    <i class="fas fa-stream"></i> <?php echo t('queue_logs.mode_raw'); ?>
                </span>
            <?php endif; ?>
<?php echo uiPageHeaderActions(); ?>
<button type="button" class="btn btn-outline-primary btn-sm" id="btnToggleAllJourneys" onclick="toggleAllQueueJourneys()" title="<?php echo t('queue_logs.btn_expand_all'); ?>" style="<?php echo ($view_mode !== 'grouped' || empty($parsed_logs)) ? 'display: none;' : ''; ?>">
                <i class="fas fa-layer-group"></i> <span id="toggleAllJourneysText"><?php echo t('queue_logs.btn_expand_all'); ?></span>
            </button>
            
<?php require dirname(__DIR__, 2) . '/export_buttons.php'; ?>
            <a href="/queue-logs" class="btn btn-icon btn-secondary btn-sm" title="<?php echo t('queue_logs.refresh_tooltip'); ?>"><i class="fas fa-sync-alt"></i></a>
<?php echo uiPageHeaderClose(); ?>

        <?php echo uiHelpBoxOpen('qlogHelpBox', t('queue_logs.help_title')); ?>
        <p><?php echo t('queue_logs.help_body'); ?></p>
        <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-220-1fr u-gap-8 u-mt-8">
            <div><strong>ENTERQUEUE:</strong> <?php echo t('queue_logs.help_enterqueue'); ?></div>
            <div><strong>CONNECT:</strong> <?php echo t('queue_logs.help_connect'); ?></div>
            <div><strong>ABANDON:</strong> <?php echo t('queue_logs.help_abandon'); ?></div>
        </div>
<?php echo uiHelpBoxClose(); ?>
</div>

<!-- Statistics Overview -->
<div class="u-d-grid u-gtc-repeat-auto-fit-minmax-170-1fr u-gap-16 u-mb-20">
    <div class="card u-mb-0">
        <div class="u-muted u-fs-12 u-fw-600 u-uppercase"><?php echo t('queue_logs.stat_entered'); ?></div>
        <div class="u-fs-26 u-fw-800 u-mt-4 u-primary"><?php echo $stat_total_enter; ?></div>
    </div>
    <div class="card u-mb-0">
        <div class="u-muted u-fs-12 u-fw-600 u-uppercase"><?php echo t('queue_logs.stat_connected'); ?></div>
        <div class="u-fs-26 u-fw-800 u-mt-4 u-success"><?php echo $stat_connected; ?></div>
    </div>
    <div class="card u-mb-0">
        <div class="u-muted u-fs-12 u-fw-600 u-uppercase"><?php echo t('queue_logs.stat_abandoned'); ?></div>
        <div class="u-fs-26 u-fw-800 u-mt-4 u-danger"><?php echo $stat_abandon; ?></div>
    </div>
    <div class="card u-mb-0">
        <div class="u-muted u-fs-12 u-fw-600 u-uppercase"><?php echo t('queue_logs.stat_avg_wait'); ?></div>
        <div class="u-fs-26 u-fw-800 u-mt-4 u-warning"><?php echo $avg_holdtime; ?> <?php echo t('common.sec_short'); ?></div>
    </div>
    <div class="card u-mb-0">
        <div class="u-muted u-fs-12 u-fw-600 u-uppercase"><?php echo t('queue_logs.stat_ring_no_answer'); ?></div>
        <div class="u-fs-28 u-fw-700 u-mt-4 u-c-ec4899"><?php echo $stat_ring_no_answer; ?></div>
    </div>
</div>

<div class="card">

    <!-- Filter Form -->
    <form method="GET" autocomplete="off" class="u-d-flex u-gap-12 u-mb-20 u-wrap u-ai-center">
        <select name="date_range" class="form-control form-control-sm u-w-auto" onchange="this.form.submit()">
            <option value="today" <?php echo $date_filter === 'today' ? 'selected' : ''; ?>><?php echo t('queue_logs.range_today'); ?></option>
            <option value="yesterday" <?php echo $date_filter === 'yesterday' ? 'selected' : ''; ?>><?php echo t('queue_logs.range_yesterday'); ?></option>
            <option value="week" <?php echo $date_filter === 'week' ? 'selected' : ''; ?>><?php echo t('queue_logs.range_week'); ?></option>
            <option value="month" <?php echo $date_filter === 'month' ? 'selected' : ''; ?>><?php echo t('queue_logs.range_month'); ?></option>
        </select>

        <select name="view_mode" class="form-control form-control-sm u-w-auto u-fw-700 u-primary" onchange="this.form.submit()">
            <option value="grouped" <?php echo $view_mode === 'grouped' ? 'selected' : ''; ?>>
                <i class="fas fa-layer-group"></i> <?php echo t('queue_logs.mode_grouped'); ?>
            </option>
            <option value="raw" <?php echo $view_mode === 'raw' ? 'selected' : ''; ?>>
                <i class="fas fa-stream"></i> <?php echo t('queue_logs.mode_raw'); ?>
            </option>
        </select>

        <select name="event" class="form-control form-control-sm u-w-auto" onchange="this.form.submit()">
            <option value=""><?php echo t('queue_logs.all_events'); ?></option>
            <option value="ENTERQUEUE" <?php echo $event_filter === 'ENTERQUEUE' ? 'selected' : ''; ?>><?php echo t('queue_logs.event_enterqueue'); ?></option>
            <option value="CONNECT" <?php echo $event_filter === 'CONNECT' ? 'selected' : ''; ?>><?php echo t('queue_logs.event_connect'); ?></option>
            <option value="COMPLETECALLER" <?php echo $event_filter === 'COMPLETECALLER' ? 'selected' : ''; ?>><?php echo t('queue_logs.event_completecaller'); ?></option>
            <option value="COMPLETEAGENT" <?php echo $event_filter === 'COMPLETEAGENT' ? 'selected' : ''; ?>><?php echo t('queue_logs.event_completeagent'); ?></option>
            <option value="ABANDON" <?php echo $event_filter === 'ABANDON' ? 'selected' : ''; ?>><?php echo t('queue_logs.event_abandon'); ?></option>
            <option value="RINGNOANSWER" <?php echo $event_filter === 'RINGNOANSWER' ? 'selected' : ''; ?>><?php echo t('queue_logs.event_ringnoanswer'); ?></option>
            <option value="ADDMEMBER" <?php echo $event_filter === 'ADDMEMBER' ? 'selected' : ''; ?>><?php echo t('queue_logs.event_addmember'); ?></option>
            <option value="REMOVEMEMBER" <?php echo $event_filter === 'REMOVEMEMBER' ? 'selected' : ''; ?>><?php echo t('queue_logs.event_removemember'); ?></option>
        </select>

        <select name="agent" class="form-control form-control-sm u-w-auto" onchange="this.form.submit()">
            <option value=""><?php echo t('queue_logs.all_agents'); ?></option>
            <?php foreach ($agent_map as $ext => $name): ?>
                <option value="<?php echo htmlspecialchars($ext); ?>" <?php echo $agent_filter === (string)$ext ? 'selected' : ''; ?>>
                    <?php echo t('queue_logs.ext_prefix'); ?> <?php echo htmlspecialchars($ext); ?> - <?php echo htmlspecialchars($name); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div class="u-relative u-maxw-220">
            <i class="fas fa-search u-pos-absolute u-left-10 u-top-50pc u-transform-translatey-50pc u-muted u-fs-12 u-pointer-events-none"></i>
            <input type="text" name="search" class="form-control form-control-sm u-pl-28" placeholder="<?php echo t('queue_logs.search_placeholder'); ?>" value="<?php echo htmlspecialchars($search_query); ?>">
        </div>
        <input type="hidden" name="size" value="<?php echo (int)$page_size; ?>">

        <button type="submit" class="btn btn-icon btn-primary btn-sm" title="<?php echo t('queue_logs.filter_tooltip'); ?>"><i class="fas fa-filter"></i></button>
        <?php if (!empty($event_filter) || !empty($agent_filter) || !empty($search_query) || $date_filter !== 'today' || $view_mode !== 'grouped'): ?>
            <a href="/queue-logs" class="btn btn-icon btn-secondary btn-sm" title="<?php echo t('common.reset'); ?>"><i class="fas fa-undo"></i></a>
        <?php endif; ?>
    </form>

    <div class="table-responsive">
        <?php
            $page_size = $page_size;
            require dirname(__DIR__, 2) . '/pagination_controls.php';
        ?>
        <?php if ($view_mode === 'grouped'): ?>
            <!-- ======================================================== -->
            <!-- GROUPED VIEW: 1 CALL = 1 ROW (WITH THE CALL JOURNEY)     -->
            <!-- ======================================================== -->
            <?php echo uiTableOpen([
                ['label' => '#', 'class' => 'col-hide-mobile u-w-45'],
                t('queue_logs.col_date'),
                t('queue_logs.col_caller'),
                t('queue_logs.col_queue'),
                t('queue_logs.col_status'),
                t('queue_logs.col_answering_agent'),
                t('queue_logs.col_hold_time'),
                t('queue_logs.col_talk_time'),
                t('queue_logs.col_journey'),
                ['label' => t('queue_logs.col_call_id'), 'class' => 'u-text-right'],
            ], ['search' => false]); ?>
                    <?php if (empty($parsed_logs)): ?>
                        <tr>
                            <td colspan="10" class="u-text-center u-muted u-p-30">
                                <i class="fas fa-search-minus u-fs-32 u-mb-8" style="display: block;"></i>
                                <?php echo t('queue_logs.empty'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($parsed_logs as $idx => $c): ?>
                            <tr>
                                <td class="col-hide-mobile u-muted u-fw-600 u-fs-12">#<?php echo ($idx + 1); ?></td>
                                <td class="u-nowrap u-fw-600"><?php echo $c['datetime']; ?></td>
                                <td>
                                    <strong class="u-primary">
                                        <i class="fas fa-phone-alt u-fs-11 u-mr-4 u-opacity-0-7"></i>
                                        <?php echo htmlspecialchars($c['caller_num']); ?>
                                    </strong>
                                </td>
                                <td><span class="badge badge-info"><?php echo htmlspecialchars($c['queue_name']); ?></span></td>
                                <td>
                                    <span class="badge <?php echo $c['status_badge']; ?> u-fs-11">
                                        <?php echo htmlspecialchars($c['status_label']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($c['agent_ext'])): ?>
                                        <strong><?php echo htmlspecialchars($c['agent_ext']); ?></strong>
                                        <?php if (!empty($c['agent_name'])): ?>
                                            <small class="u-muted u-fs-11" style="display: block;"><?php echo htmlspecialchars($c['agent_name']); ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="u-muted u-fs-12">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="u-mono u-fs-12 u-fw-600 u-warning">
                                        <i class="fas fa-hourglass-half u-fs-10 u-mr-3"></i>
                                        <?php echo $formatDuration($c['hold_sec']); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($c['talk_sec'] > 0): ?>
                                        <div class="u-mono u-fs-13 u-fw-700 u-success">
                                            <i class="fas fa-phone-volume u-fs-11 u-mr-4"></i>
                                            <?php echo $formatDuration($c['talk_sec']); ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="u-muted u-fs-12 u-mono">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-outline-primary btn-sm queue-journey-btn u-r-8 u-fw-700" id="journey-btn-<?php echo $idx; ?>" onclick="toggleQueueJourney('<?php echo $idx; ?>')" title="<?php echo t('queue_logs.journey_title'); ?>">
                                        <i class="fas fa-route"></i> <?php echo sprintf(t('queue_logs.journey_steps'), $c['steps_count']); ?> <i class="fas fa-chevron-down queue-journey-icon u-fs-10 u-transition-transform-0-2s"></i>
                                    </button>
                                </td>
                                <td class="u-text-right u-nowrap">
                                    <?php if (!empty($c['has_recording']) && !empty($c['cdr_id'])): ?>
                                        <?php if ($can_listen_all || (!empty($user_ext) && $c['agent_ext'] === $user_ext)): ?>
                                            <button type="button" class="btn btn-icon btn-secondary btn-sm" onclick="playCdrAudio(<?php echo (int) $c['cdr_id']; ?>, '<?php echo htmlspecialchars(addslashes($c['caller_num']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars($c['datetime'], ENT_QUOTES); ?>')" title="<?php echo t('cdr_reports.listen_tooltip'); ?>"><i class="fas fa-play"></i></button>
                                            <a href="/api/cc_audio.php?id=<?php echo (int) $c['cdr_id']; ?>&download=1" class="btn btn-icon btn-secondary btn-sm" title="<?php echo t('cdr_reports.download_tooltip'); ?>"><i class="fas fa-download"></i></a>
                                        <?php else: ?>
                                            <span class="badge badge-secondary u-fs-10" title="<?php echo t('cdr_reports.locked_tooltip'); ?>"><i class="fas fa-lock"></i></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <code style="font-size: 11px; color: var(--text-muted); background: var(--bg-hover, rgba(0,0,0,0.03)); padding: 2px 6px; border-radius: 4px;" title="Call ID: <?php echo htmlspecialchars($c['call_id']); ?>">
                                        <?php echo htmlspecialchars(strlen($c['call_id']) > 16 ? substr($c['call_id'], 0, 16) . '…' : $c['call_id']); ?>
                                    </code>
                                </td>
                            </tr>

                            <!-- Expandable call journey (timeline) row -->
                            <tr id="journey-row-<?php echo $idx; ?>" class="queue-journey-row" style="display: none;">
                                <td class="u-p-16-20 u-bg-rgba-0-242-254-0-02 u-border-bottom-2-solid-border-color" colspan="10">
                                    <div class="u-bg-bg-card u-bd-1-solid-border-color u-r-12 u-p-16-20 u-box-shadow-0-2-8-rgba-0-0-0-0-03">
                                        <!-- Timeline header & duration summary -->
                                        <div class="u-d-flex u-jc-space-between u-ai-center u-mb-16 u-pb-8 u-border-bottom-1-solid-border-color u-wrap u-gap-8">
                                            <div class="u-fs-13 u-fw-700 u-text-main u-d-flex u-ai-center u-gap-8">
                                                <span class="u-w-24 u-h-24 u-r-8 u-bg-rgba-0-242-254-0-12 u-primary u-d-inline-flex u-ai-center u-jc-center u-fs-12">
                                                    <i class="fas fa-route"></i>
                                                </span>
                                                <span><?php echo t('queue_logs.journey_title'); ?></span>
                                                <span class="badge badge-info u-fs-11"><?php echo sprintf(t('queue_logs.journey_steps'), $c['steps_count']); ?></span>
                                                <code class="u-fs-11 u-muted u-bg-bg-input u-p-2-8 u-r-4"><?php echo htmlspecialchars($c['call_id']); ?></code>
                                            </div>
                                            <div class="u-fs-12 u-muted u-d-flex u-ai-center u-gap-10">
                                                <span><?php echo t('queue_logs.journey_wait'); ?>: <strong class="u-warning"><?php echo $formatDuration($c['hold_sec']); ?></strong></span>
                                                <span>•</span>
                                                <span><?php echo t('queue_logs.journey_talk'); ?>: <strong class="u-success"><?php echo $formatDuration($c['talk_sec']); ?></strong></span>
                                                <span>•</span>
                                                <span><?php echo t('queue_logs.journey_total'); ?>: <strong class="u-primary"><?php echo $formatDuration($c['total_sec']); ?></strong></span>
                                            </div>
                                        </div>

                                        <!-- Vertical timeline -->
                                        <div class="u-relative u-pl-24 u-ml-8 u-border-left-2-dashed-border-color">
                                            <?php foreach ($c['steps'] as $step): ?>
                                                <div class="u-relative u-mb-10">
                                                    <!-- Node dot -->
                                                    <div style="position: absolute; left: -31px; top: 6px; width: 14px; height: 14px; border-radius: 50%; background: var(--bg-card); border: 2px solid <?php echo $step['node_color']; ?>; display: flex; align-items: center; justify-content: center;">
                                                        <div style="width: 6px; height: 6px; border-radius: 50%; background: <?php echo $step['node_color']; ?>;"></div>
                                                    </div>

                                                    <!-- Step card -->
                                                    <div class="u-d-flex u-jc-space-between u-ai-center u-gap-12 u-bg-bg-input u-p-8-12 u-r-8 u-bd-1-solid-border-color u-wrap">
                                                        <div class="u-d-flex u-ai-center u-gap-10 u-wrap">
                                                            <span class="u-mono u-fs-11 u-fw-700 u-muted u-bg-bg-card u-p-2-8 u-r-4 u-bd-1-solid-border-color">
                                                                <?php echo $step['time']; ?>
                                                            </span>
                                                            <span class="u-fs-13 u-fw-700 u-text-main">
                                                                <i class="fas <?php echo $step['icon']; ?> u-primary u-fs-11 u-mr-4"></i>
                                                                <?php echo $step['title']; ?>
                                                            </span>
                                                            <span class="badge <?php echo $step['badge']; ?> u-fs-10 u-p-2-8">
                                                                <?php echo htmlspecialchars($step['event']); ?>
                                                            </span>
                                                            <span class="u-muted u-fs-12">
                                                                <?php echo $step['detail']; ?>
                                                            </span>
                                                        </div>
                                                        <div class="u-fs-11 u-muted u-mono">
                                                            #<?php echo $step['id']; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php echo uiTableClose(); ?>
        <?php else: ?>
            <!-- ======================================================== -->
            <!-- RAW VIEW: LISTING BY RAW EVENT                           -->
            <!-- ======================================================== -->
            <?php echo uiTableOpen([
                ['label' => '#', 'class' => 'col-hide-mobile u-w-45'],
                t('queue_logs.col_date'),
                t('queue_logs.col_event'),
                t('queue_logs.col_queue'),
                ['label' => t('queue_logs.col_agent'), 'class' => 'col-hide-mobile'],
                ['label' => t('queue_logs.col_call_id'), 'class' => 'col-hide-mobile'],
                t('queue_logs.col_detail'),
            ], ['search' => false]); ?>
                    <?php if (empty($parsed_logs)): ?>
                        <tr>
                            <td colspan="7" class="u-text-center u-muted u-p-30">
                                <i class="fas fa-search-minus u-fs-32 u-mb-8" style="display: block;"></i>
                                <?php echo t('queue_logs.empty'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php
                            $queueLogBadgeMap = [
                                'CONNECT' => 'success', 'COMPLETECALLER' => 'success', 'COMPLETEAGENT' => 'success',
                                'ABANDON' => 'danger', 'EXITWITHTIMEOUT' => 'danger',
                                'RINGNOANSWER' => 'warning',
                            ];
                        ?>
                        <?php foreach ($parsed_logs as $idx => $log): ?>
                            <tr>
                                <td class="col-hide-mobile u-muted u-fs-12">#<?php echo ($idx + 1); ?></td>
                                <td class="u-nowrap"><?php echo $log['datetime']; ?></td>
                                <td>
                                    <?php echo uiStatusBadge($log['event'], $queueLogBadgeMap, 'info'); ?>
                                </td>
                                <td><span class="badge badge-info"><?php echo htmlspecialchars($log['queue_name']); ?></span></td>
                                <td class="col-hide-mobile">
                                    <?php if (!empty($log['agent_ext'])): ?>
                                        <strong><?php echo htmlspecialchars($log['agent_ext']); ?></strong> - <?php echo htmlspecialchars($log['agent_name']); ?>
                                    <?php else: ?>
                                        <span class="u-muted"><?php echo htmlspecialchars($log['agent']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-hide-mobile u-mono u-fs-12 u-muted"><?php echo htmlspecialchars($log['call_id']); ?></td>
                                <td class="u-fs-13">
                                    <?php
                                    if ($log['event'] === 'CONNECT') {
                                        echo t('queue_logs.detail_wait_time') . ": <strong>" . htmlspecialchars($log['data1']) . " " . t('common.sec_short') . "</strong> | " . t('queue_logs.detail_ring') . ": " . htmlspecialchars($log['data3']) . " sn";
                                    } elseif ($log['event'] === 'COMPLETECALLER' || $log['event'] === 'COMPLETEAGENT') {
                                        echo t('queue_logs.detail_wait') . ": " . htmlspecialchars($log['data1']) . " " . t('common.sec_short') . " | " . t('queue_logs.detail_talk_time') . ": <strong>" . htmlspecialchars($log['data2']) . " sn</strong> (" . t('queue_logs.detail_queue_pos') . ": " . htmlspecialchars($log['data3']) . ")";
                                    } elseif ($log['event'] === 'ABANDON') {
                                        echo t('queue_logs.detail_abandoned_pos') . ": " . htmlspecialchars($log['data1']) . " | " . t('queue_logs.detail_wait_time_short') . ": <strong>" . htmlspecialchars($log['data3']) . " sn</strong>";
                                    } elseif ($log['event'] === 'RINGNOANSWER') {
                                        echo sprintf(t('queue_logs.detail_rang_no_answer'), htmlspecialchars($log['data1']));
                                    } elseif ($log['event'] === 'ENTERQUEUE') {
                                        echo t('queue_logs.detail_caller_number') . ": <strong>" . htmlspecialchars($log['data2']) . "</strong>";
                                    } else {
                                        echo htmlspecialchars(t('queue_logs.detail_raw_data') . ": {$log['data1']} {$log['data2']} {$log['data3']}");
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php echo uiTableClose(); ?>
        <?php endif; ?>
    </div>

    <?php
        $page = $current_page;
        $page_size = $page_size;
        $total_pages = $total_pages;
        $total_rows = $total_records;
        require dirname(__DIR__, 2) . '/pagination.php';
    ?>
</div>

<?php require dirname(__DIR__, 2) . '/cdr_audio_player.php'; ?>

<script>
window.QUEUE_LOGS_I18N = {
    collapse_all: <?php echo json_encode(t('queue_logs.btn_collapse_all')); ?>,
    expand_all: <?php echo json_encode(t('queue_logs.btn_expand_all')); ?>
};
</script>
<script src="<?php echo asset('/assets/js/queue_logs.js'); ?>"></script>
