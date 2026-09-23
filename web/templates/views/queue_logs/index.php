<!-- Statistics Overview -->
<div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-bottom: 20px;">
    <div class="card" style="margin-bottom: 0;">
        <div style="color: var(--text-muted); font-size: 12px; font-weight: 600; text-transform: uppercase;"><?php echo t('queue_logs.stat_entered'); ?></div>
        <div style="font-size: 26px; font-weight: 800; margin-top: 4px; color: var(--primary);"><?php echo $stat_total_enter; ?></div>
    </div>
    <div class="card" style="margin-bottom: 0;">
        <div style="color: var(--text-muted); font-size: 12px; font-weight: 600; text-transform: uppercase;"><?php echo t('queue_logs.stat_connected'); ?></div>
        <div style="font-size: 26px; font-weight: 800; margin-top: 4px; color: var(--success);"><?php echo $stat_connected; ?></div>
    </div>
    <div class="card" style="margin-bottom: 0;">
        <div style="color: var(--text-muted); font-size: 12px; font-weight: 600; text-transform: uppercase;"><?php echo t('queue_logs.stat_abandoned'); ?></div>
        <div style="font-size: 26px; font-weight: 800; margin-top: 4px; color: var(--danger);"><?php echo $stat_abandon; ?></div>
    </div>
    <div class="card" style="margin-bottom: 0;">
        <div style="color: var(--text-muted); font-size: 12px; font-weight: 600; text-transform: uppercase;"><?php echo t('queue_logs.stat_avg_wait'); ?></div>
        <div style="font-size: 26px; font-weight: 800; margin-top: 4px; color: var(--warning);"><?php echo $avg_holdtime; ?> sn</div>
    </div>
    <div class="card" style="margin-bottom: 0;">
        <div style="color: var(--text-muted); font-size: 12px; font-weight: 600; text-transform: uppercase;"><?php echo t('queue_logs.stat_ring_no_answer'); ?></div>
        <div style="font-size: 26px; font-weight: 800; margin-top: 4px; color: #ec4899;"><?php echo $stat_ring_no_answer; ?></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-list-alt" style="color: var(--primary);"></i> <?php echo t('queue_logs.header_title'); ?>
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('qlogHelpBox')" title="Modül Rehberi">
                <i class="fas fa-question-circle"></i>
            </button>
            <a href="/queue-logs" class="btn btn-secondary btn-sm" title="<?php echo t('queue_logs.refresh_tooltip'); ?>"><i class="fas fa-sync-alt"></i></a>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="qlogHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('queue_logs.help_title'); ?></h4>
        <?php echo t('queue_logs.help_body'); ?><br>
        - <strong><?php echo t('queue_logs.help_enterqueue'); ?></strong><br>
        - <strong><?php echo t('queue_logs.help_connect'); ?></strong><br>
        - <strong><?php echo t('queue_logs.help_abandon'); ?></strong>
    </div>

    <!-- Filter Form -->
    <form method="GET" autocomplete="off" style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; align-items: center;">
        <select name="date_range" class="form-control form-control-sm" style="width: auto;">
            <option value="today" <?php echo $date_filter === 'today' ? 'selected' : ''; ?>><?php echo t('queue_logs.range_today'); ?></option>
            <option value="yesterday" <?php echo $date_filter === 'yesterday' ? 'selected' : ''; ?>><?php echo t('queue_logs.range_yesterday'); ?></option>
            <option value="week" <?php echo $date_filter === 'week' ? 'selected' : ''; ?>><?php echo t('queue_logs.range_week'); ?></option>
            <option value="month" <?php echo $date_filter === 'month' ? 'selected' : ''; ?>><?php echo t('queue_logs.range_month'); ?></option>
        </select>

        <select name="event" class="form-control form-control-sm" style="width: auto;">
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

        <select name="agent" class="form-control form-control-sm" style="width: auto;">
            <option value=""><?php echo t('queue_logs.all_agents'); ?></option>
            <?php foreach ($agent_map as $ext => $name): ?>
                <option value="<?php echo htmlspecialchars($ext); ?>" <?php echo $agent_filter === (string)$ext ? 'selected' : ''; ?>>
                    <?php echo t('queue_logs.ext_prefix'); ?> <?php echo htmlspecialchars($ext); ?> - <?php echo htmlspecialchars($name); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div style="position: relative; max-width: 220px;">
            <i class="fas fa-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 12px; pointer-events: none;"></i>
            <input type="text" name="search" class="form-control form-control-sm" placeholder="<?php echo t('queue_logs.search_placeholder'); ?>" value="<?php echo htmlspecialchars($search_query); ?>" style="padding-left: 28px;">
        </div>

        <button type="submit" class="btn btn-primary btn-sm" title="<?php echo t('queue_logs.filter_tooltip'); ?>"><i class="fas fa-filter"></i></button>
    </form>

    <div class="table-responsive">
        <table class="data-table" data-no-dt="true">
            <thead>
                <tr>
                    <th class="col-hide-mobile" style="width: 45px;">#</th>
                    <th><?php echo t('queue_logs.col_date'); ?></th>
                    <th><?php echo t('queue_logs.col_event'); ?></th>
                    <th><?php echo t('queue_logs.col_queue'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('queue_logs.col_agent'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('queue_logs.col_call_id'); ?></th>
                    <th><?php echo t('queue_logs.col_detail'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($parsed_logs)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px;">
                            <i class="fas fa-search-minus" style="font-size: 32px; margin-bottom: 8px; display: block;"></i>
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
                            <td class="col-hide-mobile" style="color: var(--text-muted); font-size: 12px;">#<?php echo ($idx + 1); ?></td>
                            <td style="white-space: nowrap;"><?php echo $log['datetime']; ?></td>
                            <td>
                                <?php echo uiStatusBadge($log['event'], $queueLogBadgeMap, 'info'); ?>
                            </td>
                            <td><span class="badge badge-info"><?php echo htmlspecialchars($log['queue_name']); ?></span></td>
                            <td class="col-hide-mobile">
                                <?php if (!empty($log['agent_ext'])): ?>
                                    <strong><?php echo htmlspecialchars($log['agent_ext']); ?></strong> - <?php echo htmlspecialchars($log['agent_name']); ?>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);"><?php echo htmlspecialchars($log['agent']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="col-hide-mobile" style="font-family: monospace; font-size: 12px; color: var(--text-muted);"><?php echo htmlspecialchars($log['call_id']); ?></td>
                            <td style="font-size: 13px;">
                                <?php
                                if ($log['event'] === 'CONNECT') {
                                    echo t('queue_logs.detail_wait_time') . ": <strong>" . htmlspecialchars($log['data1']) . " sn</strong> | " . t('queue_logs.detail_ring') . ": " . htmlspecialchars($log['data3']) . " sn";
                                } elseif ($log['event'] === 'COMPLETECALLER' || $log['event'] === 'COMPLETEAGENT') {
                                    echo t('queue_logs.detail_wait') . ": " . htmlspecialchars($log['data1']) . " sn | " . t('queue_logs.detail_talk_time') . ": <strong>" . htmlspecialchars($log['data2']) . " sn</strong> (" . t('queue_logs.detail_queue_pos') . ": " . htmlspecialchars($log['data3']) . ")";
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
            </tbody>
        </table>
    </div>
</div>
