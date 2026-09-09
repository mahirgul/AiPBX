<?php

class PauseReportController extends BaseController
{
    public static function index(): void
    {
        static::requireRole(['admin', 'cc_agent']);

        $user = getCurrentUser();
        $role = $_SESSION['user_role'] ?? 'fax_user';
        $user_ext = $user['extension'] ?? '';
        $can_view_all = ($role === 'admin' || !empty($user['can_view_all_cdrs']));

        $start_date = trim($_GET['start_date'] ?? date('Y-m-d'));
        $end_date = trim($_GET['end_date'] ?? date('Y-m-d'));
        $agent_filter = trim($_GET['agent_filter'] ?? '');
        $reason_filter = trim($_GET['reason_filter'] ?? '');

        $logs = PauseReportRepository::search($start_date, $end_date, $can_view_all, $user_ext, $agent_filter, $reason_filter);

        // Statistics calculation
        $total_breaks = count($logs);
        $total_sec = 0;
        $reasons_count = [];
        $active_breaks = 0;

        foreach ($logs as $l) {
            $total_sec += intval($l['duration_sec']);
            $r = $l['pause_reason'] ?: t('pause_reports.other');
            $reasons_count[$r] = ($reasons_count[$r] ?? 0) + 1;
            if ($l['status'] === 'PAUSED') {
                $active_breaks++;
            }
        }

        $avg_sec = $total_breaks > 0 ? round($total_sec / $total_breaks) : 0;
        arsort($reasons_count);
        $top_reason = !empty($reasons_count) ? array_key_first($reasons_count) : '-';

        $agents = $can_view_all ? PauseReportRepository::distinctAgents() : [];
        $reasons_list = PauseReportRepository::distinctReasons();

        $page_title = t('pause_reports.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('pause_reports/index', [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'agent_filter' => $agent_filter,
            'reason_filter' => $reason_filter,
            'can_view_all' => $can_view_all,
            'total_breaks' => $total_breaks,
            'total_sec' => $total_sec,
            'active_breaks' => $active_breaks,
            'avg_sec' => $avg_sec,
            'top_reason' => $top_reason,
            'agents' => $agents,
            'reasons_list' => $reasons_list,
            'logs' => $logs,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
