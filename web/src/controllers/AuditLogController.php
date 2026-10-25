<?php
/**
 * Permanent audit record (audit log) viewing page — 2026-08-24.
 */
require_once __DIR__ . '/../asterisk_sync.php';

/**
 * Audit page (/audit-log).
 * Renders templates/views/audit_log/index.php.
 * Logic: AuditLogRepository.
 * Admins only.
 */
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

        $audit_domains = array_merge(PENDING_SYNC_DOMAIN_MAP, [
            'privacy'            => 'privacy',
            'security'           => 'security',
            'plugins'            => 'plugins',
            'phones'             => 'phones',
            'ai_cloud'           => 'ai_cloud',
            'webhooks'           => 'webhooks',
            'api_keys'           => 'api_keys',
            'scheduled_tasks'    => 'scheduled_tasks',
            'external_dbs'       => 'external_dbs',
            'asterisk_settings'  => 'asterisk_settings',
            'file_storage'       => 'file_storage',
            'network_services'   => 'network_services',
            'network_settings'   => 'network_settings',
            'ms_teams'           => 'ms_teams',
            'google_integration' => 'google_integration',
        ]);

        $page_title = t('audit_log.title');
        static::renderPage('audit_log/index', [
            'logs' => $logs,
            'user_map' => $user_map,
            'domain_filter' => $domain_filter,
            'action_filter' => $action_filter,
            'user_filter' => $user_filter,
            'search_query' => $search_query,
            'date_filter' => $date_filter,
            'domain_map' => $audit_domains,
            'login_attempts' => $login_attempts,
            'login_status_filter' => $login_status_filter,
            'login_search_query' => $login_search_query,
        ], ['title' => $page_title]);
    }
}
