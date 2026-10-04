<?php
require_once __DIR__ . '/../repositories/QueueReportRepository.php';
require_once __DIR__ . '/../services/QueueStats.php';
require_once __DIR__ . '/../services/ReportExport.php';

/**
 * /queue-reports — Queue Report Centre: service level, queues, agents,
 * lost calls and call-backs, repeat callers, call notes.
 */
class QueueReportController extends BaseController
{
    public const TABS = ['overview', 'queues', 'agents', 'lost', 'repeat', 'notes'];

    public static function index(): void
    {
        requireLogin();
        static::requireModule('queue_reports', 'view');

        [$from, $to, $range, $startDate, $endDate] = static::period();
        $queues = QueueReportRepository::queues();
        $queue = trim($_GET['queue'] ?? '');
        if (!isset($queues[$queue])) {
            $queue = '';
        }
        $sl = max(5, min(300, intval($_GET['sl'] ?? 20)));
        $tab = in_array($_GET['tab'] ?? '', self::TABS, true) ? $_GET['tab'] : 'overview';

        $calls = QueueReportRepository::calls($from, $to, $queue !== '' ? [$queue] : []);
        $agentNames = QueueReportRepository::agentNames();

        $data = [
            'tab' => $tab, 'range' => $range, 'start_date' => $startDate, 'end_date' => $endDate,
            'from' => $from, 'to' => $to, 'queue' => $queue, 'queues' => $queues, 'sl' => $sl,
            'agent_names' => $agentNames,
            'kpis' => QueueStats::kpis($calls, $sl),
        ];

        // Every tab shows the "not called back" tile, so it is always computed.
        $lostCallers = array_column(array_filter($calls, fn($c) => !$c['answered'] && $c['lost'] !== ''), 'caller');
        $lost = QueueStats::lostCalls($calls, QueueReportRepository::outgoingTo($lostCallers, $from));
        $data['unresolved'] = count(array_filter($lost, fn($l) => $l['resolved_at'] === null));

        switch ($tab) {
            case 'overview':
                $data['dist'] = QueueStats::distribution($calls);
                $data['buckets'] = QueueStats::waitBuckets($calls);
                break;
            case 'queues':
                $data['by_queue'] = QueueStats::byQueue($calls, $sl);
                break;
            case 'agents':
                $notes = QueueReportRepository::notes($from, $to);
                $data['by_agent'] = QueueStats::byAgent($calls, QueueReportRepository::ringMisses($from, $to, $queue !== '' ? [$queue] : []),
                    QueueReportRepository::pauses($from, $to), $notes['per_agent']);
                break;
            case 'lost':
                $data['lost'] = $lost;
                break;
            case 'repeat':
                $data['repeat'] = QueueStats::repeatCallers($calls);
                break;
            case 'notes':
                $data['notes'] = QueueReportRepository::notes($from, $to);
                break;
        }

        $format = $_GET['export'] ?? '';
        if (in_array($format, ['pdf', 'xlsx'], true)) {
            // The export is the whole report, whatever tab is open.
            $notes = QueueReportRepository::notes($from, $to);
            $data['dist'] = QueueStats::distribution($calls);
            $data['buckets'] = QueueStats::waitBuckets($calls);
            $data['by_queue'] = QueueStats::byQueue($calls, $sl);
            $data['by_agent'] = QueueStats::byAgent($calls, QueueReportRepository::ringMisses($from, $to, $queue !== '' ? [$queue] : []),
                QueueReportRepository::pauses($from, $to), $notes['per_agent']);
            $data['lost'] = $lost;
            $data['repeat'] = QueueStats::repeatCallers($calls);
            $data['notes'] = $notes;
            ReportExport::send(static::exportDoc($data), $format, 'queue-report');
        }

        static::renderPage('queue_reports/index', $data, ['title' => t('queue_reports.title')]);
    }

    /** @return array{0: int, 1: int, 2: string, 3: string, 4: string} */
    protected static function period(): array
    {
        $range = trim($_GET['date_range'] ?? 'today');
        $startDate = trim($_GET['start_date'] ?? '');
        $endDate = trim($_GET['end_date'] ?? '');
        $today = strtotime('today');
        switch ($range) {
            case 'yesterday':
                return [$today - 86400, $today - 1, $range, $startDate, $endDate];
            case 'week':
                return [$today - 6 * 86400, $today + 86399, $range, $startDate, $endDate];
            case 'month':
                return [$today - 29 * 86400, $today + 86399, $range, $startDate, $endDate];
            case 'custom':
                $s = strtotime($startDate);
                $e = strtotime($endDate);
                if ($s && $e && $e >= $s) {
                    // At most a year: the report is computed per call in memory.
                    $s = max($s, $e - 365 * 86400);
                    return [$s, $e + 86399, $range, date('Y-m-d', $s), $endDate];
                }
                // fall through to today
            default:
                return [$today, $today + 86399, 'today', $startDate, $endDate];
        }
    }

