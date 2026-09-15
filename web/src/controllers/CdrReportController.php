<?php
require_once __DIR__ . '/../services/CdrReportService.php';

class CdrReportController extends BaseController
{
    public static function index(): void
    {
        requireLogin();
        if (!hasModulePermission('cdr_reports', 'view') && !hasModulePermission('cc_reports', 'view')) {
            static::requireModule('cdr_reports', 'view');
        }

        $user = getCurrentUser();
        $role = $_SESSION['user_role'] ?? 'fax_user';
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
            // Yetkisiz/CSRF hatası: orijinal davranış gibi normal render'a devam edilir (redirect yok).
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

        $sayfa = max(1, intval($_GET['page'] ?? 1));
        $sayfa_boyutu = View::sayfaBoyutu(CdrReportRepository::SAYFA_BOYUTU);

        $cdrs = CdrReportRepository::search($can_view_all, $user_ext, $start_ts, $end_ts, $status_filter, $agent_filter, $search_query, $sayfa, $sayfa_boyutu, $device_filter);

        // Ozet TUM eslesen kayitlar uzerinden, veritabaninda hesaplaniyor.
        // Eskiden PHP'de satir satir donuluyordu; sayfalamayla birlikte bu
        // yalnizca goruntulenen sayfayi kapsar ve ozet yanlis olurdu. Ayrica
        // her satir icin file_exists() cagriliyordu.
        $ozet = CdrReportRepository::ozet($can_view_all, $user_ext, $start_ts, $end_ts, $status_filter, $agent_filter, $search_query, $device_filter);

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

        $toplam_sayfa  = max(1, (int)ceil($stat_total / $sayfa_boyutu));
        if ($sayfa > $toplam_sayfa) $sayfa = $toplam_sayfa;

        $answer_rate = $stat_total > 0 ? round(($stat_answered / $stat_total) * 100, 1) : 0;

        $page_title = t('cdr_reports.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('cdr_reports/index', [
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
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
