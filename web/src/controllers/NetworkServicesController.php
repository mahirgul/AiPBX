<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/NetworkServicesService.php';

/** PBX → Network services: DHCP and TFTP for desk phones (roadmap item 10). */
class NetworkServicesController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $csrf = static::csrfToken();
        $notices = static::handlePost([
            'save_netsvc' => fn() => NetworkServicesService::save($_POST),
            'probe_dhcp' => fn() => NetworkServicesService::probeAction((string) ($_POST['netsvc_interface'] ?? ''), $csrf),
            'upload_tftp' => fn() => NetworkServicesService::uploadTftp($_FILES['tftp_file'] ?? null, $csrf),
            'delete_tftp' => fn() => NetworkServicesService::deleteTftp((string) ($_POST['name'] ?? ''), $csrf),
        ]);

        $settings = NetworkServicesService::settings();
        $interfaces = NetworkServicesService::interfaces();
        $leases = NetworkServicesService::leases();
        NetworkServicesService::syncLeasesToWaiting($leases);

        // Option 150 (Cisco TFTP) names this server: the chosen interface, else the first one.
        $first = array_values($interfaces)[0] ?? ['ip' => ''];
        $serverIp = $interfaces[$settings['netsvc_interface']]['ip'] ?? $first['ip'];

        static::renderPage('network_services/index', [
            'csrf' => getCSRFToken(),
            'settings' => $settings,
            'status' => $settings['netsvc_mode'] === 'off' ? 'inactive' : NetworkServicesService::status(),
            'interfaces' => $interfaces,
            'leases' => $leases,
            'tftpFiles' => NetworkServicesService::tftpFiles(),
            'tftpNetworks' => NetworkServicesService::tftpNetworks(),
            'hints' => NetworkServicesService::dhcpOptionHints(NetworkServicesService::provisioningUrl(), $serverIp),
            'provisioningUrl' => NetworkServicesService::provisioningUrl(),
        ], ['title' => t('netsvc.title')] + $notices);
    }
}
