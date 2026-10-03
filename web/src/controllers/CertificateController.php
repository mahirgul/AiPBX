<?php
require_once __DIR__ . '/../services/CertificateService.php';

/**
 * /certificates — TLS certificate of the portal, TURNS and SIP-TLS (admin only).
 */
class CertificateController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';
        $warning = '';
        $output = '';

        if (static::isPost()) {
            if (!static::verifyCsrf()) {
                $res = ['success' => false, 'error' => t('common.invalid_csrf')];
            } else {
                $res = match ($_POST['action'] ?? '') {
                    'le_test' => CertificateService::letsEncrypt(true, $_POST['email'] ?? ''),
                    'le_issue' => CertificateService::letsEncrypt(false, $_POST['email'] ?? ''),
                    'renew_test' => CertificateService::renew(true),
                    'renew' => CertificateService::renew(false),
                    'selfsigned' => CertificateService::selfSigned(),
                    'upload' => CertificateService::installCustom($_FILES, (string) ($_POST['password'] ?? ''), !empty($_POST['allow_mismatch'])),
                    default => ['success' => false, 'error' => t('certificates.action_failed')],
                };
                $done = [
                    'le_test' => 'certificates.msg_le_test_ok',
                    'le_issue' => 'certificates.msg_le_ok',
                    'renew_test' => 'certificates.msg_renew_test_ok',
                    'renew' => 'certificates.msg_renew_ok',
                    'selfsigned' => 'certificates.msg_selfsigned_ok',
                    'upload' => 'certificates.msg_upload_ok',
                ];
                if ($res['success']) {
                    $message = t($done[$_POST['action']]);
                    if (!empty($res['warnings'])) {
                        $warning = implode(' ', array_map(fn($w) => sprintf(t('certificates.issue_' . $w), CertificateService::domain()), $res['warnings']));
                    }
                }
            }
            if (!$res['success']) {
                $error = $res['error'] ?? t('certificates.action_failed');
            }
            $output = $res['output'] ?? '';
        }

        static::renderPage('certificates/index', [
            'st' => CertificateService::status(),
            'output' => $output,
            'csrf_token' => getCSRFToken(),
        ], ['title' => t('certificates.title'), 'message' => $message, 'error' => $error, 'warning' => $warning]);
    }
}
