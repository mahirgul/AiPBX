<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/DiagService.php';

/**
 * Troubleshooting → Problem recording (/problem-recording): start, repeat the
 * problem, stop, download one masked zip (DiagService, conf/sbin/aipbx-diag).
 */
class ProblemRecordingController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $csrf = static::csrfToken();
        $notices = null;
        // The zip goes out as a file, before any HTML.
        if (static::isPost() && isset($_POST['diag_download'])) {
            $notices = static::notices(PBXHelper::handleAction($csrf, function () {
                $path = DiagService::downloadPath((string) ($_POST['name'] ?? ''));
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . basename($path) . '"');
                header('Content-Length: ' . filesize($path));
                header('Cache-Control: no-store');
                readfile($path);
                exit;
            }));
        }
        $notices ??= static::handlePost([
            'diag_start' => fn() => DiagService::start($_POST),
            'diag_stop' => fn() => DiagService::stop($_POST),
            'diag_delete' => fn() => DiagService::delete($_POST),
        ]);

        static::renderPage('problem_recording/index', [
            'csrf' => getCSRFToken(),
            'diag' => DiagService::status(),
        ], ['title' => t('diag.title')] + $notices);
    }
}
