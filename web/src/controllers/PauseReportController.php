<?php
require_once __DIR__ . '/../services/ReportExport.php';

class PauseReportController extends BaseController
{
    public static function index(): void
    {
        static::requireRole(['admin', 'cc_manager', 'cc_agent']);

        $user = getCurrentUser();
        $role = $_SESSION['user_role'] ?? 'user';
        $user_ext = $user['extension'] ?? '';
        $can_view_all = ($role === 'admin' || !empty($user['can_view_all_cdrs']));

        $start_date = trim($_GET['start_date'] ?? date('Y-m-d'));
        $end_date = trim($_GET['end_date'] ?? date('Y-m-d'));
        $agent_filter = trim($_GET['agent_filter'] ?? '');
        $reason_filter = trim($_GET['reason_filter'] ?? '');

        $format = $_GET['export'] ?? '';
        $exporting = in_array($format, ['pdf', 'xlsx'], true);
        // The page lists the latest 500; an export takes all of the period.
        $logs = PauseReportRepository::search($start_date, $end_date, $can_view_all, $user_ext, $agent_filter, $reason_filter, $exporting ? ReportExport::XLSX_MAX_ROWS : 500);

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

        if ($exporting) {
            ReportExport::send(static::exportDoc($logs, $start_date, $end_date, array_filter([
                t('pause_reports.field_agent_filter') => $agent_filter,
                t('pause_reports.field_reason_filter') => $reason_filter,
            ]), compact('total_breaks', 'total_sec', 'avg_sec', 'active_breaks', 'top_reason')), $format, 'pause-report');
        }

        $agents = $can_view_all ? PauseReportRepository::distinctAgents() : [];
        $reasons_list = PauseReportRepository::distinctReasons();

        $page_title = t('pause_reports.title');
        static::renderPage('pause_reports/index', [
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
        ], ['title' => $page_title]);
    }

    protected static function exportDoc(array $logs, string $startDate, string $endDate, array $filters, array $st): array
    {
        $other = t('pause_reports.other');
        $byReason = [];
        $byAgent = [];
        foreach ($logs as $l) {
            $r = $l['pause_reason'] ?: $other;
            $byReason[$r] = ['count' => ($byReason[$r]['count'] ?? 0) + 1, 'sec' => ($byReason[$r]['sec'] ?? 0) + (int) $l['duration_sec']];
            $a = (string) $l['agent_extension'];
            $byAgent[$a] = ['name' => $l['agent_name'], 'count' => ($byAgent[$a]['count'] ?? 0) + 1, 'sec' => ($byAgent[$a]['sec'] ?? 0) + (int) $l['duration_sec']];
        }
        uasort($byReason, fn($x, $y) => $y['sec'] <=> $x['sec']);
        uasort($byAgent, fn($x, $y) => $y['sec'] <=> $x['sec']);
        $a = date('d.m.Y', strtotime($startDate) ?: time());
        $b = date('d.m.Y', strtotime($endDate) ?: time());
        return [
            'title' => t('pause_reports.title'),
            'period' => $a === $b ? $a : "{$a} – {$b}",
            'filters' => $filters,
            'kpis' => [
                [t('pause_reports.stat_total_breaks'), (string) $st['total_breaks'], ''],
                [t('pause_reports.stat_total_duration'), ReportExport::format($st['total_sec'], 'dur'), ''],
                [t('pause_reports.stat_avg_duration'), ReportExport::format($st['avg_sec'], 'dur'), ''],
                [t('pause_reports.stat_active_now'), (string) $st['active_breaks'], ''],
                [t('pause_reports.stat_top_reason'), (string) $st['top_reason'], ''],
            ],
            'sections' => [
                [
                    'title' => t('export.by_agent'),
                    'columns' => [[t('pause_reports.col_extension'), 'text'], [t('pause_reports.col_agent'), 'text'], [t('export.col_count'), 'int'], [t('pause_reports.col_total_duration'), 'dur']],
                    'rows' => array_map(fn($ext, $v) => [(string) $ext, $v['name'], $v['count'], $v['sec']], array_keys($byAgent), array_values($byAgent)),
                ],
                [
                    'title' => t('export.by_reason'),
                    'columns' => [[t('pause_reports.col_reason'), 'text'], [t('export.col_count'), 'int'], [t('pause_reports.col_total_duration'), 'dur']],
                    'rows' => array_map(fn($r, $v) => [$r, $v['count'], $v['sec']], array_keys($byReason), array_values($byReason)),
                ],
                [
                    'title' => t('pause_reports.title'),
                    'columns' => [[t('pause_reports.col_extension'), 'text'], [t('pause_reports.col_agent'), 'text'], [t('pause_reports.col_reason'), 'text'],
                        [t('pause_reports.col_start'), 'datetime'], [t('pause_reports.col_end'), 'datetime'], [t('pause_reports.col_total_duration'), 'dur'], [t('pause_reports.col_status'), 'text']],
                    'rows' => array_map(fn($l) => [(string) $l['agent_extension'], $l['agent_name'], $l['pause_reason'] ?: $other, strtotime((string) $l['start_time']),
                        $l['end_time'] ? strtotime((string) $l['end_time']) : '', (int) $l['duration_sec'],
                        $l['status'] === 'PAUSED' ? t('pause_reports.status_active') : t('pause_reports.status_completed')], $logs),
                ],
            ],
        ];
    }
}
