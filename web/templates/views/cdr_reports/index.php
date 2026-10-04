<?php
// Call direction: icon, colour and label (see CdrCallAnalyzer).
$dir_meta = [
    CdrCallAnalyzer::INBOUND => ['fa-right-to-bracket', 'var(--success)', t('cdr_reports.dir_inbound')],
    CdrCallAnalyzer::OUTBOUND => ['fa-right-from-bracket', 'var(--primary)', t('cdr_reports.dir_outbound')],
    CdrCallAnalyzer::INTERNAL => ['fa-building', 'var(--text-muted)', t('cdr_reports.dir_internal')],
    CdrCallAnalyzer::TRANSIT => ['fa-shuffle', 'var(--purple)', t('cdr_reports.dir_transit')],
];
$grouped_mode = ($view_mode ?? 'grouped') === 'grouped';
$filter_url = function (array $override) {
    $q = array_merge($_GET, $override);
    unset($q['page']);
    return '/cdr-reports?' . http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
};
?>
<!-- Statistics Overview -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 20px;">
    <div class="card u-mb-0">
        <div style="color: var(--text-muted); font-size: 13px; font-weight: 600;"><?php echo t('cdr_reports.stat_total'); ?></div>
        <div style="font-size: 28px; font-weight: 800; margin-top: 6px; color: var(--text-main);"><?php echo $stat_total; ?></div>
        <div class="u-muted u-fs-12 u-mt-4"><?php echo t('cdr_reports.stat_total_desc'); ?></div>
    </div>

    <div class="card u-mb-0">
        <div style="color: var(--text-muted); font-size: 13px; font-weight: 600;"><?php echo t('cdr_reports.stat_answered'); ?></div>
        <div style="font-size: 28px; font-weight: 800; margin-top: 6px; color: var(--success);"><?php echo $stat_answered; ?> <span style="font-size: 14px; color: var(--text-muted); font-weight: 600;">(%<?php echo $answer_rate; ?>)</span></div>
        <div class="u-muted u-fs-12 u-mt-4"><?php echo t('cdr_reports.stat_answered_desc'); ?></div>
    </div>

    <div class="card u-mb-0">
        <div style="color: var(--text-muted); font-size: 13px; font-weight: 600;"><?php echo t('cdr_reports.stat_talk_time'); ?></div>
        <div style="font-size: 28px; font-weight: 800; margin-top: 6px; color: var(--primary);"><?php echo round($stat_total_billsec / 60, 1); ?> <span style="font-size: 14px; color: var(--text-muted); font-weight: 600;">dk</span></div>
        <div class="u-muted u-fs-12 u-mt-4"><?php echo t('cdr_reports.stat_talk_time_desc'); ?></div>
    </div>

    <div class="card u-mb-0">
        <div style="color: var(--text-muted); font-size: 13px; font-weight: 600;"><?php echo t('cdr_reports.stat_recordings'); ?></div>
        <div style="font-size: 28px; font-weight: 800; margin-top: 6px; color: var(--warning);"><?php echo $stat_recordings_count; ?></div>
        <div class="u-muted u-fs-12 u-mt-4"><?php echo t('cdr_reports.stat_recordings_desc'); ?></div>
    </div>
</div>

<?php if ($grouped_mode && $stat_total > 0): ?>
<div class="u-flex-gap" style="flex-wrap: wrap; margin-bottom: 20px;">
    <?php foreach ($dir_meta as $dk => [$dicon, $dcolor, $dlabel]): ?>
        <a href="<?php echo htmlspecialchars($filter_url(['direction' => $direction_filter === $dk ? '' : $dk])); ?>"
           class="card u-mb-0" style="flex: 1; min-width: 160px; padding: 12px 16px; display: flex; align-items: center; gap: 12px; text-decoration: none; <?php echo $direction_filter === $dk ? 'outline: 2px solid ' . $dcolor . ';' : ''; ?>"
           title="<?php echo htmlspecialchars(t('cdr_reports.dir_filter_tooltip')); ?>">
            <i class="fas <?php echo $dicon; ?>" style="font-size: 20px; color: <?php echo $dcolor; ?>;"></i>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: var(--text-main);"><?php echo (int) $stat_directions[$dk]; ?></div>
                <div class="u-muted u-fs-12"><?php echo $dlabel; ?></div>
            </div>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-file-audio u-primary"></i> <?php echo t('cdr_reports.title'); ?>
        </div>
        <div class="u-flex-gap">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('cdrHelpBox')" title="Modül Rehberi">
                <i class="fas fa-question-circle"></i>
            </button>
