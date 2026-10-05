<?php
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$dur = function ($sec) {
    $sec = max(0, (int) $sec);
    return $sec >= 3600 ? sprintf('%d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60) : sprintf('%02d:%02d', intdiv($sec, 60), $sec % 60);
};
$url = function (array $override) {
    $q = array_merge($_GET, $override);
    return '/queue-reports?' . http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
};
$k = $kpis;
$agentLabel = fn($ext) => $ext . (isset($agent_names[$ext]) ? ' · ' . $agent_names[$ext] : '');
$series = [
    ['answered', t('queue_reports.answered'), 'var(--primary)'],
    ['lost', t('queue_reports.lost'), 'var(--danger)'],
];

/**
 * Stacked bar chart (inline SVG). $rows: label => [series key => value].
 * Answered and lost are stacked so the full bar is what was offered.
 */
$barChart = function (array $rows, array $series, string $ariaLabel, int $width = 640) use ($h) {
    $n = count($rows);
    if ($n === 0) {
        return '';
    }
    $max = 0;
    foreach ($rows as $vals) {
        $max = max($max, array_sum(array_map(fn($s) => (int) ($vals[$s[0]] ?? 0), $series)));
    }
    $mag = $max > 0 ? 10 ** floor(log10($max)) : 1;
    $top = $max > 0 ? max(1, ceil($max / $mag * 2) / 2 * $mag) : 1;
    $left = 34; $bottom = 22; $height = 190; $plotH = $height - $bottom - 8;
    // Drawing width close to the space the chart gets on screen: text keeps
    // its size and a few bars still fill the chart.
    $step = ($width - $left - 8) / $n;
    $barW = max(4, min(48, (int) round($step * 0.62)));
    $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" width="100%" style="display: block;" role="img" aria-label="' . $h($ariaLabel) . '">';
    foreach ([0, 0.5, 1] as $f) {
        $y = 8 + $plotH - $plotH * $f;
        $svg .= '<line x1="' . $left . '" x2="' . ($width - 4) . '" y1="' . $y . '" y2="' . $y . '" style="stroke: var(--border-color);" stroke-width="1"/>';
        $svg .= '<text x="' . ($left - 6) . '" y="' . ($y + 4) . '" text-anchor="end" font-size="10" style="fill: var(--text-muted);">' . (int) round($top * $f) . '</text>';
    }
    $i = 0;
    $labelEvery = (int) ceil($n / 31);
    foreach ($rows as $label => $vals) {
        $x = $left + $i * $step + ($step - $barW) / 2;
        $y = 8 + $plotH;
        $tip = [$label];
        foreach ($series as [$key, $sLabel, $color]) {
            $v = (int) ($vals[$key] ?? 0);
            $tip[] = $sLabel . ': ' . $v;
            if ($v <= 0) {
                continue;
            }
            $bh = $plotH * $v / $top;
            $y -= $bh;
            // 2px surface gap between stacked segments.
            $svg .= '<rect x="' . $x . '" y="' . ($y + 1) . '" width="' . $barW . '" height="' . max(1, $bh - 2) . '" rx="2" style="fill: ' . $color . ';"/>';
        }
        // Hit target: the whole column, bigger than the bar.
        $svg .= '<rect x="' . ($left + $i * $step) . '" y="8" width="' . $step . '" height="' . $plotH . '" fill="transparent"><title>' . $h(implode("\n", $tip)) . '</title></rect>';
        if ($i % $labelEvery === 0) {
            $svg .= '<text x="' . ($left + $i * $step + $step / 2) . '" y="' . ($height - 6) . '" text-anchor="middle" font-size="10" style="fill: var(--text-muted);">' . $h($label) . '</text>';
        }
        $i++;
    }
    return $svg . '</svg>';
};
$legend = function (array $series) use ($h) {
    $out = '<div class="u-flex-gap u-fs-12" style="flex-wrap: wrap; margin-bottom: 6px;">';
    foreach ($series as [, $label, $color]) {
        $out .= '<span style="display: inline-flex; align-items: center; gap: 6px;"><span style="width: 10px; height: 10px; border-radius: 2px; background: ' . $color . ';"></span>' . $h($label) . '</span>';
    }
    return $out . '</div>';
};

$tabs = [
    'overview' => ['fa-gauge-high', t('queue_reports.tab_overview')],
    'queues' => ['fa-layer-group', t('queue_reports.tab_queues')],
    'agents' => ['fa-headset', t('queue_reports.tab_agents')],
    'lost' => ['fa-phone-slash', t('queue_reports.tab_lost')],
    'repeat' => ['fa-rotate', t('queue_reports.tab_repeat')],
    'notes' => ['fa-clipboard-list', t('queue_reports.tab_notes')],
];
$tile = function (string $label, string $value, string $sub, string $color = 'var(--text-main)', string $hint = '') use ($h) {
    return '<div class="card u-mb-0" style="padding: 14px 16px;" title="' . $h($hint) . '">'
        . '<div class="u-muted u-fs-12 u-fw-600">' . $h($label) . '</div>'
        . '<div style="font-size: 24px; font-weight: 800; margin-top: 4px; color: ' . $color . ';">' . $value . '</div>'
        . '<div class="u-muted u-fs-11 u-mt-4">' . $sub . '</div></div>';
};
?>
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-chart-column u-primary"></i> <?php echo t('queue_reports.title'); ?></div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('qrHelpBox')" title="<?php echo t('common.module_guide'); ?>"><i class="fas fa-question-circle"></i></button>
    </div>
    <div class="module-help-box" id="qrHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('queue_reports.help_title'); ?></h4>
        <?php echo sprintf(t('queue_reports.help_body'), QueueStats::SHORT_ABANDON); ?>
    </div>

    <form method="GET" autocomplete="off" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center; background: var(--bg-input); padding: 14px 16px; border-radius: 12px; border: 1px solid var(--border-color);">
        <input type="hidden" name="tab" value="<?php echo $h($tab); ?>">
        <select name="date_range" class="form-control form-control-sm u-w-auto" onchange="document.getElementById('qrCustom').style.display = this.value === 'custom' ? 'flex' : 'none'; if (this.value !== 'custom') this.form.submit();">
            <?php foreach (['today', 'yesterday', 'week', 'month', 'custom'] as $r): ?>
                <option value="<?php echo $r; ?>" <?php echo $range === $r ? 'selected' : ''; ?>><?php echo t('cdr_reports.range_' . $r); ?></option>
            <?php endforeach; ?>
        </select>
        <div id="qrCustom" style="display: <?php echo $range === 'custom' ? 'flex' : 'none'; ?>; gap: 8px; align-items: center;">
            <input type="date" name="start_date" class="form-control form-control-sm u-w-auto" value="<?php echo $h($start_date); ?>">
            <span class="u-muted">-</span>
            <input type="date" name="end_date" class="form-control form-control-sm u-w-auto" value="<?php echo $h($end_date); ?>">
        </div>
        <select name="queue" class="form-control form-control-sm u-w-auto" onchange="this.form.submit()">
            <option value=""><?php echo t('queue_reports.all_queues'); ?></option>
            <?php foreach ($queues as $qn => $qt): ?>
                <option value="<?php echo $h($qn); ?>" <?php echo $queue === $qn ? 'selected' : ''; ?>><?php echo $h($qt); ?></option>
            <?php endforeach; ?>
        </select>
        <label class="u-fs-12 u-muted" style="display: inline-flex; align-items: center; gap: 6px;" title="<?php echo $h(t('queue_reports.sl_hint')); ?>">
            <?php echo t('queue_reports.sl_target'); ?>
            <input type="number" name="sl" min="5" max="300" value="<?php echo (int) $sl; ?>" class="form-control form-control-sm" style="width: 72px;"> <?php echo t('queue_reports.seconds_short'); ?>
        </label>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i></button>
        <span class="u-muted u-fs-12"><?php echo date('d.m.Y', $from); ?><?php echo date('Y-m-d', $from) !== date('Y-m-d', $to) ? ' – ' . date('d.m.Y', $to) : ''; ?></span>
        <span style="margin-left: auto; display: inline-flex; gap: 6px;"><?php require dirname(__DIR__, 2) . '/export_buttons.php'; ?></span>
    </form>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 20px;">
    <?php
    echo $tile(t('queue_reports.kpi_offered'), (string) $k['offered'], t('queue_reports.kpi_offered_sub'));
    echo $tile(t('queue_reports.kpi_answered'), $k['answered'] . ' <span class="u-fs-12 u-muted">%' . $k['answer_rate'] . '</span>', t('queue_reports.kpi_answered_sub'), 'var(--success)');
    echo $tile(t('queue_reports.kpi_lost'), $k['lost'] . ' <span class="u-fs-12 u-muted">%' . $k['lost_rate'] . '</span>', sprintf(t('queue_reports.kpi_lost_sub'), $k['short_abandons'], QueueStats::SHORT_ABANDON), $k['lost'] ? 'var(--danger)' : 'var(--text-main)');
    echo $tile(t('queue_reports.kpi_sl'), '%' . $k['service_level'], sprintf(t('queue_reports.kpi_sl_sub'), $sl), 'var(--primary)', t('queue_reports.sl_hint'));
    echo $tile(t('queue_reports.kpi_asa'), $dur($k['asa']), sprintf(t('queue_reports.kpi_max_wait'), $dur($k['max_wait'])), 'var(--text-main)', t('queue_reports.asa_hint'));
    echo $tile(t('queue_reports.kpi_aht'), $dur($k['aht']), sprintf(t('queue_reports.kpi_talk_total'), $dur($k['talk_total'])), 'var(--text-main)', t('queue_reports.aht_hint'));
    if (isset($unresolved)) {
        echo '<a href="' . $h($url(['tab' => 'lost', 'unresolved' => '1'])) . '" style="text-decoration: none;">'
            . $tile(t('queue_reports.kpi_unresolved'), (string) $unresolved, t('queue_reports.kpi_unresolved_sub'), $unresolved ? 'var(--warning)' : 'var(--success)')
            . '</a>';
    }
    ?>
</div>

<div class="card">
    <div style="display: flex; gap: 4px; flex-wrap: wrap; border-bottom: 1px solid var(--border-color); margin-bottom: 16px;">
        <?php foreach ($tabs as $tk => [$ticon, $tlabel]): ?>
            <a href="<?php echo $h($url(['tab' => $tk, 'unresolved' => null])); ?>"
               style="padding: 10px 14px; font-size: 13px; font-weight: 600; text-decoration: none; border-bottom: 2px solid <?php echo $tab === $tk ? 'var(--primary)' : 'transparent'; ?>; color: <?php echo $tab === $tk ? 'var(--primary)' : 'var(--text-muted)'; ?>;">
                <i class="fas <?php echo $ticon; ?>"></i> <?php echo $tlabel; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($k['offered'] === 0 && !in_array($tab, ['agents', 'notes'], true)): ?>
        <div class="u-muted u-text-center u-p-24"><?php echo t('queue_reports.empty'); ?></div>

    <?php elseif ($tab === 'overview'):
        $hours = $dist['hours'];
        $active = array_keys(array_filter($hours, fn($v) => $v['offered'] > 0));
        $hourRows = [];
        if ($active) {
            for ($i = min($active); $i <= max($active); $i++) {
                $hourRows[sprintf('%02d', $i)] = $hours[$i];
            }
        }
        $bucketRows = [];
        foreach ($buckets as $b) {
            $bucketRows[$b['to'] === null ? $b['from'] . '+' : $b['from'] . '-' . $b['to']] = $b;
        }
    ?>
        <div class="u-grid-2" style="gap: 24px;">
            <div>
                <div class="u-strong u-mb-10"><?php echo t('queue_reports.chart_hourly'); ?></div>
                <?php echo $legend($series); ?>
                <?php echo $barChart($hourRows, $series, t('queue_reports.chart_hourly')); ?>
            </div>
            <div>
                <div class="u-strong u-mb-10"><?php echo t('queue_reports.chart_wait'); ?></div>
                <?php echo $legend($series); ?>
                <?php echo $barChart($bucketRows, $series, t('queue_reports.chart_wait')); ?>
                <div class="u-muted u-fs-11"><?php echo t('queue_reports.chart_wait_axis'); ?></div>
            </div>
        </div>
        <?php if (count($dist['days']) > 1):
            $dayRows = [];
            foreach ($dist['days'] as $d => $v) {
                $dayRows[date('d.m', strtotime($d))] = $v;
            }
        ?>
            <div style="margin-top: 24px;">
                <div class="u-strong u-mb-10"><?php echo t('queue_reports.chart_daily'); ?></div>
                <?php echo $legend($series); ?>
                <?php echo $barChart($dayRows, $series, t('queue_reports.chart_daily'), 1320); ?>
            </div>
        <?php endif; ?>
        <details style="margin-top: 16px;">
            <summary class="u-fs-12 u-muted u-pointer"><?php echo t('queue_reports.as_table'); ?></summary>
            <div class="table-responsive" style="margin-top: 8px;">
                <table class="data-table" data-no-dt="true">
                    <thead><tr><th><?php echo t('queue_reports.col_hour'); ?></th><th><?php echo t('queue_reports.col_offered'); ?></th><th><?php echo t('queue_reports.answered'); ?></th><th><?php echo t('queue_reports.lost'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($hourRows as $hr => $v): ?>
                        <tr><td><?php echo $hr; ?>:00</td><td><?php echo $v['offered']; ?></td><td><?php echo $v['answered']; ?></td><td><?php echo $v['lost']; ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>

    <?php elseif ($tab === 'queues'): ?>
        <div class="table-responsive">
            <table class="data-table" data-no-dt="true">
                <thead><tr>
                    <th><?php echo t('queue_reports.col_queue'); ?></th><th><?php echo t('queue_reports.col_offered'); ?></th><th><?php echo t('queue_reports.answered'); ?></th>
                    <th><?php echo t('queue_reports.lost'); ?></th><th><?php echo sprintf(t('queue_reports.col_sl'), $sl); ?></th>
                    <th title="<?php echo $h(t('queue_reports.asa_hint')); ?>">ASA</th><th title="<?php echo $h(t('queue_reports.aht_hint')); ?>">AHT</th>
                    <th><?php echo t('queue_reports.col_max_wait'); ?></th><th><?php echo t('queue_reports.col_lost_wait'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($by_queue as $qn => $q): ?>
                    <tr>
                        <td class="u-strong"><?php echo $h($queues[$qn] ?? $qn); ?></td>
                        <td><?php echo $q['offered']; ?></td>
                        <td><?php echo $q['answered']; ?> <span class="u-muted u-fs-11">%<?php echo $q['answer_rate']; ?></span></td>
                        <td><?php echo $q['lost']; ?> <span class="u-muted u-fs-11">%<?php echo $q['lost_rate']; ?></span></td>
                        <td class="u-strong">%<?php echo $q['service_level']; ?></td>
                        <td><?php echo $dur($q['asa']); ?></td><td><?php echo $dur($q['aht']); ?></td>
                        <td><?php echo $dur($q['max_wait']); ?></td><td><?php echo $dur($q['avg_lost_wait']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($tab === 'agents'): ?>
        <?php if (!$by_agent): ?>
            <div class="u-muted u-text-center u-p-24"><?php echo t('queue_reports.empty'); ?></div>
        <?php else: $maxAnswered = max(1, max(array_column($by_agent, 'answered'))); ?>
        <div class="table-responsive">
            <table class="data-table" data-no-dt="true">
                <thead><tr>
                    <th><?php echo t('queue_reports.col_agent'); ?></th>
                    <th><?php echo t('queue_reports.answered'); ?></th>
                    <th><?php echo t('queue_reports.col_talk_total'); ?></th>
                    <th title="<?php echo $h(t('queue_reports.aht_hint')); ?>">AHT</th>
                    <th><?php echo t('queue_reports.col_talk_max'); ?></th>
                    <th title="<?php echo $h(t('queue_reports.avg_ring_hint')); ?>"><?php echo t('queue_reports.col_avg_ring'); ?></th>
                    <th title="<?php echo $h(t('queue_reports.missed_hint')); ?>"><?php echo t('queue_reports.col_missed'); ?></th>
                    <th title="<?php echo $h(t('queue_reports.pickup_hint')); ?>"><?php echo t('queue_reports.col_pickup'); ?></th>
                    <th title="<?php echo $h(t('queue_reports.agent_hangup_hint')); ?>"><?php echo t('queue_reports.col_agent_hangup'); ?></th>
                    <th><?php echo t('queue_reports.col_pause'); ?></th>
                    <th><?php echo t('queue_reports.col_notes'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($by_agent as $ext => $a): ?>
                    <tr>
                        <td class="u-strong"><?php echo $h($agentLabel($ext)); ?></td>
                        <td style="min-width: 140px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span class="u-strong" style="min-width: 28px;"><?php echo $a['answered']; ?></span>
                                <span style="flex: 1; height: 6px; background: var(--bg-input); border-radius: 3px; overflow: hidden;"><span style="display: block; height: 100%; width: <?php echo round($a['answered'] * 100 / $maxAnswered); ?>%; background: var(--primary); border-radius: 3px;"></span></span>
                                <span class="u-muted u-fs-11">%<?php echo $a['share']; ?></span>
                            </div>
                        </td>
                        <td><?php echo $dur($a['talk_total']); ?></td>
                        <td><?php echo $dur($a['aht']); ?></td>
                        <td><?php echo $dur($a['talk_max']); ?></td>
                        <td><?php echo $a['avg_ring']; ?> <?php echo t('queue_reports.seconds_short'); ?></td>
                        <td><?php echo $a['missed_rings']; ?></td>
                        <td class="<?php echo $a['pickup_rate'] < 70 && ($a['answered'] + $a['missed_rings']) > 0 ? 'u-warning' : ''; ?> u-strong">%<?php echo $a['pickup_rate']; ?></td>
                        <td><?php echo $a['agent_hangups']; ?></td>
                        <td><?php echo $a['pause_count'] ? $a['pause_count'] . ' · ' . $dur($a['pause_seconds']) : '-'; ?></td>
                        <td><?php echo $a['notes'] ?: '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    <?php elseif ($tab === 'lost'):
        $onlyOpen = !empty($_GET['unresolved']);
        $list = $onlyOpen ? array_values(array_filter($lost, fn($l) => $l['resolved_at'] === null)) : $lost;
    ?>
        <div class="u-flex-gap u-mb-10" style="align-items: center;">
            <a href="<?php echo $h($url(['unresolved' => $onlyOpen ? null : '1'])); ?>" class="btn btn-sm <?php echo $onlyOpen ? 'btn-primary' : 'btn-secondary'; ?>">
                <i class="fas fa-filter"></i> <?php echo sprintf(t('queue_reports.only_unresolved'), $unresolved); ?>
            </a>
            <span class="u-muted u-fs-12"><?php echo t('queue_reports.lost_hint'); ?></span>
        </div>
        <div class="table-responsive">
            <table class="data-table" data-no-dt="true">
                <thead><tr>
                    <th><?php echo t('queue_reports.col_time'); ?></th><th><?php echo t('queue_reports.col_caller'); ?></th><th><?php echo t('queue_reports.col_queue'); ?></th>
                    <th><?php echo t('queue_reports.col_wait'); ?></th><th><?php echo t('queue_reports.col_reason'); ?></th><th><?php echo t('queue_reports.col_callback'); ?></th>
                </tr></thead>
                <tbody>
                <?php if (!$list): ?>
                    <tr><td colspan="6" class="u-muted u-text-center u-p-24"><?php echo t('queue_reports.no_lost'); ?></td></tr>
                <?php endif; ?>
                <?php foreach (array_slice($list, 0, 500) as $l): ?>
                    <tr>
                        <td class="u-nowrap"><?php echo date('d.m.Y H:i:s', $l['enter_ts']); ?></td>
                        <td class="u-strong"><?php echo $h($l['caller'] ?: '-'); ?></td>
                        <td><?php echo $h($queues[$l['queue_name']] ?? $l['queue_name']); ?></td>
                        <td><?php echo $dur($l['wait']); ?><?php echo $l['wait'] < QueueStats::SHORT_ABANDON ? ' <span class="badge badge-secondary u-fs-10">' . t('queue_reports.short') . '</span>' : ''; ?></td>
                        <td><?php echo t($l['lost'] === 'abandon' ? 'queue_reports.reason_abandon' : 'queue_reports.reason_timeout'); ?></td>
                        <td>
                            <?php if ($l['resolved_at'] !== null): ?>
                                <span class="u-success u-fs-12"><i class="fas fa-check"></i> <?php echo t('queue_reports.resolved_' . $l['resolved_how']); ?> · <?php echo date('d.m H:i', $l['resolved_at']); ?></span>
                            <?php else: ?>
                                <span class="badge badge-warning u-fs-11"><i class="fas fa-triangle-exclamation"></i> <?php echo t('queue_reports.unresolved'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($list) > 500): ?><div class="u-muted u-fs-12 u-mt-10"><?php echo sprintf(t('queue_reports.truncated'), count($list)); ?></div><?php endif; ?>

    <?php elseif ($tab === 'repeat'): ?>
        <div class="u-muted u-fs-12 u-mb-10"><?php echo t('queue_reports.repeat_hint'); ?></div>
        <div class="table-responsive">
            <table class="data-table" data-no-dt="true">
                <thead><tr>
                    <th><?php echo t('queue_reports.col_caller'); ?></th><th><?php echo t('queue_reports.col_calls'); ?></th><th><?php echo t('queue_reports.answered'); ?></th>
                    <th><?php echo t('queue_reports.lost'); ?></th><th><?php echo t('queue_reports.col_first'); ?></th><th><?php echo t('queue_reports.col_last'); ?></th>
                </tr></thead>
                <tbody>
                <?php if (!$repeat): ?>
                    <tr><td colspan="6" class="u-muted u-text-center u-p-24"><?php echo t('queue_reports.no_repeat'); ?></td></tr>
                <?php endif; ?>
                <?php foreach (array_slice($repeat, 0, 300) as $r): ?>
                    <tr>
                        <td class="u-strong"><a href="/cdr-reports?<?php echo $h(http_build_query(['date_range' => 'custom', 'start_date' => date('Y-m-d', $r['first']), 'end_date' => date('Y-m-d', $r['last']), 'search' => $r['caller']])); ?>"><?php echo $h($r['caller']); ?></a></td>
                        <td class="u-strong"><?php echo $r['calls']; ?></td>
                        <td><?php echo $r['answered']; ?></td>
                        <td class="<?php echo $r['lost'] ? 'u-danger' : ''; ?>"><?php echo $r['lost']; ?></td>
                        <td><?php echo date('d.m.Y H:i', $r['first']); ?></td>
                        <td><?php echo date('d.m.Y H:i', $r['last']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($tab === 'notes'):
        $perDisp = $notes['per_disposition'];
        $exts = array_keys($notes['per_agent']);
        sort($exts);
    ?>
        <?php if (!$perDisp): ?>
            <div class="u-muted u-text-center u-p-24"><?php echo t('queue_reports.no_notes'); ?></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table" data-no-dt="true">
                <thead><tr>
                    <th><?php echo t('queue_reports.col_disposition'); ?></th>
                    <?php foreach ($exts as $e): ?><th><?php echo $h($agentLabel($e)); ?></th><?php endforeach; ?>
                    <th><?php echo t('queue_reports.col_total'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($perDisp as $disp => $per): ?>
                    <tr>
                        <td class="u-strong"><?php echo $h($disp === '-' ? t('queue_reports.no_disposition') : $disp); ?></td>
                        <?php foreach ($exts as $e): ?><td><?php echo $per[$e] ?? '-'; ?></td><?php endforeach; ?>
                        <td class="u-strong"><?php echo array_sum($per); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