    protected static function exportDoc(array $d): array
    {
        $k = $d['kpis'];
        $names = $d['agent_names'];
        $qt = fn($q) => $d['queues'][$q] ?? $q;
        $series = fn(array $rows) => [
            [t('queue_reports.answered'), 'primary', array_column($rows, 'answered')],
            [t('queue_reports.lost'), 'danger', array_column($rows, 'lost')],
        ];

        $hours = $d['dist']['hours'];
        $active = array_keys(array_filter($hours, fn($v) => $v['offered'] > 0));
        $hourRows = [];
        if ($active) {
            for ($i = min($active); $i <= max($active); $i++) {
                $hourRows[sprintf('%02d:00', $i)] = $hours[$i];
            }
        }
        $bucketLabel = fn($b) => $b['to'] === null ? $b['from'] . '+ ' . t('queue_reports.seconds_short') : $b['from'] . '-' . $b['to'] . ' ' . t('queue_reports.seconds_short');

        $sections = [];
        $sections[] = [
            'title' => t('queue_reports.chart_hourly'),
            'chart' => ['labels' => array_keys($hourRows), 'series' => $series(array_values($hourRows))],
            'columns' => [[t('queue_reports.col_hour'), 'text'], [t('queue_reports.col_offered'), 'int'], [t('queue_reports.answered'), 'int'], [t('queue_reports.lost'), 'int']],
            'rows' => array_map(fn($h, $v) => [$h, $v['offered'], $v['answered'], $v['lost']], array_keys($hourRows), array_values($hourRows)),
        ];
        if (count($d['dist']['days']) > 1) {
            $days = $d['dist']['days'];
            $sections[] = [
                'title' => t('queue_reports.chart_daily'),
                'chart' => ['labels' => array_map(fn($x) => date('d.m', strtotime($x)), array_keys($days)), 'series' => $series(array_values($days))],
                'columns' => [[t('export.col_date'), 'text'], [t('queue_reports.col_offered'), 'int'], [t('queue_reports.answered'), 'int'], [t('queue_reports.lost'), 'int']],
                'rows' => array_map(fn($x, $v) => [date('d.m.Y', strtotime($x)), $v['offered'], $v['answered'], $v['lost']], array_keys($days), array_values($days)),
            ];
        }
        $sections[] = [
            'title' => t('queue_reports.chart_wait'),
            'chart' => ['labels' => array_map($bucketLabel, $d['buckets']), 'series' => $series($d['buckets'])],
            'columns' => [[t('queue_reports.col_wait'), 'text'], [t('queue_reports.answered'), 'int'], [t('queue_reports.lost'), 'int']],
            'rows' => array_map(fn($b) => [$bucketLabel($b), $b['answered'], $b['lost']], $d['buckets']),
        ];
        $sections[] = [
            'title' => t('queue_reports.tab_queues'),
            'columns' => [[t('queue_reports.col_queue'), 'text'], [t('queue_reports.col_offered'), 'int'], [t('queue_reports.answered'), 'int'], [t('queue_reports.lost'), 'int'],
                [t('export.col_answer_rate'), 'pct'], [sprintf(t('queue_reports.col_sl'), $d['sl']), 'pct'], ['ASA', 'dur'], ['AHT', 'dur'],
                [t('queue_reports.col_max_wait'), 'dur'], [t('queue_reports.col_lost_wait'), 'dur']],
            'rows' => array_map(fn($q, $v) => [$qt($q), $v['offered'], $v['answered'], $v['lost'], $v['answer_rate'], $v['service_level'], $v['asa'], $v['aht'], $v['max_wait'], $v['avg_lost_wait']],
                array_keys($d['by_queue']), array_values($d['by_queue'])),
        ];
        $sections[] = [
            'title' => t('queue_reports.tab_agents'),
            'columns' => [[t('queue_reports.col_agent'), 'text'], [t('export.col_name'), 'text'], [t('queue_reports.answered'), 'int'], [t('export.col_share'), 'pct'],
                [t('queue_reports.col_talk_total'), 'dur'], ['AHT', 'dur'], [t('queue_reports.col_talk_max'), 'dur'], [t('queue_reports.col_avg_ring'), 'dur'],
                [t('queue_reports.col_missed'), 'int'], [t('queue_reports.col_pickup'), 'pct'], [t('queue_reports.col_agent_hangup'), 'int'],
                [t('export.col_pause_count'), 'int'], [t('export.col_pause_time'), 'dur'], [t('queue_reports.col_notes'), 'int']],
            'rows' => array_map(fn($ext, $a) => [(string) $ext, $names[$ext] ?? '', $a['answered'], $a['share'], $a['talk_total'], $a['aht'], $a['talk_max'], $a['avg_ring'],
                $a['missed_rings'], $a['pickup_rate'], $a['agent_hangups'], $a['pause_count'], $a['pause_seconds'], $a['notes']], array_keys($d['by_agent']), array_values($d['by_agent'])),
        ];
        $sections[] = [
            'title' => t('queue_reports.tab_lost'),
            'note' => t('queue_reports.lost_hint'),
            'columns' => [[t('queue_reports.col_time'), 'datetime'], [t('queue_reports.col_caller'), 'text'], [t('queue_reports.col_queue'), 'text'],
                [t('queue_reports.col_wait'), 'dur'], [t('queue_reports.col_reason'), 'text'], [t('queue_reports.col_callback'), 'text'], [t('export.col_callback_time'), 'datetime']],
            'rows' => array_map(fn($l) => [$l['enter_ts'], $l['caller'], $qt($l['queue_name']), $l['wait'],
                t($l['lost'] === 'abandon' ? 'queue_reports.reason_abandon' : 'queue_reports.reason_timeout'),
                $l['resolved_at'] !== null ? t('queue_reports.resolved_' . $l['resolved_how']) : t('queue_reports.unresolved'), $l['resolved_at']], $d['lost']),
        ];
        $sections[] = [
            'title' => t('queue_reports.tab_repeat'),
            'note' => t('queue_reports.repeat_hint'),
            'columns' => [[t('queue_reports.col_caller'), 'text'], [t('queue_reports.col_calls'), 'int'], [t('queue_reports.answered'), 'int'], [t('queue_reports.lost'), 'int'],
                [t('queue_reports.col_first'), 'datetime'], [t('queue_reports.col_last'), 'datetime']],
            'rows' => array_map(fn($r) => [$r['caller'], $r['calls'], $r['answered'], $r['lost'], $r['first'], $r['last']], $d['repeat']),
        ];
        $noteRows = [];
        foreach ($d['notes']['per_disposition'] as $disp => $per) {
            foreach ($per as $ext => $n) {
                $noteRows[] = [$disp === '-' ? t('queue_reports.no_disposition') : $disp, (string) $ext, $names[$ext] ?? '', $n];
            }
        }
        $sections[] = [
            'title' => t('queue_reports.tab_notes'),
            'columns' => [[t('queue_reports.col_disposition'), 'text'], [t('queue_reports.col_agent'), 'text'], [t('export.col_name'), 'text'], [t('export.col_count'), 'int']],
            'rows' => $noteRows,
        ];

        return [
            'title' => t('queue_reports.title'),
            'period' => date('d.m.Y', $d['from']) . (date('Ymd', $d['from']) !== date('Ymd', $d['to']) ? ' – ' . date('d.m.Y', $d['to']) : ''),
            'filters' => array_filter([
                t('queue_reports.col_queue') => $d['queue'] !== '' ? $qt($d['queue']) : '',
                t('queue_reports.sl_target') => $d['sl'] . ' ' . t('queue_reports.seconds_short'),
            ]),
            'kpis' => [
                [t('queue_reports.kpi_offered'), (string) $k['offered'], ''],
                [t('queue_reports.kpi_answered'), (string) $k['answered'], ReportExport::format($k['answer_rate'], 'pct')],
                [t('queue_reports.kpi_lost'), (string) $k['lost'], ReportExport::format($k['lost_rate'], 'pct')],
                [t('queue_reports.kpi_sl'), ReportExport::format($k['service_level'], 'pct'), sprintf(t('queue_reports.kpi_sl_sub'), $d['sl'])],
                [t('queue_reports.kpi_asa'), ReportExport::format($k['asa'], 'dur'), sprintf(t('queue_reports.kpi_max_wait'), ReportExport::format($k['max_wait'], 'dur'))],
                [t('queue_reports.kpi_aht'), ReportExport::format($k['aht'], 'dur'), sprintf(t('queue_reports.kpi_talk_total'), ReportExport::format($k['talk_total'], 'dur'))],
                [t('queue_reports.kpi_unresolved'), (string) ($d['unresolved'] ?? 0), t('queue_reports.kpi_unresolved_sub')],
            ],
            'sections' => $sections,
        ];
    }
}
