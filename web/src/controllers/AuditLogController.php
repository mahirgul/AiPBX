<?php
/**
 * Kalıcı denetim kaydı (audit log) görüntüleme sayfası — 2026-08-24.
 */
require_once __DIR__ . '/../asterisk_sync.php';

class AuditLogController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $user_map = AuditLogRepository::userMap();

        $domain_filter = trim($_GET['domain'] ?? '');
        $action_filter = trim($_GET['action'] ?? '');
        $user_filter = trim($_GET['user'] ?? '');
        $search_query = trim($_GET['search'] ?? '');
        $date_filter = trim($_GET['date_range'] ?? 'today');

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
        } elseif ($date_filter === 'all') {
            $start_ts = 0;
        }

        $logs = AuditLogRepository::search($start_ts, $end_ts, $domain_filter, $action_filter, $user_filter, $search_query);

        $login_status_filter = trim($_GET['login_status'] ?? '');
        $login_search_query = trim($_GET['login_search'] ?? '');
        $login_attempts = AuditLogRepository::searchLoginAttempts($start_ts, $end_ts, $login_status_filter, $login_search_query);

        $page_title = t('audit_log.title');
        require_once dirname(__DIR__) . '/../header.php';
        static::render('audit_log/index', [
            'logs' => $logs,
            'user_map' => $user_map,
            'domain_filter' => $domain_filter,
            'action_filter' => $action_filter,
            'user_filter' => $user_filter,
            'search_query' => $search_query,
            'date_filter' => $date_filter,
            'domain_map' => PENDING_SYNC_DOMAIN_MAP,
            'login_attempts' => $login_attempts,
            'login_status_filter' => $login_status_filter,
            'login_search_query' => $login_search_query,
        ]);
        require_once dirname(__DIR__) . '/../footer.php';
    }
}
