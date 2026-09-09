<?php

class QueueLogController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $agent_map = QueueLogRepository::agentMap();

        $event_filter = trim($_GET['event'] ?? '');
        $agent_filter = trim($_GET['agent'] ?? '');
        $search_query = trim($_GET['search'] ?? '');
        $date_filter = trim($_GET['date_range'] ?? 'today');

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

        // Ensure latest logs are synced to DB
        QueueLogRepository::syncLatest();

        $result = QueueLogRepository::searchAndParse($start_ts, $end_ts, $event_filter, $agent_filter, $search_query, $agent_map);

        $page_title = t('queue_logs.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('queue_logs/index', [
            'agent_map' => $agent_map,
            'event_filter' => $event_filter,
            'agent_filter' => $agent_filter,
            'search_query' => $search_query,
            'date_filter' => $date_filter,
            'parsed_logs' => $result['logs'],
            'stat_total_enter' => $result['stat_total_enter'],
            'stat_connected' => $result['stat_connected'],
            'stat_abandon' => $result['stat_abandon'],
            'stat_ring_no_answer' => $result['stat_ring_no_answer'],
            'avg_holdtime' => $result['avg_holdtime'],
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
