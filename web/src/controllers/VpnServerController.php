<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/VpnService.php';

/** Network → VPN Server: phones, softphones and admins reach this PBX over WireGuard or OpenVPN. */
class VpnServerController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $qr = null;
        $notices = ['message' => '', 'error' => ''];
        if (static::isPost() && (isset($_POST['peer_download']) || isset($_POST['peer_qr']))) {
            $res = PBXHelper::handleAction(static::csrfToken(), function () use (&$qr) {
                $profile = VpnService::peerProfile((int) ($_POST['peer_id'] ?? 0));
                if (isset($_POST['peer_qr'])) {
                    $qr = ['name' => $profile['name'], 'image' => VpnService::profileQr($profile['content'])];
                    return '';
                }
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $profile['name'] . '"');
                header('Cache-Control: no-store');
                echo $profile['content'];
                exit;
            });
            $notices = static::notices($res);
        } else {
            $notices = static::handlePost([
                'save_server' => fn() => VpnService::saveServer($_POST),
                'peer_add' => fn() => VpnService::addPeer($_POST),
                'peer_delete' => fn() => VpnService::deletePeer($_POST),
            ]);
        }

        static::renderPage('vpn/server', [
            'csrf' => getCSRFToken(),
            'status' => VpnService::status(),
            'qr' => $qr,
            'suggestedEndpoint' => VpnService::suggestedEndpoint(),
            'log' => isset($_GET['log']) ? VpnService::log('server') : null,
        ], ['title' => t('vpn.server_title')] + $notices);
    }
}
