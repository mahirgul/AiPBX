<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/NetworkSettingsService.php';
require_once __DIR__ . '/../services/NatDiagnosticsService.php';
require_once __DIR__ . '/../services/NetworkConfigService.php';

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

        $natResults = null;
        if (static::isPost() && ($_POST['action'] ?? '') === 'nat_check' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $natResults = NatDiagnosticsService::evaluate(NatDiagnosticsService::gather());
        }
        $notices = static::handlePost([
            'netcfg_apply' => fn() => NetworkConfigService::apply($_POST),
            'restart_for_networks' => fn() => NetworkSettingsService::restartForNetworks($_POST),
            'netcfg_confirm' => fn() => NetworkConfigService::confirm($_POST),
            'netcfg_revert' => fn() => NetworkConfigService::revert($_POST),
        ]);

        $files = NetworkSettingsService::fileStatus();
        $stale = NetworkSettingsService::staleAddresses(null, null, $files);
        // A changed address waits on Apply, with what it changes (pending page).
        NetworkSettingsService::queueReaddress($stale);
        static::renderPage('network_settings/index', [
            'csrf' => getCSRFToken(),
            'system' => NetworkSettingsService::system(),
            'settings' => NetworkSettingsService::settings(),
            'localIps' => NetworkSettingsService::localIps(),
            'stale' => $stale,
            'files' => $files,
            'natResults' => $natResults,
        ] + $notices, ['title' => t('sidebar.item_network_settings')]);
    }
}
