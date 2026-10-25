<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/PrivacyService.php';

/**
 * Security → GDPR & Data Privacy (EN) / KVKK ve Veri Gizliliği (TR).
 */
class PrivacyController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        // JSON Subject Access Request (SAR) export download
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['export_subject'])) {
            if (!verifyCSRFToken(static::csrfToken())) {
                http_response_code(403);
                exit('Invalid CSRF token.');
            }
            $rawSubject = trim((string)($_POST['subject_number'] ?? ''));
            $info = PrivacyService::lookupSubject($rawSubject);
            if ($info !== null) {
                if (function_exists('writeAuditLog')) {
                    writeAuditLog('privacy', 'sar_export', $info['query'], 'Exported SAR data package for ' . $info['query'], 'create', $_SESSION['user_id'] ?? null);
                }
                $settings = PrivacyService::settings();
                $profiles = PrivacyService::profiles();
                $activeProfile = $profiles[$settings['jurisdiction_profile']]['code'] ?? 'GDPR / KVKK';
                $payload = [
                    'generated_at'    => date('c'),
                    'framework'       => $activeProfile,
                    'subject_number'  => $info['query'],
                    'cdr_count'       => $info['cdr_count'],
                    'recording_files' => array_map('basename', $info['recording_files']),
                    'voicemails'      => $info['voicemails'],
                    'faxes'           => $info['faxes'],
                    'signaling_events'=> $info['signaling_count'],
                    'cdrs'            => $info['all_cdrs'],
                ];
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="sar-export-' . preg_replace('/[^0-9]/', '', $info['digits']) . '-' . date('Ymd-His') . '.json"');
                echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        $csrf = static::csrfToken();
        $notices = static::handlePost([
            'apply_profile'   => fn() => !verifyCSRFToken($csrf)
                ? ['success' => false, 'error' => t('common.invalid_csrf')]
                : PrivacyService::applyProfile($_POST),
            'save_notice'     => fn() => !verifyCSRFToken($csrf)
                ? ['success' => false, 'error' => t('common.invalid_csrf')]
                : PrivacyService::saveNotice($_POST),
            'save_retention'  => fn() => !verifyCSRFToken($csrf)
                ? ['success' => false, 'error' => t('common.invalid_csrf')]
                : PrivacyService::saveRetention($_POST),
            'run_purge'       => function () use ($csrf): array {
                if (!verifyCSRFToken($csrf)) {
                    return ['success' => false, 'error' => t('common.invalid_csrf')];
                }
                $res = PrivacyService::runRetentionPurge();
                return ['success' => true, 'message' => sprintf(t('privacy.msg_purge_done'), $res['summary'])];
            },
            'erase_subject'   => fn() => !verifyCSRFToken($csrf)
                ? ['success' => false, 'error' => t('common.invalid_csrf')]
                : PrivacyService::eraseSubject((string)($_POST['subject_number'] ?? '')),
            'save_compliance' => fn() => !verifyCSRFToken($csrf)
                ? ['success' => false, 'error' => t('common.invalid_csrf')]
                : PrivacyService::saveCompliance($_POST),
        ]);

        $subjectQuery = trim((string)($_GET['subject'] ?? $_POST['subject_number'] ?? ''));
        $subjectResult = $subjectQuery !== '' ? PrivacyService::lookupSubject($subjectQuery) : null;

        $settings = PrivacyService::settings();
        $overview = PrivacyService::complianceOverview();

        static::renderPage('privacy/index', [
            'csrf'          => getCSRFToken(),
            'settings'      => $settings,
            'profiles'      => PrivacyService::profiles(),
            'overview'      => $overview,
            'subjectQuery'  => $subjectQuery,
            'subjectResult' => $subjectResult,
        ], ['title' => t('privacy.title')] + $notices);
    }
}
