<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/HealthService.php';
require_once __DIR__ . '/../services/DiagService.php';

/** /health — Admin → Health checks: results, intervals, alarm recipients, daily summary. */
class HealthController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $csrf = static::csrfToken();
        // Problem recording zip: sent as a file, before any HTML.
        if (static::isPost() && isset($_POST['diag_download'])) {
            $res = PBXHelper::handleAction($csrf, function () {
                $path = DiagService::downloadPath((string) ($_POST['name'] ?? ''));
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . basename($path) . '"');
                header('Content-Length: ' . filesize($path));
                header('Cache-Control: no-store');
                readfile($path);
                exit;
            });
            $notices = static::notices($res);
        }
        $notices ??= static::handlePost([
            'diag_start' => fn() => DiagService::start($_POST),
            'diag_stop' => fn() => DiagService::stop($_POST),
            'diag_delete' => fn() => DiagService::delete($_POST),
            'save_health' => fn() => HealthService::save($_POST),
            'run_all' => fn() => HealthService::requestRunAll($csrf),
            'send_summary' => fn() => HealthService::sendTestSummary($csrf),
        ]);

        static::renderPage('health/index', [
            'csrf' => getCSRFToken(),
            'checks' => HealthService::checks(),
            'settings' => HealthService::settings(),
            'recipientIds' => HealthService::recipientIds(),
            'users' => HealthService::candidateUsers(),
            'runQueued' => (string) getSystemSetting('health_run_all', '0') === '1',
            'diag' => DiagService::status(),
        ], ['title' => t('health.title')] + $notices);
    }
}
