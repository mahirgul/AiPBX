<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/ReverseProxyService.php';
require_once __DIR__ . '/../services/NetworkSettingsService.php';

/**
 * Controller for /reverse-proxy — Reverse Proxy configuration, client IP detection,
 * ports matrix, and firewall rules generator.
 */
class ReverseProxyController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $message = '';
        $error = '';

        if (static::isPost()) {
            if (!static::verifyCsrf()) {
                $error = t('common.invalid_csrf');
            } else {
                $action = (string) ($_POST['action'] ?? '');
                if ($action === 'save_settings') {
                    $userId = $_SESSION['user_id'] ?? null;
                    $res = ReverseProxyService::saveSettings([
                        'enabled' => !empty($_POST['proxy_enabled']),
                        'trusted_proxies' => (string) ($_POST['proxy_trusted_proxies'] ?? ''),
                        'tls_termination' => (string) ($_POST['proxy_tls_termination'] ?? 'edge'),
                        'external_fqdn' => (string) ($_POST['proxy_external_fqdn'] ?? ''),
                        'external_port' => (int) ($_POST['proxy_external_port'] ?? 443),
                        'proxy_type' => (string) ($_POST['proxy_type'] ?? 'nginx'),
                    ], $userId);

                    if ($res['success']) {
                        $message = t('reverse_proxy.msg_saved_ok');
                    } else {
                        $error = $res['error'] ?? t('reverse_proxy.err_save_failed');
                    }
                }
            }
        }

        $settings = ReverseProxyService::getSettings();
        $ports = ReverseProxyService::portsMatrix();
        $diagnostics = ReverseProxyService::inspectCurrentRequest($_SERVER);

        // System primary LAN IP for backend config generation
        $sysInfo = NetworkSettingsService::system();
        $primaryIp = $sysInfo['primary'] ?: '127.0.0.1';

        static::renderPage('reverse_proxy/index', [
            'settings' => $settings,
            'ports' => $ports,
            'diagnostics' => $diagnostics,
            'primary_ip' => $primaryIp,
            'csrf_token' => getCSRFToken(),
        ], [
            'title' => t('reverse_proxy.title'),
            'message' => $message,
            'error' => $error,
        ]);
    }
}
