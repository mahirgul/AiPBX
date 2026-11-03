<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/VpnService.php';

/** Network → VPN Client: this PBX connects out to another network, over WireGuard or OpenVPN. */
class VpnClientController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $notices = static::handlePost([
            'tunnel_add' => fn() => VpnService::addTunnel($_POST, $_FILES['tunnel_file'] ?? null),
            'tunnel_save' => fn() => VpnService::setTunnel($_POST),
            'tunnel_delete' => fn() => VpnService::deleteTunnel($_POST),
        ]);

        $logId = (int) ($_GET['log'] ?? 0);
        static::renderPage('vpn/client', [
            'csrf' => getCSRFToken(),
            'status' => VpnService::status(),
            'logId' => $logId,
            'log' => $logId > 0 ? VpnService::log('t' . $logId) : null,
        ], ['title' => t('vpn.client_title')] + $notices);
    }
}
