<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/HealthService.php';

/** /health — Admin → Health checks: results, intervals, alarm recipients, daily summary. */
class HealthController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $csrf = static::csrfToken();
        $notices = static::handlePost([
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
        ], ['title' => t('health.title')] + $notices);
    }
}
