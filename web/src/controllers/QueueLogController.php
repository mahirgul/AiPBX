<?php
require_once __DIR__ . '/../services/ReportExport.php';

class QueueLogController extends BaseController
{
    public static function index(): void
    {
        static::requireRole(['admin', 'cc_manager']);

        $agent_map = QueueLogRepository::agentMap();

        $event_filter = trim($_GET['event'] ?? '');
        $agent_filter = trim($_GET['agent'] ?? '');
        $search_query = trim($_GET['search'] ?? '');
        $date_filter = trim($_GET['date_range'] ?? 'today');
        $view_mode = trim($_GET['view_mode'] ?? 'grouped');
        if ($view_mode !== 'raw') {
            $view_mode = 'grouped';
        }

        // Determine date boundary
        $start_ts = 0;
        $end_ts = time();

        if ($date_filter === 'today') {
            $start_ts = strtotime('today midnight');
        } elseif ($date_filter === 'yesterday') {
            $start_ts = strtotime('yesterday midnight');
            $end_ts = strtotime('today midnight') - 1;
        } elseif ($date_filter === 'week') {
            $start_ts = strtotime('-7 days midnight');
        } elseif ($date_filter === 'month') {
            $start_ts = strtotime('-30 days midnight');
        }

        $sayfa = max(1, intval($_GET['page'] ?? 1));
        $sayfa_boyutu = View::sayfaBoyutu(50);

        // Ensure latest logs are synced to DB
        QueueLogRepository::syncLatest();

        $result = QueueLogRepository::searchAndParse($start_ts, $end_ts, $event_filter, $agent_filter, $search_query, $agent_map, $view_mode, $sayfa, $sayfa_boyutu);

        $format = $_GET['export'] ?? '';
        if (in_array($format, ['pdf', 'xlsx'], true)) {
            $limit = $format === 'pdf' ? ReportExport::PDF_MAX_ROWS : ReportExport::XLSX_MAX_ROWS;
            $all = QueueLogRepository::searchAndParse($start_ts, $end_ts, $event_filter, $agent_filter, $search_query, $agent_map, $view_mode, 1, $limit);
            ReportExport::send(static::exportDoc($all, $view_mode, $start_ts, $end_ts, array_filter([
                t('queue_logs.col_event') => $event_filter,
                t('queue_logs.col_agent') => $agent_filter,
                t('export.f_search') => $search_query,
            ])), $format, 'queue-log');
        }

        $total_records = (int)($result['total'] ?? count($result['logs']));
        $toplam_sayfa = max(1, (int)ceil($total_records / $sayfa_boyutu));
        if ($sayfa > $toplam_sayfa) {
            $sayfa = $toplam_sayfa;
        }

        $page_title = t('queue_logs.title');
        // Recordings: same rule as /api/cc_audio.php (admin, "can listen" users, or their own calls).
        $user = getCurrentUser();
        $can_listen_all = ($_SESSION['user_role'] ?? '') === 'admin' || !empty($user['can_listen_recordings']);

        static::renderPage('queue_logs/index', [
            'can_listen_all' => $can_listen_all,
            'user_ext' => $user['extension'] ?? '',
            'agent_map' => $agent_map,
            'event_filter' => $event_filter,
            'agent_filter' => $agent_filter,
            'search_query' => $search_query,
            'date_filter' => $date_filter,
            'view_mode' => $view_mode,
            'sayfa' => $sayfa,
            'toplam_sayfa' => $toplam_sayfa,
            'sayfa_boyutu' => $sayfa_boyutu,
            'total_records' => $total_records,
            'parsed_logs' => $result['logs'],
            'stat_total_enter' => $result['stat_total_enter'],
            'stat_connected' => $result['stat_connected'],
            'stat_abandon' => $result['stat_abandon'],
            'stat_ring_no_answer' => $result['stat_ring_no_answer'],
            'avg_holdtime' => $result['avg_holdtime'],
            'avg_talktime' => $result['avg_talktime'] ?? 0,
        ], ['title' => $page_title]);
    }

    protected static function exportDoc(array $r, string $mode, int $from, int $to, array $filters): array
    {
        if ($mode === 'raw') {
            $columns = [[t('queue_logs.col_date'), 'datetime'], [t('queue_logs.col_event'), 'text'], [t('queue_logs.col_queue'), 'text'],
                [t('queue_logs.col_agent'), 'text'], [t('export.col_name'), 'text'], ['data1', 'text'], ['data2', 'text'], ['data3', 'text'], ['data4', 'text'], ['Call ID', 'text']];
            $rows = array_map(fn($l) => [$l['timestamp'], $l['event'], $l['queue_name'], $l['agent_ext'], $l['agent_name'], $l['data1'], $l['data2'], $l['data3'], $l['data4'], $l['call_id']], $r['logs']);
        } else {
            $columns = [[t('queue_logs.col_date'), 'datetime'], [t('queue_logs.col_caller'), 'text'], [t('queue_logs.col_queue'), 'text'], [t('queue_logs.col_status'), 'text'],
                [t('queue_logs.col_agent'), 'text'], [t('export.col_name'), 'text'], [t('queue_logs.col_hold_time'), 'dur'], [t('queue_logs.col_talk_time'), 'dur'],
                [t('export.col_missed_rings'), 'int'], [t('export.col_recording'), 'text'], ['Call ID', 'text']];
            $rows = array_map(fn($l) => [$l['start_ts'], $l['caller_num'], $l['queue_name'], $l['status_label'], $l['agent_ext'], $l['agent_name'], $l['hold_sec'], $l['talk_sec'],
                $l['ring_no_answer_count'], !empty($l['has_recording']) ? t('common.yes') : '', $l['call_id']], $r['logs']);
        }
        return [
            'title' => t('queue_logs.title'),
            'period' => date('d.m.Y', $from) . (date('Ymd', $from) !== date('Ymd', $to) ? ' – ' . date('d.m.Y', $to) : ''),
            'filters' => $filters,
            'kpis' => [
                [t('queue_logs.stat_entered'), (string) ($r['stat_total_enter'] ?? 0), ''],
                [t('queue_logs.stat_connected'), (string) ($r['stat_connected'] ?? 0), ''],
                [t('queue_logs.stat_abandoned'), (string) ($r['stat_abandon'] ?? 0), ''],
                [t('queue_logs.stat_ring_no_answer'), (string) ($r['stat_ring_no_answer'] ?? 0), ''],
                [t('queue_logs.stat_avg_wait'), ReportExport::format($r['avg_holdtime'] ?? 0, 'dur'), ''],
                [t('export.avg_talk'), ReportExport::format($r['avg_talktime'] ?? 0, 'dur'), ''],
            ],
            'sections' => [[
                'title' => t('queue_logs.title'),
                'total' => (int) ($r['total'] ?? count($rows)),
                'columns' => $columns,
                'rows' => $rows,
            ]],
        ];
    }
}
