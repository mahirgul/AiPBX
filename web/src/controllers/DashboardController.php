<?php
/**
 * Dashboard controller — the MVC counterpart of the old src/dashboard.php.
 * The page logic (access check + POST handling) is here, data collection in
 * DashboardRepository, the HTML output in templates/views/dashboard/index.php.
 */
require_once __DIR__ . '/../asterisk_sync.php';
require_once __DIR__ . '/../priv_helper.php';

class DashboardController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost() && isset($_POST['system_action'])) {
            if (!static::verifyCsrf()) {
                $error = t('dashboard.msg_csrf_error');
            } else {
                [$message, $error] = static::handleSystemAction();
            }
            if ($message !== '') static::notifySuccess($message);
            if ($error !== '') static::notifyError($error);
        }

        $stats = DashboardRepository::getStats();
        $metrics = DashboardRepository::getSystemMetrics();
        $metrics['live'] = DashboardRepository::getLiveStats();

        $page_title = t('dashboard.title');
        static::renderPage('dashboard/index', array_merge($stats, $metrics), ['title' => $page_title, 'message' => $message ?? '', 'error' => $error ?? '']);
    }

    /**
     * @return array{0: string, 1: string} [message, error]
     */
    private static function handleSystemAction(): array
    {
        $action = trim($_POST['system_action'] ?? '');
        $service = trim($_POST['service_name'] ?? '');

        $uid = $_SESSION['user_id'] ?? null;

        if ($action === 'reload_asterisk') {
            // 2026-08-24: `asterisk -rx` always exits with code 0 (verified
            // live) — execCLI() checks the output text with
            // looksLikeCliFailure(). No sudo needed: the web user is in the
            // asterisk group and reaches the control socket directly.
            $res = AsteriskHelper::execCLI('core reload');
            $output = trim($res['output']);
            $failed = !$res['success'];
            // 2026-08-25: on failure the raw Asterisk output is written to the
            // audit record too (it used to write only "core reload" and left
            // the actual error text out of the permanent record).
            $label = t('dashboard.service_asterisk_name') . ' (core reload)' . ($failed ? ': ' . mb_substr($output, 0, 200) : '');
            writeAuditLog(null, 'system_service', 'asterisk', $label, $failed ? 'reload_failed' : 'reload', $uid);
            if ($failed) {
                return ['', t('dashboard.msg_reload_failed') . ' ' . $output];
            }
            return [t('dashboard.msg_reload_success'), ''];
        }

        if ($action === 'restart_service') {
            $allowed_services = [
                'asterisk' => t('dashboard.service_asterisk_name'),
                'apache2' => t('dashboard.service_httpd_name'),
                'mariadb' => t('dashboard.service_mariadb_name'),
                'postfix' => t('dashboard.service_postfix_name'),
            ];
            if (isset($allowed_services[$service])) {
                // Unlike asterisk -rx, systemctl returns a REAL exit code
                // (verified live: a failed restart gives a non-zero code) —
                // $ret is reliable here.
                $res = PrivHelper::run(['service', 'restart', $service]);
                $output = $res['output'];
                $failed = !$res['success'];
                $label = $allowed_services[$service] . ' (systemctl restart)' . ($failed ? ': ' . mb_substr($output, 0, 200) : '');
                writeAuditLog(null, 'system_service', $service, $label, $failed ? 'restart_failed' : 'restart', $uid);
                if ($failed) {
                    return ['', $allowed_services[$service] . ' ' . t('dashboard.msg_restart_failed') . ' ' . $output];
                }
                return [$allowed_services[$service] . ' ' . t('dashboard.msg_service_restarted'), ''];
            }
            return ['', t('dashboard.msg_invalid_service')];
        }

        return ['', ''];
    }
}
