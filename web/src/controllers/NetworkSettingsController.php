<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/NetworkSettingsService.php';

/**
 * Network → Network settings (roadmap 12, 13): the server's addresses, the
 * addresses desk phones are told, and moving them all after an IP change.
 * Admin only.
 */
class NetworkSettingsController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $preview = null;
        $previewError = '';
        if (static::isPost() && ($_POST['action'] ?? '') === 'readdress_preview') {
            if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
                $previewError = t('common.invalid_csrf');
            } else {
                try {
                    $preview = [
                        'old' => trim((string) ($_POST['old_ip'] ?? '')),
                        'new' => trim((string) ($_POST['new_ip'] ?? '')),
                    ];
                    $preview['changes'] = NetworkSettingsService::readdressPreview($preview['old'], $preview['new']);
                } catch (\Exception $e) {
                    $preview = null;
                    $previewError = $e->getMessage();
                }
            }
        }
        $notices = static::handlePost([
            'save_server' => fn() => NetworkSettingsService::saveServer($_POST),
            'save_phones' => fn() => NetworkSettingsService::savePhones($_POST),
            'readdress_apply' => fn() => NetworkSettingsService::readdressAction($_POST),
        ]);

        $files = NetworkSettingsService::fileStatus();
        static::renderPage('network_settings/index', [
            'csrf' => getCSRFToken(),
            'system' => NetworkSettingsService::system(),
            'settings' => NetworkSettingsService::settings(),
            'localIps' => NetworkSettingsService::localIps(),
            'stale' => NetworkSettingsService::staleAddresses(null, null, $files),
            'files' => $files,
            'preview' => $preview,
            'previewError' => $previewError,
        ] + $notices, ['title' => t('sidebar.item_network_settings')]);
    }
}
