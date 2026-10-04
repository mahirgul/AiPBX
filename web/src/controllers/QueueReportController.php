<?php
require_once __DIR__ . '/../repositories/QueueReportRepository.php';
require_once __DIR__ . '/../services/QueueStats.php';

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

        if (($_GET['export'] ?? '') === 'csv') {
            static::csv($tab, $data);
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

    protected static function csv(string $tab, array $d): void
    {
        $rows = [];
        $fmt = fn($ts) => $ts ? date('Y-m-d H:i:s', (int) $ts) : '';
        switch ($tab) {
            case 'overview':
            case 'queues':
                $rows[] = ['queue', 'offered', 'answered', 'lost', 'short_abandons', 'answer_rate', 'service_level', 'asa', 'aht', 'max_wait', 'avg_lost_wait'];
                $groups = $tab === 'queues' ? $d['by_queue'] : ['*' => $d['kpis']];
                foreach ($groups as $q => $k) {
                    $rows[] = [$d['queues'][$q] ?? $q, $k['offered'], $k['answered'], $k['lost'], $k['short_abandons'], $k['answer_rate'], $k['service_level'], $k['asa'], $k['aht'], $k['max_wait'], $k['avg_lost_wait']];
                }
                break;
            case 'agents':
                $rows[] = ['extension', 'name', 'answered', 'share', 'talk_total', 'aht', 'talk_max', 'avg_ring', 'missed_rings', 'pickup_rate', 'agent_hangups', 'transfers', 'pause_count', 'pause_seconds', 'notes'];
                foreach ($d['by_agent'] as $ext => $a) {
                    $rows[] = [$ext, $d['agent_names'][$ext] ?? '', $a['answered'], $a['share'], $a['talk_total'], $a['aht'], $a['talk_max'], $a['avg_ring'], $a['missed_rings'], $a['pickup_rate'], $a['agent_hangups'], $a['transfers'], $a['pause_count'], $a['pause_seconds'], $a['notes']];
                }
                break;
            case 'lost':
                $rows[] = ['time', 'queue', 'caller', 'wait', 'reason', 'resolved', 'resolved_at'];
                foreach ($d['lost'] as $l) {
                    $rows[] = [$fmt($l['enter_ts']), $d['queues'][$l['queue_name']] ?? $l['queue_name'], $l['caller'], $l['wait'], $l['lost'], $l['resolved_how'], $fmt($l['resolved_at'])];
                }
                break;
            case 'repeat':
                $rows[] = ['caller', 'calls', 'answered', 'lost', 'first', 'last'];
                foreach ($d['repeat'] as $r) {
                    $rows[] = [$r['caller'], $r['calls'], $r['answered'], $r['lost'], $fmt($r['first']), $fmt($r['last'])];
                }
                break;
            case 'notes':
                $rows[] = ['disposition', 'extension', 'name', 'count'];
                foreach ($d['notes']['per_disposition'] as $disp => $per) {
                    foreach ($per as $ext => $n) {
                        $rows[] = [$disp, $ext, $d['agent_names'][$ext] ?? '', $n];
                    }
                }
                break;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="queue-report-' . $tab . '-' . date('Ymd', $d['from']) . '-' . date('Ymd', $d['to']) . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");   // BOM: Excel opens UTF-8 (Turkish characters) correctly
        foreach ($rows as $r) {
            // A leading = + - @ would make Excel evaluate the cell (CSV injection).
            fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $r), ';', '"', '');
        }
        fclose($out);
        exit;
    }
}
