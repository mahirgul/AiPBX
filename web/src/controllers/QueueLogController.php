<?php

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

        $total_records = (int)($result['total'] ?? count($result['logs']));
        $toplam_sayfa = max(1, (int)ceil($total_records / $sayfa_boyutu));
        if ($sayfa > $toplam_sayfa) {
            $sayfa = $toplam_sayfa;
        }

        $page_title = t('queue_logs.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('queue_logs/index', [
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
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