<?php require dirname(__DIR__, 2) . '/export_buttons.php'; ?>
            <a href="/cdr-reports" class="btn btn-secondary btn-sm" title="<?php echo t('cdr_reports.refresh_tooltip'); ?>"><i class="fas fa-sync-alt"></i></a>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="cdrHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('cdr_reports.help_title'); ?></h4>
        <?php echo t('cdr_reports.help_body'); ?><br>
        <?php echo t('cdr_reports.help_body2'); ?>
    </div>

    <!-- Filter Form Bar -->
    <form method="GET" autocomplete="off" style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; align-items: center; background: var(--bg-input); padding: 16px; border-radius: 12px; border: 1px solid var(--border-color);">
        <select name="date_range" id="date_range_select" class="form-control form-control-sm u-w-auto" onchange="toggleCustomDates(); if (this.value !== 'custom') this.form.submit();">
            <option value="today" <?php echo $date_filter === 'today' ? 'selected' : ''; ?>><?php echo t('cdr_reports.range_today'); ?></option>
            <option value="yesterday" <?php echo $date_filter === 'yesterday' ? 'selected' : ''; ?>><?php echo t('cdr_reports.range_yesterday'); ?></option>
            <option value="week" <?php echo $date_filter === 'week' ? 'selected' : ''; ?>><?php echo t('cdr_reports.range_week'); ?></option>
            <option value="month" <?php echo $date_filter === 'month' ? 'selected' : ''; ?>><?php echo t('cdr_reports.range_month'); ?></option>
            <option value="custom" <?php echo $date_filter === 'custom' ? 'selected' : ''; ?>><?php echo t('cdr_reports.range_custom'); ?></option>
        </select>

        <div id="custom_date_inputs" style="display: <?php echo $date_filter === 'custom' ? 'flex' : 'none'; ?>; gap: 8px; align-items: center;">
            <input type="date" name="start_date" class="form-control form-control-sm u-w-auto" value="<?php echo htmlspecialchars($start_date); ?>">
            <span class="u-muted u-fs-12">-</span>
            <input type="date" name="end_date" class="form-control form-control-sm u-w-auto" value="<?php echo htmlspecialchars($end_date); ?>">
        </div>

        <select name="status" class="form-control form-control-sm u-w-auto" onchange="this.form.submit()">
            <option value=""><?php echo t('cdr_reports.all_statuses'); ?></option>
            <option value="ANSWERED" <?php echo $status_filter === 'ANSWERED' ? 'selected' : ''; ?>><?php echo t('cdr_reports.status_answered'); ?></option>
            <option value="NO ANSWER" <?php echo $status_filter === 'NO ANSWER' ? 'selected' : ''; ?>><?php echo t('cdr_reports.status_no_answer'); ?></option>
            <option value="BUSY" <?php echo $status_filter === 'BUSY' ? 'selected' : ''; ?>><?php echo t('cdr_reports.status_busy'); ?></option>
            <option value="FAILED" <?php echo $status_filter === 'FAILED' ? 'selected' : ''; ?>><?php echo t('cdr_reports.status_failed'); ?></option>
            <option value="ABANDON" <?php echo $status_filter === 'ABANDON' ? 'selected' : ''; ?>><?php echo t('cdr_reports.status_abandon'); ?></option>
        </select>

        <?php if ($can_view_all): ?>
        <select name="agent" class="form-control form-control-sm u-w-auto" onchange="this.form.submit()">
            <option value=""><?php echo t('cdr_reports.all_agents'); ?></option>
            <?php foreach ($agents as $ag): ?>
                <option value="<?php echo htmlspecialchars($ag['extension']); ?>" <?php echo $agent_filter === $ag['extension'] ? 'selected' : ''; ?>>
                    <?php echo t('cdr_reports.ext_prefix'); ?> <?php echo htmlspecialchars($ag['extension']); ?> - <?php echo htmlspecialchars($ag['full_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <select name="device" class="form-control form-control-sm u-w-auto" onchange="this.form.submit()">
            <option value=""><?php echo t('cdr_reports.all_devices'); ?></option>
            <option value="mobil" <?php echo ($device_filter ?? '') === 'mobil' ? 'selected' : ''; ?>><?php echo t('cdr_reports.device_mobile'); ?></option>
            <option value="webrtc" <?php echo ($device_filter ?? '') === 'webrtc' ? 'selected' : ''; ?>><?php echo t('cdr_reports.device_webrtc'); ?></option>
            <option value="sip" <?php echo ($device_filter ?? '') === 'sip' ? 'selected' : ''; ?>><?php echo t('cdr_reports.device_sip'); ?></option>
        </select>

        <?php if ($grouped_mode): ?>
        <select name="direction" class="form-control form-control-sm u-w-auto" onchange="this.form.submit()">
            <option value=""><?php echo t('cdr_reports.all_directions'); ?></option>
            <?php foreach ($dir_meta as $dk => [, , $dlabel]): ?>
                <option value="<?php echo $dk; ?>" <?php echo $direction_filter === $dk ? 'selected' : ''; ?>><?php echo $dlabel; ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (!empty($trunks)): ?>
        <select name="trunk" class="form-control form-control-sm u-w-auto" onchange="this.form.submit()">
            <option value=""><?php echo t('cdr_reports.all_trunks'); ?></option>
            <?php foreach ($trunks as $tn => $tt): ?>
                <option value="<?php echo htmlspecialchars($tn); ?>" <?php echo $trunk_filter === $tn ? 'selected' : ''; ?>><?php echo htmlspecialchars($tt); ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php endif; ?>

        <select name="view_mode" class="form-control form-control-sm u-fw-600 u-w-auto" onchange="this.form.submit()">
            <option value="grouped" <?php echo ($view_mode ?? 'grouped') === 'grouped' ? 'selected' : ''; ?>><?php echo t('cdr_reports.mode_grouped'); ?></option>
            <option value="raw" <?php echo ($view_mode ?? 'grouped') === 'raw' ? 'selected' : ''; ?>><?php echo t('cdr_reports.mode_raw'); ?></option>
        </select>

        <div style="position: relative; max-width: 220px;">
            <i class="fas fa-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 12px; pointer-events: none;"></i>
            <input type="text" name="search" class="form-control form-control-sm" placeholder="<?php echo t('cdr_reports.search_placeholder'); ?>" value="<?php echo htmlspecialchars($search_query); ?>" style="padding-left: 28px;">
        </div>
        <input type="hidden" name="boyut" value="<?php echo (int)$sayfa_boyutu; ?>">

        <button type="submit" class="btn btn-primary btn-sm" title="<?php echo t('cdr_reports.filter_tooltip'); ?>"><i class="fas fa-filter"></i></button>
        <?php if (!empty($search_query) || !empty($status_filter) || !empty($agent_filter) || !empty($device_filter) || !empty($direction_filter) || !empty($trunk_filter) || ($view_mode ?? 'grouped') !== 'grouped' || $date_filter !== 'today'): ?>
            <a href="/cdr-reports" class="btn btn-secondary btn-sm" title="<?php echo t('cdr_reports.reset_tooltip'); ?>"><i class="fas fa-undo"></i></a>
        <?php endif; ?>
    </form>

    <!-- CDR Records Data Table -->
    <div class="table-responsive">
        <?php
            // "Goster: N Kayit" secicisi tablonun USTUNDE, sagda — sistemdeki
            // diger tablolarla ayni yerde (dt-controls-bar).
            $page_size = $sayfa_boyutu;
            require dirname(__DIR__, 2) . '/pagination_controls.php';
        ?>
        <table class="data-table" data-no-dt="true">
            <thead>
                <tr>
                    <th class="col-hide-mobile"><?php echo t('cdr_reports.col_id'); ?></th>
                    <th><?php echo t('cdr_reports.col_datetime'); ?></th>
                    <th><?php echo t('cdr_reports.col_direction'); ?></th>
                    <th><?php echo t('cdr_reports.col_caller'); ?></th>
                    <th><?php echo t('cdr_reports.col_callee'); ?></th>
                    <th><?php echo t('cdr_reports.col_answered_by'); ?></th>
                    <th><?php echo t('cdr_reports.col_duration'); ?></th>
                    <th><?php echo t('cdr_reports.col_status'); ?></th>
                    <th><?php echo t('cdr_reports.col_note'); ?></th>
                    <th class="text-right"><?php echo t('cdr_reports.col_actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($cdrs)): ?>
                    <tr>
                        <td colspan="10" class="u-muted u-text-center u-p-24">
                            <?php echo t('cdr_reports.empty'); ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($cdrs as $c):
                        $has_rec = (!empty($c['recording_path']) && file_exists($c['recording_path']));
                        $is_own = (!empty($user_ext) && ($c['agent_extension'] === $user_ext || $c['caller_num'] === $user_ext));
                        $can_listen_row = ($can_listen_all || $is_own);

                        // Status Badge Color Mapping
                        $badge_class = 'badge-secondary';
                        $status_label = $c['status'];

                        if ($c['status'] === 'ANSWERED') {
                            $badge_class = 'badge-success';
                            $status_label = t('cdr_reports.status_answered_label');
                        } elseif (in_array($c['status'], ['NO ANSWER', 'NOANSWER', 'CANCEL'])) {
                            $badge_class = 'badge-warning';
                            $status_label = t('cdr_reports.status_no_answer_label');
                        } elseif ($c['status'] === 'UNAVAILABLE') {
                            $badge_class = 'badge-secondary';
                            $status_label = t('cdr_reports.status_unavailable_label');
                        } elseif ($c['status'] === 'BUSY') {
                            $badge_class = 'badge-info';
                            $status_label = t('cdr_reports.status_busy_label');
                        } elseif ($c['status'] === 'FAILED' || $c['status'] === 'ABANDON') {
                            $badge_class = 'badge-danger';
                            $status_label = ($c['status'] === 'ABANDON') ? t('cdr_reports.status_abandon_label') : t('cdr_reports.status_failed_label');
                        }
                    ?>
                        <tr>
                            <td class="col-hide-mobile" style="color: var(--text-muted); font-size: 12px; white-space: nowrap;">
                                <?php $show_journey = (!empty($c['legs']) && count($c['legs']) > 1) || count($c['flow']['path'] ?? []) > 2; ?>
                                <?php if ($show_journey): ?>
                                    <button type="button" class="btn btn-outline-primary btn-sm journey-toggle-btn" id="journey-btn-<?php echo $c['id']; ?>" onclick="toggleCallJourney('<?php echo $c['id']; ?>')" style="padding: 2px 7px; font-size: 11px; margin-right: 5px; border-radius: 6px; font-weight: 700; line-height: 1.2;" title="<?php echo t('cdr_reports.journey_title'); ?>">
                                        <i class="fas fa-route"></i> <?php echo count($c['legs']); ?> <i class="fas fa-chevron-down journey-icon" style="font-size: 9px; transition: transform 0.2s;"></i>
                                    </button>
                                <?php endif; ?>
                                #<?php echo $c['id']; ?>
                            </td>
                            <td style="font-weight: 600; white-space: nowrap;">
                                <?php echo date('d.m.Y H:i:s', strtotime($c['start_time'])); ?>
                            </td>
                            <?php
                                $f = $c['flow'] ?? null;
                                $dev_icons = ['mobil' => 'fa-mobile-alt', 'webrtc' => 'fa-desktop', 'sip' => 'fa-phone-alt'];
                                $dev_labels = ['mobil' => t('cdr_reports.device_mobile'), 'webrtc' => t('cdr_reports.device_webrtc'), 'sip' => t('cdr_reports.device_sip')];
                                $chip = function (string $icon, string $text, string $title) {
                                    return '<span class="badge" title="' . htmlspecialchars($title) . '" style="background: rgba(100,116,139,0.12); color: var(--text-main); border: 1px solid var(--border-color); font-size: 10px; font-weight: 600; text-transform: none; letter-spacing: 0; padding: 2px 6px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px; margin-top: 3px;"><i class="fas ' . $icon . '"></i> ' . htmlspecialchars($text) . '</span>';
                                };
                            ?>
                            <td style="white-space: nowrap;">
                                <?php if ($f): [$dicon, $dcolor, $dlabel] = $dir_meta[$f['direction']]; ?>
                                    <span style="color: <?php echo $dcolor; ?>; font-weight: 700; font-size: 12px;"><i class="fas <?php echo $dicon; ?>"></i> <?php echo $dlabel; ?></span>
                                    <?php if ($f['transferred']): ?>
                                        <div><span class="badge badge-purple u-fs-10" style="margin-top: 3px;"><i class="fas fa-share"></i> <?php echo t('cdr_reports.transferred'); ?></span></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="u-muted u-fs-12">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight: 700; color: var(--primary);"><?php echo htmlspecialchars($f['caller_number'] ?? $c['caller_num']); ?></div>
                                <?php if (!empty($f['caller_name'])): ?>
                                    <small class="u-muted" style="display: block; font-size: 11px;"><?php echo htmlspecialchars($f['caller_name']); ?></small>
                                <?php endif; ?>
                                <?php if (!empty($f['in_trunk'])): ?>
                                    <?php echo $chip('fa-right-to-bracket', $f['in_trunk_title'], t('cdr_reports.in_trunk')); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $dialed = $f['dialed_number'] ?? ''; ?>
                                <?php if ($dialed !== ''): ?>
                                    <div style="font-weight: 700;"><?php echo htmlspecialchars($dialed); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($c['route'] ?? $c['queue_name'] ?? '')): ?>
                                    <small class="u-muted" style="display: block; font-size: 11px;"><i class="fas fa-signs-post"></i> <?php echo htmlspecialchars($c['route'] ?? $c['queue_name']); ?></small>
                                <?php endif; ?>
                                <?php if (!empty($f['out_trunk'])): ?>
                                    <?php echo $chip('fa-right-from-bracket', $f['out_trunk_title'] . ($f['out_number'] !== '' && $f['out_number'] !== $dialed ? ' → ' . $f['out_number'] : ''), t('cdr_reports.out_trunk')); ?>
                                <?php endif; ?>
                                <?php if ($dialed === '' && empty($f['out_trunk']) && empty($c['route'] ?? $c['queue_name'] ?? '')): ?>
                                    <span class="u-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($c['agent_extension'])): ?>
                                    <strong><?php echo htmlspecialchars($c['agent_extension']); ?></strong>
                                    <?php $dev = $c['device_type'] ?? ''; if (isset($dev_icons[$dev])): ?>
                                        <i class="fas <?php echo $dev_icons[$dev]; ?> u-muted" style="font-size: 11px; margin-left: 4px;" title="<?php echo htmlspecialchars($dev_labels[$dev]); ?>"></i>
                                    <?php endif; ?>
                                    <?php if (!empty($c['agent_name'])): ?>
                                        <small style="color: var(--text-muted); display: block; font-size: 11px;"><?php echo htmlspecialchars($c['agent_name']); ?></small>
                                    <?php endif; ?>
                                <?php elseif ($f && $f['direction'] === CdrCallAnalyzer::TRANSIT && $c['status'] === 'ANSWERED'): ?>
                                    <span class="u-fs-12"><i class="fas fa-right-from-bracket u-muted"></i> <?php echo htmlspecialchars(t('cdr_reports.answered_external')); ?></span>
                                <?php else: ?>
                                    <span class="u-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <?php
                                // Calma = toplam - konusma. Gorunum bunu ring_sec olarak
                                // veriyor; eski kayitlarda alan yoksa burada hesaplanir.
                                $ring = isset($c['ring_sec'])
                                    ? intval($c['ring_sec'])
                                    : max(0, intval($c['duration']) - intval($c['billsec']));
                                $bill = intval($c['billsec'] ?? 0);
                                $dur  = max(intval($c['duration'] ?? 0), $bill);
                                $sure_bicim = fn(int $sn) => sprintf('%02d:%02d', intdiv($sn, 60), $sn % 60);
                            ?>
                            <td>
                                <?php if ($c['status'] === 'ANSWERED'): ?>
                                    <div style="font-family: monospace; font-size: 13px; font-weight: 700; color: var(--success);">
                                        <i class="fas fa-phone-volume" style="font-size: 11px; margin-right: 4px;"></i><?php echo $sure_bicim($bill); ?>
                                    </div>
                                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px; white-space: nowrap;">
                                        <?php echo t('cdr_reports.col_ring'); ?>: <?php echo $sure_bicim($ring); ?> • <?php echo t('cdr_reports.stat_total'); ?>: <?php echo $sure_bicim($dur); ?>
                                    </div>
                                <?php elseif (in_array($c['status'], ['NO ANSWER', 'NOANSWER', 'CANCEL', 'ABANDON'])): ?>
                                    <div style="font-family: monospace; font-size: 12px; color: var(--warning); font-weight: 600;">
                                        <i class="fas fa-bell" style="font-size: 10px; margin-right: 3px;"></i><?php echo t('cdr_reports.col_ring'); ?>: <?php echo $sure_bicim($ring); ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: var(--text-muted); font-size: 12px; font-family: monospace;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $badge_class; ?> u-fs-11">
                                    <?php echo htmlspecialchars($status_label); ?>
                                </span>
                            </td>
                            <td style="max-width: 220px;">
                                <?php if (!empty($c['note_disposition']) || !empty($c['note_text']) || !empty($c['note_customer_name'])): ?>
                                    <?php if (!empty($c['note_disposition'])): ?>
                                        <span class="badge badge-info u-fs-10"><?php echo htmlspecialchars($c['note_disposition']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($c['note_customer_name'])): ?>
                                        <div style="font-size: 12px; font-weight: 600; color: var(--text-main); margin-top: 2px;"><?php echo htmlspecialchars($c['note_customer_name']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($c['note_text'])): ?>
                                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px; white-space: normal; overflow-wrap: anywhere;" title="<?php echo htmlspecialchars($c['note_text']); ?>">
                                            <?php echo htmlspecialchars(mb_strlen($c['note_text']) > 60 ? mb_substr($c['note_text'], 0, 60) . '…' : $c['note_text']); ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="u-muted u-fs-12">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <?php if ($has_rec && $can_listen_row): ?>
                                    <button class="btn btn-secondary btn-sm" onclick="playCdrAudio(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['caller_num']), ENT_QUOTES); ?>', '<?php echo date('d.m.Y H:i', strtotime($c['start_time'])); ?>')" title="<?php echo t('cdr_reports.listen_tooltip'); ?>">
                                        <i class="fas fa-play"></i>
                                    </button>
                                    <a href="/api/cc_audio.php?id=<?php echo $c['id']; ?>&download=1" class="btn btn-secondary btn-sm" title="<?php echo t('cdr_reports.download_tooltip'); ?>">
                                        <i class="fas fa-download"></i>
                                    </a>
                                <?php elseif ($has_rec): ?>
                                    <span class="badge badge-secondary u-fs-10" title="<?php echo t('cdr_reports.locked_tooltip'); ?>"><i class="fas fa-lock"></i> <?php echo t('cdr_reports.locked'); ?></span>
                                <?php else: ?>
                                    <span class="u-muted u-fs-12">-</span>
                                <?php endif; ?>

                                <?php if ($can_delete_cdr): ?>
                                    <form method="POST" autocomplete="off" style="display: inline-block; margin-left: 4px;" onsubmit="return confirm('<?php echo htmlspecialchars(t('cdr_reports.delete_confirm'), ENT_QUOTES); ?>');">
                                        <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                        <input type="hidden" name="delete_cdr_id" value="<?php echo $c['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="<?php echo t('cdr_reports.delete_tooltip'); ?>">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($show_journey): ?>
                            <tr id="journey-row-<?php echo $c['id']; ?>" class="cdr-journey-row" style="display: none;">
                                <td colspan="10" style="padding: 14px 20px; background: rgba(0, 242, 254, 0.02); border-bottom: 2px solid var(--border-color);">
                                    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 14px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--border-color); flex-wrap: wrap; gap: 8px;">
                                            <div style="font-size: 13px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                                                <span style="width: 24px; height: 24px; border-radius: 6px; background: rgba(0, 242, 254, 0.12); color: var(--primary); display: inline-flex; align-items: center; justify-content: center; font-size: 12px;">
                                                    <i class="fas fa-route"></i>
                                                </span>
                                                <span><?php echo t('cdr_reports.journey_title'); ?></span>
                                                <span class="badge badge-info u-fs-11"><?php echo count($c['legs']); ?> <?php echo t('cdr_reports.legs_count'); ?></span>
                                                <code style="font-size: 11px; color: var(--text-muted); background: var(--bg-input); padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($c['linkedid'] ?? $c['call_id']); ?></code>
                                            </div>
                                            <div style="font-size: 12px; color: var(--text-muted); display: flex; align-items: center; gap: 10px;">
                                                <span><?php echo t('cdr_reports.journey_wait'); ?>: <strong style="color: var(--text-main);"><?php echo sprintf('%02d:%02d', intdiv($ring, 60), $ring % 60); ?></strong></span>
                                                <span>•</span>
                                                <span><?php echo t('cdr_reports.journey_talk'); ?>: <strong class="u-success"><?php echo sprintf('%02d:%02d', intdiv($bill, 60), $bill % 60); ?></strong></span>
                                                <span>•</span>
                                                <span><?php echo t('cdr_reports.journey_total'); ?>: <strong class="u-primary"><?php echo sprintf('%02d:%02d', intdiv($dur, 60), $dur % 60); ?></strong></span>
                                            </div>
                                        </div>

                                        <?php if (!empty($c['flow']['path'])): ?>
                                        <!-- Call path: origin, then everyone who answered -->
                                        <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 12px;">
                                            <?php foreach ($c['flow']['path'] as $pi => $node):
                                                $nicon = $node['kind'] === 'trunk' ? 'fa-tower-broadcast' : ($node['kind'] === 'ext' ? 'fa-user' : 'fa-hashtag');
                                                $ntext = $node['kind'] === 'trunk'
                                                    ? $node['label'] . ($node['number'] !== '' ? ': ' . $node['number'] : '')
                                                    : $node['id'] . ($node['label'] !== '' ? ' ' . $node['label'] : '');
                                            ?>
                                                <?php if ($pi > 0): ?><i class="fas fa-arrow-right-long u-muted" style="font-size: 11px;"></i><?php endif; ?>
                                                <span style="display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 999px; border: 1px solid var(--border-color); background: var(--bg-input); <?php echo $node['answered'] ? '' : 'opacity: 0.6; border-style: dashed;'; ?>">
                                                    <i class="fas <?php echo $nicon; ?>" style="color: var(--primary); font-size: 11px;"></i> <?php echo htmlspecialchars($ntext); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Vertical Timeline Steps -->
                                        <div style="position: relative; padding-left: 24px; margin-left: 8px; border-left: 2px dashed var(--border-color);">
                                            <?php foreach ($c['legs'] as $leg):
                                                $info = $leg['leg_info'];
                                                $legHasRec = (!empty($leg['userfield']) && file_exists($leg['userfield']));
                                                $legTime = date('H:i:s', strtotime($leg['calldate']));
                                                $isAnsweredLeg = ($leg['disposition'] === 'ANSWERED');
                                                $nodeColor = $isAnsweredLeg ? 'var(--success)' : ($leg['disposition'] === 'BUSY' ? 'var(--info)' : 'var(--warning)');
                                                if ($leg['disposition'] === 'FAILED' || $leg['disposition'] === 'ABANDON') {
                                                    $nodeColor = 'var(--danger)';
                                                }
                                            ?>
                                                <div style="position: relative; margin-bottom: 10px;">
                                                    <!-- Node Dot -->
                                                    <div style="position: absolute; left: -31px; top: 5px; width: 14px; height: 14px; border-radius: 50%; background: var(--bg-card); border: 2px solid <?php echo $nodeColor; ?>; display: flex; align-items: center; justify-content: center;">
                                                        <div style="width: 6px; height: 6px; border-radius: 50%; background: <?php echo $nodeColor; ?>;"></div>
                                                    </div>

                                                    <!-- Step Box -->
                                                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; background: var(--bg-input); padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border-color);">
                                                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                                            <span style="font-family: monospace; font-size: 11px; font-weight: 700; color: var(--text-muted); background: var(--bg-card); padding: 2px 6px; border-radius: 4px; border: 1px solid var(--border-color);">
                                                                <?php echo $legTime; ?>
                                                            </span>
                                                            <span class="u-strong u-fs-12">
                                                                <i class="fas <?php echo $info['icon']; ?>" style="color: var(--primary); font-size: 11px; margin-right: 4px;"></i>
                                                                <?php echo htmlspecialchars($info['title']); ?>
                                                            </span>
                                                            <span class="badge <?php echo $info['badge']; ?>" style="font-size: 10px; padding: 2px 6px;">
                                                                <?php echo htmlspecialchars($info['badge_text']); ?>
                                                            </span>
                                                            <span class="u-muted u-fs-11">
                                                                <?php echo htmlspecialchars($info['detail']); ?>
                                                            </span>
                                                        </div>

                                                        <div style="display: flex; align-items: center; gap: 6px; white-space: nowrap;">
                                                            <?php if ($legHasRec && $can_listen_row): ?>
                                                                <button type="button" class="btn btn-secondary btn-sm" onclick="playCdrAudio(<?php echo $leg['id']; ?>, '<?php echo htmlspecialchars(addslashes($leg['src']), ENT_QUOTES); ?>', '<?php echo date('d.m.Y H:i', strtotime($leg['calldate'])); ?>')" title="<?php echo t('cdr_reports.listen_tooltip'); ?>" style="padding: 2px 8px; font-size: 11px;">
                                                                    <i class="fas fa-play"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                            <span style="font-size: 11px; color: var(--text-muted); font-family: monospace;">#<?php echo $leg['id']; ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php
        // Sistem genelinde tek sayfalama gorunumu (templates/pagination.php).
        $page = $sayfa;
        $page_size = $sayfa_boyutu;
        $total_pages = $toplam_sayfa;
        $total_rows = $stat_total;
        require dirname(__DIR__, 2) . '/pagination.php';
    ?>
</div>

<?php require dirname(__DIR__, 2) . '/cdr_audio_player.php'; ?>
