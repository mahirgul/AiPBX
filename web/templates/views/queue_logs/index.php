<?php
$formatDuration = function(int $seconds): string {
    $m = intdiv($seconds, 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d', $m, $s);
};
?>

<!-- Statistics Overview -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 16px; margin-bottom: 20px;">
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
            <?php if ($view_mode === 'grouped'): ?>
                <span class="badge badge-info" style="font-size: 11px; margin-left: 8px;">
                    <i class="fas fa-route"></i> <?php echo t('queue_logs.mode_grouped'); ?>
                </span>
            <?php else: ?>
                <span class="badge badge-secondary" style="font-size: 11px; margin-left: 8px;">
                    <i class="fas fa-stream"></i> <?php echo t('queue_logs.mode_raw'); ?>
                </span>
            <?php endif; ?>
        </div>
        <div style="display: flex; gap: 8px; align-items: center;">
            <button type="button" class="btn btn-outline-primary btn-sm" id="btnToggleAllJourneys" onclick="toggleAllQueueJourneys()" title="<?php echo t('queue_logs.btn_expand_all'); ?>" style="<?php echo ($view_mode !== 'grouped' || empty($parsed_logs)) ? 'display: none;' : ''; ?>">
                <i class="fas fa-layer-group"></i> <span id="toggleAllJourneysText"><?php echo t('queue_logs.btn_expand_all'); ?></span>
            </button>
            <button type="button" class="btn-help" onclick="toggleModuleHelp('qlogHelpBox')" title="<?php echo t('common.module_guide', 'Modül Rehberi'); ?>">
                <i class="fas fa-question-circle"></i>
            </button>
            <a href="/queue-logs" class="btn btn-secondary btn-sm" title="<?php echo t('queue_logs.refresh_tooltip'); ?>"><i class="fas fa-sync-alt"></i></a>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="qlogHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('queue_logs.help_title'); ?></h4>
        <p><?php echo t('queue_logs.help_body'); ?></p>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 8px; margin-top: 8px;">
            <div><strong>ENTERQUEUE:</strong> <?php echo t('queue_logs.help_enterqueue'); ?></div>
            <div><strong>CONNECT:</strong> <?php echo t('queue_logs.help_connect'); ?></div>
            <div><strong>ABANDON:</strong> <?php echo t('queue_logs.help_abandon'); ?></div>
        </div>
    </div>

    <!-- Filter Form -->
    <form method="GET" autocomplete="off" style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; align-items: center;">
        <select name="date_range" class="form-control form-control-sm" style="width: auto;" onchange="this.form.submit()">
            <option value="today" <?php echo $date_filter === 'today' ? 'selected' : ''; ?>><?php echo t('queue_logs.range_today'); ?></option>
            <option value="yesterday" <?php echo $date_filter === 'yesterday' ? 'selected' : ''; ?>><?php echo t('queue_logs.range_yesterday'); ?></option>
            <option value="week" <?php echo $date_filter === 'week' ? 'selected' : ''; ?>><?php echo t('queue_logs.range_week'); ?></option>
            <option value="month" <?php echo $date_filter === 'month' ? 'selected' : ''; ?>><?php echo t('queue_logs.range_month'); ?></option>
        </select>

        <select name="view_mode" class="form-control form-control-sm" style="width: auto; font-weight: 700; color: var(--primary);" onchange="this.form.submit()">
            <option value="grouped" <?php echo $view_mode === 'grouped' ? 'selected' : ''; ?>>
                <i class="fas fa-layer-group"></i> <?php echo t('queue_logs.mode_grouped'); ?>
            </option>
            <option value="raw" <?php echo $view_mode === 'raw' ? 'selected' : ''; ?>>
                <i class="fas fa-stream"></i> <?php echo t('queue_logs.mode_raw'); ?>
            </option>
        </select>

        <select name="event" class="form-control form-control-sm" style="width: auto;" onchange="this.form.submit()">
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

        <select name="agent" class="form-control form-control-sm" style="width: auto;" onchange="this.form.submit()">
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
        <input type="hidden" name="boyut" value="<?php echo (int)$sayfa_boyutu; ?>">

        <button type="submit" class="btn btn-primary btn-sm" title="<?php echo t('queue_logs.filter_tooltip'); ?>"><i class="fas fa-filter"></i></button>
        <?php if (!empty($event_filter) || !empty($agent_filter) || !empty($search_query) || $date_filter !== 'today' || $view_mode !== 'grouped'): ?>
            <a href="/queue-logs" class="btn btn-secondary btn-sm" title="<?php echo t('common.reset', 'Sıfırla'); ?>"><i class="fas fa-undo"></i></a>
        <?php endif; ?>
    </form>

    <div class="table-responsive">
        <?php
            $page_size = $sayfa_boyutu;
            require dirname(__DIR__, 2) . '/pagination_controls.php';
        ?>
        <?php if ($view_mode === 'grouped'): ?>
            <!-- ======================================================== -->
            <!-- GROUPED VIEW: 1 ÇAĞRI = 1 SATIR (ÇAĞRI YOLCULUĞU İLE)    -->
            <!-- ======================================================== -->
            <table class="data-table" data-no-dt="true">
                <thead>
                    <tr>
                        <th class="col-hide-mobile" style="width: 45px;">#</th>
                        <th><?php echo t('queue_logs.col_date'); ?></th>
                        <th><?php echo t('queue_logs.col_caller'); ?></th>
                        <th><?php echo t('queue_logs.col_queue'); ?></th>
                        <th><?php echo t('queue_logs.col_status'); ?></th>
                        <th><?php echo t('queue_logs.col_answering_agent'); ?></th>
                        <th><?php echo t('queue_logs.col_hold_time'); ?></th>
                        <th><?php echo t('queue_logs.col_talk_time'); ?></th>
                        <th><?php echo t('queue_logs.col_journey'); ?></th>
                        <th style="text-align: right;"><?php echo t('queue_logs.col_call_id'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($parsed_logs)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; color: var(--text-muted); padding: 30px;">
                                <i class="fas fa-search-minus" style="font-size: 32px; margin-bottom: 8px; display: block;"></i>
                                <?php echo t('queue_logs.empty'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($parsed_logs as $idx => $c): ?>
                            <tr>
                                <td class="col-hide-mobile" style="color: var(--text-muted); font-size: 12px; font-weight: 600;">#<?php echo ($idx + 1); ?></td>
                                <td style="white-space: nowrap; font-weight: 600;"><?php echo $c['datetime']; ?></td>
                                <td>
                                    <strong style="color: var(--primary);">
                                        <i class="fas fa-phone-alt" style="font-size: 11px; margin-right: 4px; opacity: 0.7;"></i>
                                        <?php echo htmlspecialchars($c['caller_num']); ?>
                                    </strong>
                                </td>
                                <td><span class="badge badge-info"><?php echo htmlspecialchars($c['queue_name']); ?></span></td>
                                <td>
                                    <span class="badge <?php echo $c['status_badge']; ?>" style="font-size: 11px;">
                                        <?php echo htmlspecialchars($c['status_label']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($c['agent_ext'])): ?>
                                        <strong><?php echo htmlspecialchars($c['agent_ext']); ?></strong>
                                        <?php if (!empty($c['agent_name'])): ?>
                                            <small style="color: var(--text-muted); display: block; font-size: 11px;"><?php echo htmlspecialchars($c['agent_name']); ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 12px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-family: monospace; font-size: 12px; font-weight: 600; color: var(--warning);">
                                        <i class="fas fa-hourglass-half" style="font-size: 10px; margin-right: 3px;"></i>
                                        <?php echo $formatDuration($c['hold_sec']); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($c['talk_sec'] > 0): ?>
                                        <div style="font-family: monospace; font-size: 12.5px; font-weight: 700; color: var(--success);">
                                            <i class="fas fa-phone-volume" style="font-size: 11px; margin-right: 4px;"></i>
                                            <?php echo $formatDuration($c['talk_sec']); ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 12px; font-family: monospace;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-outline-primary btn-sm queue-journey-btn" id="journey-btn-<?php echo $idx; ?>" onclick="toggleQueueJourney('<?php echo $idx; ?>')" style="padding: 2px 8px; font-size: 11px; border-radius: 6px; font-weight: 700; line-height: 1.3;" title="<?php echo t('queue_logs.journey_title'); ?>">
                                        <i class="fas fa-route"></i> <?php echo sprintf(t('queue_logs.journey_steps'), $c['steps_count']); ?> <i class="fas fa-chevron-down queue-journey-icon" style="font-size: 9px; transition: transform 0.2s;"></i>
                                    </button>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <code style="font-size: 11px; color: var(--text-muted); background: var(--bg-hover, rgba(0,0,0,0.03)); padding: 2px 6px; border-radius: 4px;" title="Call ID: <?php echo htmlspecialchars($c['call_id']); ?>">
                                        <?php echo htmlspecialchars(strlen($c['call_id']) > 16 ? substr($c['call_id'], 0, 16) . '…' : $c['call_id']); ?>
                                    </code>
                                </td>
                            </tr>

                            <!-- Açılabilir Çağrı Yolculuğu (Timeline) Satırı -->
                            <tr id="journey-row-<?php echo $idx; ?>" class="queue-journey-row" style="display: none;">
                                <td colspan="10" style="padding: 14px 20px; background: rgba(0, 242, 254, 0.02); border-bottom: 2px solid var(--border-color);">
                                    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 14px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
                                        <!-- Timeline Üst Başlık & Süre Özeti -->
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 8px; border-bottom: 1px solid var(--border-color); flex-wrap: wrap; gap: 8px;">
                                            <div style="font-size: 13px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                                                <span style="width: 24px; height: 24px; border-radius: 6px; background: rgba(0, 242, 254, 0.12); color: var(--primary); display: inline-flex; align-items: center; justify-content: center; font-size: 12px;">
                                                    <i class="fas fa-route"></i>
                                                </span>
                                                <span><?php echo t('queue_logs.journey_title'); ?></span>
                                                <span class="badge badge-info" style="font-size: 11px;"><?php echo sprintf(t('queue_logs.journey_steps'), $c['steps_count']); ?></span>
                                                <code style="font-size: 11px; color: var(--text-muted); background: var(--bg-input); padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($c['call_id']); ?></code>
                                            </div>
                                            <div style="font-size: 12px; color: var(--text-muted); display: flex; align-items: center; gap: 10px;">
                                                <span><?php echo t('queue_logs.journey_wait'); ?>: <strong style="color: var(--warning);"><?php echo $formatDuration($c['hold_sec']); ?></strong></span>
                                                <span>•</span>
                                                <span><?php echo t('queue_logs.journey_talk'); ?>: <strong style="color: var(--success);"><?php echo $formatDuration($c['talk_sec']); ?></strong></span>
                                                <span>•</span>
                                                <span><?php echo t('queue_logs.journey_total'); ?>: <strong style="color: var(--primary);"><?php echo $formatDuration($c['total_sec']); ?></strong></span>
                                            </div>
                                        </div>

                                        <!-- Dikey Zaman Çizelgesi (Vertical Timeline) -->
                                        <div style="position: relative; padding-left: 24px; margin-left: 8px; border-left: 2px dashed var(--border-color);">
                                            <?php foreach ($c['steps'] as $step): ?>
                                                <div style="position: relative; margin-bottom: 10px;">
                                                    <!-- Düğüm Noktası (Node Dot) -->
                                                    <div style="position: absolute; left: -31px; top: 6px; width: 14px; height: 14px; border-radius: 50%; background: var(--bg-card); border: 2px solid <?php echo $step['node_color']; ?>; display: flex; align-items: center; justify-content: center;">
                                                        <div style="width: 6px; height: 6px; border-radius: 50%; background: <?php echo $step['node_color']; ?>;"></div>
                                                    </div>

                                                    <!-- Adım Kartı (Step Card) -->
                                                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; background: var(--bg-input); padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border-color); flex-wrap: wrap;">
                                                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                                            <span style="font-family: monospace; font-size: 11px; font-weight: 700; color: var(--text-muted); background: var(--bg-card); padding: 2px 6px; border-radius: 4px; border: 1px solid var(--border-color);">
                                                                <?php echo $step['time']; ?>
                                                            </span>
                                                            <span style="font-size: 12.5px; font-weight: 700; color: var(--text-main);">
                                                                <i class="fas <?php echo $step['icon']; ?>" style="color: var(--primary); font-size: 11px; margin-right: 4px;"></i>
                                                                <?php echo $step['title']; ?>
                                                            </span>
                                                            <span class="badge <?php echo $step['badge']; ?>" style="font-size: 10px; padding: 2px 6px;">
                                                                <?php echo htmlspecialchars($step['event']); ?>
                                                            </span>
                                                            <span style="font-size: 12px; color: var(--text-muted);">
                                                                <?php echo $step['detail']; ?>
                                                            </span>
                                                        </div>
                                                        <div style="font-size: 11px; color: var(--text-muted); font-family: monospace;">
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
                </tbody>
            </table>
        <?php else: ?>
            <!-- ======================================================== -->
            <!-- RAW VIEW: HAM OLAY BAZLI LİSTELEME                      -->
            <!-- ======================================================== -->
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
        <?php endif; ?>
    </div>

    <?php
        $page = $sayfa;
        $page_size = $sayfa_boyutu;
        $total_pages = $toplam_sayfa;
        $total_rows = $total_records;
        require dirname(__DIR__, 2) . '/pagination.php';
    ?>
</div>

<script>
function toggleQueueJourney(id) {
    const row = document.getElementById('journey-row-' + id);
    const btn = document.getElementById('journey-btn-' + id);
    if (!row) return;
    const isHidden = (row.style.display === 'none' || !row.style.display);
    row.style.display = isHidden ? 'table-row' : 'none';
    if (btn) {
        const icon = btn.querySelector('.queue-journey-icon');
        if (icon) {
            icon.style.transform = isHidden ? 'rotate(180deg)' : 'rotate(0deg)';
        }
        if (isHidden) {
            btn.classList.add('active');
            btn.classList.remove('btn-outline-primary');
            btn.classList.add('btn-primary');
        } else {
            btn.classList.remove('active');
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-outline-primary');
        }
    }
}

function toggleAllQueueJourneys() {
    const rows = document.querySelectorAll('.queue-journey-row');
    const btns = document.querySelectorAll('.queue-journey-btn');
    const textAll = document.getElementById('toggleAllJourneysText');
    if (!rows.length) return;

    let anyHidden = false;
    rows.forEach(function(r) {
        if (r.style.display === 'none' || !r.style.display) anyHidden = true;
    });

    rows.forEach(function(r) {
        r.style.display = anyHidden ? 'table-row' : 'none';
    });

    btns.forEach(function(b) {
        const icon = b.querySelector('.queue-journey-icon');
        if (icon) icon.style.transform = anyHidden ? 'rotate(180deg)' : 'rotate(0deg)';
        if (anyHidden) {
            b.classList.add('active', 'btn-primary');
            b.classList.remove('btn-outline-primary');
        } else {
            b.classList.remove('active', 'btn-primary');
            b.classList.add('btn-outline-primary');
        }
    });

    if (textAll) {
        textAll.innerText = anyHidden ? <?php echo json_encode(t('queue_logs.btn_collapse_all', 'Tümünü Daralt')); ?> : <?php echo json_encode(t('queue_logs.btn_expand_all', 'Tümünü Genişlet')); ?>;
    }
}
</script>
