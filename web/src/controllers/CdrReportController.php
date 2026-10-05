<?php
require_once __DIR__ . '/../services/CdrReportService.php';
require_once __DIR__ . '/../services/ReportExport.php';

class CdrReportController extends BaseController
{
    public static function index(): void
    {
        requireLogin();
        if (!hasModulePermission('cdr_reports', 'view') && !hasModulePermission('cc_reports', 'view')) {
            static::requireModule('cdr_reports', 'view');
        }

        $user = getCurrentUser();
        $role = $_SESSION['user_role'] ?? 'user';
        $user_ext = $user['extension'] ?? '';

        // Permission checks
        $can_view_all = ($role === 'admin' || !empty($user['can_view_all_cdrs']));
        $can_listen_all = ($role === 'admin' || !empty($user['can_listen_recordings']));
        $can_delete_cdr = ($role === 'admin' || hasModulePermission('cdr_reports', 'delete') || hasModulePermission('cc_reports', 'delete'));

        // Handle Delete CDR Submission
        if (static::isPost() && isset($_POST['delete_cdr_id'])) {
            $deleted = CdrReportService::deleteCdr($_POST['delete_cdr_id'], $_POST['csrf_token'] ?? '', $can_delete_cdr);
            if ($deleted) {
                static::redirect('/cdr-reports');
            }
            // Unauthorized/CSRF error: like the original behaviour, keep rendering normally (no redirect).
        }

        $agents = CdrReportRepository::agentsForFilter();

        // Date Filter Setup
        $date_filter = trim($_GET['date_range'] ?? 'today');
        $start_date = trim($_GET['start_date'] ?? '');
        $end_date = trim($_GET['end_date'] ?? '');

        $start_ts = null;
        $end_ts = null;

        if ($date_filter === 'today') {
            $start_ts = date('Y-m-d 00:00:00');
            $end_ts = date('Y-m-d 23:59:59');
        } elseif ($date_filter === 'yesterday') {
            $start_ts = date('Y-m-d 00:00:00', strtotime('-1 day'));
            $end_ts = date('Y-m-d 23:59:59', strtotime('-1 day'));
        } elseif ($date_filter === 'week') {
            $start_ts = date('Y-m-d 00:00:00', strtotime('-7 days'));
            $end_ts = date('Y-m-d 23:59:59');
        } elseif ($date_filter === 'month') {
            $start_ts = date('Y-m-d 00:00:00', strtotime('-30 days'));
            $end_ts = date('Y-m-d 23:59:59');
        } elseif ($date_filter === 'custom' && !empty($start_date) && !empty($end_date)) {
            $start_ts = $start_date . ' 00:00:00';
            $end_ts = $end_date . ' 23:59:59';
        }

        $status_filter = trim($_GET['status'] ?? '');
        $agent_filter = trim($_GET['agent'] ?? '');
        $device_filter = trim($_GET['device'] ?? '');
        $search_query = trim($_GET['search'] ?? '');
        $direction_filter = trim($_GET['direction'] ?? '');
        $trunk_filter = trim($_GET['trunk'] ?? '');
        $trunks = CdrReportRepository::trunkTitles();
        if (!isset($trunks[$trunk_filter])) {
            $trunk_filter = '';
        }

        $sayfa = max(1, intval($_GET['page'] ?? 1));
        $sayfa_boyutu = View::sayfaBoyutu(CdrReportRepository::SAYFA_BOYUTU);

        $view_mode = trim($_GET['view_mode'] ?? 'grouped');
        if ($view_mode !== 'raw') {
            $view_mode = 'grouped';
        }

        $cdrs = CdrReportRepository::search($can_view_all, $user_ext, $start_ts, $end_ts, $status_filter, $agent_filter, $search_query, $sayfa, $sayfa_boyutu, $device_filter, $view_mode, $direction_filter, $trunk_filter);

        // The summary is computed in the database over ALL matching records.
        // It used to be looped row by row in PHP; with paging that would cover
        // only the displayed page and the summary would be wrong. It also
        // called file_exists() for every row.
        $ozet = CdrReportRepository::ozet($can_view_all, $user_ext, $start_ts, $end_ts, $status_filter, $agent_filter, $search_query, $device_filter, $view_mode, $direction_filter, $trunk_filter);

        $stat_total            = $ozet['toplam'];
        $stat_answered         = $ozet['cevaplanan'];
        $stat_no_answer        = $ozet['cevapsiz'];
        $stat_busy             = $ozet['mesgul'];
        $stat_failed           = $ozet['basarisiz'];
        $stat_total_billsec    = $ozet['toplam_sure'];
        $stat_recordings_count = $ozet['kayitli'];
        $stat_total_ring       = $ozet['toplam_calma'];
        $stat_avg_talk         = $ozet['ort_konusma'];
        $stat_avg_ring         = $ozet['ort_calma'];

        $format = $_GET['export'] ?? '';
        if (in_array($format, ['pdf', 'xlsx'], true)) {
            // Every filtered call, not just this page (capped per format).
            $limit = min($stat_total, $format === 'pdf' ? ReportExport::PDF_MAX_ROWS : ReportExport::XLSX_MAX_ROWS);
            $all = [];
            for ($p = 1; count($all) < $limit; $p++) {
                $batch = CdrReportRepository::search($can_view_all, $user_ext, $start_ts, $end_ts, $status_filter, $agent_filter, $search_query, $p, 1000, $device_filter, $view_mode, $direction_filter, $trunk_filter);
                if (!$batch) {
                    break;
                }
                array_push($all, ...$batch);
            }
            $filters = array_filter([
                t('cdr_reports.col_status') => $status_filter !== '' ? static::statusLabel($status_filter) : '',
                t('export.f_extension') => $agent_filter,
                t('cdr_reports.col_direction') => $direction_filter !== '' ? t('cdr_reports.dir_' . $direction_filter) : '',
                t('export.f_trunk') => $trunk_filter !== '' ? ($trunks[$trunk_filter] ?? $trunk_filter) : '',
                t('export.f_device') => $device_filter,
                t('export.f_search') => $search_query,
            ], fn($v) => $v !== '');
            ReportExport::send(static::exportDoc(array_slice($all, 0, $limit), $ozet, $start_ts, $end_ts, $filters, $stat_total), $format, 'call-report');
        }

        $toplam_sayfa  = max(1, (int)ceil($stat_total / $sayfa_boyutu));
        if ($sayfa > $toplam_sayfa) $sayfa = $toplam_sayfa;

        $answer_rate = $stat_total > 0 ? round(($stat_answered / $stat_total) * 100, 1) : 0;

        $page_title = t('cdr_reports.title');
        static::renderPage('cdr_reports/index', [
            'user_ext' => $user_ext,
            'can_view_all' => $can_view_all,
            'can_listen_all' => $can_listen_all,
            'can_delete_cdr' => $can_delete_cdr,
            'agents' => $agents,
            'date_filter' => $date_filter,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'status_filter' => $status_filter,
            'agent_filter' => $agent_filter,
            'device_filter' => $device_filter,
            'search_query' => $search_query,
            'direction_filter' => $direction_filter,
            'trunk_filter' => $trunk_filter,
            'trunks' => $trunks,
            'stat_directions' => [
                CdrCallAnalyzer::INBOUND => $ozet['gelen'] ?? 0,
                CdrCallAnalyzer::OUTBOUND => $ozet['giden'] ?? 0,
                CdrCallAnalyzer::INTERNAL => $ozet['dahili'] ?? 0,
                CdrCallAnalyzer::TRANSIT => $ozet['transit'] ?? 0,
            ],
            'view_mode' => $view_mode,
            'cdrs' => $cdrs,
            'stat_total_ring' => $stat_total_ring,
            'stat_avg_talk' => $stat_avg_talk,
            'stat_avg_ring' => $stat_avg_ring,
            'sayfa' => $sayfa,
            'toplam_sayfa' => $toplam_sayfa,
            'sayfa_boyutu' => $sayfa_boyutu,
            'stat_total' => $stat_total,
            'stat_answered' => $stat_answered,
            'stat_total_billsec' => $stat_total_billsec,
            'stat_recordings_count' => $stat_recordings_count,
            'answer_rate' => $answer_rate,
        ], ['title' => $page_title]);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'ANSWERED' => t('cdr_reports.status_answered_label'),
            'NO ANSWER', 'NOANSWER', 'CANCEL' => t('cdr_reports.status_no_answer_label'),
            'BUSY' => t('cdr_reports.status_busy_label'),
            'ABANDON' => t('cdr_reports.status_abandon_label'),
            'FAILED' => t('cdr_reports.status_failed_label'),
            default => $status,
        };
    }

    /** One row per call with the same columns as the page, plus what only fits in a spreadsheet. */
    protected static function exportDoc(array $calls, array $ozet, ?string $from, ?string $to, array $filters, int $total): array
    {
        $rows = [];
        foreach ($calls as $c) {
            $f = $c['flow'] ?? null;
            $path = $f ? implode(' → ', array_map(fn($n) => $n['kind'] === 'trunk'
                ? $n['label'] . ($n['number'] !== '' ? ': ' . $n['number'] : '')
                : $n['id'] . ($n['label'] !== '' ? ' ' . $n['label'] : ''), $f['path'])) : '';
            $note = trim(implode(' · ', array_filter([$c['note_disposition'] ?? '', $c['note_customer_name'] ?? '', $c['note_text'] ?? ''])));
            $rows[] = [
                strtotime((string) $c['start_time']),
                $f ? t('cdr_reports.dir_' . $f['direction']) : '',
                $f['caller_number'] ?? ($c['caller_num'] ?? ''),
                $f['caller_name'] ?? '',
                $f['in_trunk_title'] ?? '',
                $f['dialed_number'] ?? '',
                $c['route'] ?? ($c['queue_name'] ?? ''),
                $f['out_trunk_title'] ?? '',
                $f['out_number'] ?? '',
                $c['agent_extension'] ?? '',
                $c['agent_name'] ?? '',
                $c['device_type'] ?? '',
                (int) ($c['ring_sec'] ?? 0),
                (int) ($c['billsec'] ?? 0),
                (int) ($c['duration'] ?? 0),
                static::statusLabel((string) $c['status']),
                !empty($f['transferred']) ? t('cdr_reports.transferred') : '',
                $path,
                $note,
            ];
        }
        $rate = $ozet['toplam'] > 0 ? round($ozet['cevaplanan'] * 100 / $ozet['toplam'], 1) : 0;
        $kpis = [
            [t('cdr_reports.stat_total'), (string) $ozet['toplam'], ''],
            [t('cdr_reports.stat_answered'), (string) $ozet['cevaplanan'], ReportExport::format($rate, 'pct')],
            [t('cdr_reports.stat_talk_time'), ReportExport::format($ozet['toplam_sure'], 'dur'), ''],
            [t('cdr_reports.stat_recordings'), (string) $ozet['kayitli'], ''],
        ];
        foreach (['gelen' => 'inbound', 'giden' => 'outbound', 'dahili' => 'internal', 'transit' => 'transit'] as $k => $dir) {
            if (isset($ozet[$k])) {
                $kpis[] = [t('cdr_reports.dir_' . $dir), (string) $ozet[$k], ''];
            }
        }
        return [
            'title' => t('cdr_reports.title'),
            'period' => static::periodLabel($from, $to),
            'filters' => $filters,
            'kpis' => $kpis,
            'sections' => [[
                'title' => t('cdr_reports.title'),
                'total' => $total,
                'columns' => [
                    [t('cdr_reports.col_datetime'), 'datetime'], [t('cdr_reports.col_direction'), 'text'],
                    [t('cdr_reports.col_caller'), 'text'], [t('export.col_caller_name'), 'text'], [t('export.col_in_trunk'), 'text'],
                    [t('cdr_reports.col_callee'), 'text'], [t('cdr_reports.col_route'), 'text'],
                    [t('export.col_out_trunk'), 'text'], [t('export.col_sent_number'), 'text'],
                    [t('cdr_reports.col_answered_by'), 'text'], [t('export.col_answered_name'), 'text'], [t('cdr_reports.col_device'), 'text'],
                    [t('cdr_reports.col_ring'), 'dur'], [t('cdr_reports.col_talk'), 'dur'], [t('export.col_total'), 'dur'],
                    [t('cdr_reports.col_status'), 'text'], [t('cdr_reports.transferred'), 'text'], [t('export.col_path'), 'text'],
                    [t('cdr_reports.col_note'), 'text'],
                ],
                'pdf_columns' => [0, 1, 2, 4, 5, 7, 9, 13, 15],
                'rows' => $rows,
            ]],
        ];
    }

    public static function periodLabel(?string $from, ?string $to): string
    {
        if (!$from || !$to) {
            return t('export.all_time');
        }
        $a = date('d.m.Y', strtotime($from));
        $b = date('d.m.Y', strtotime($to));
        return $a === $b ? $a : "{$a} – {$b}";
    }
}
