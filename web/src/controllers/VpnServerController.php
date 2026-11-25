<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/VpnService.php';

/** Network → VPN (Server & Client unified tabbed page). */
class VpnServerController extends BaseController
{
    public static function index(): void
    {
        static::renderVpn('server');
    }

    public static function renderVpn(string $defaultTab = 'server'): void
    {
        static::requireRole('admin');

        $activeTab = (string) ($_POST['_tab'] ?? $_GET['tab'] ?? $defaultTab);
        if (!in_array($activeTab, ['server', 'client'], true)) {
            $activeTab = $defaultTab;
        }
        if (isset($_POST['tunnel_add']) || isset($_POST['tunnel_save']) || isset($_POST['tunnel_delete'])) {
            $activeTab = 'client';
        } elseif (isset($_POST['save_server']) || isset($_POST['peer_add']) || isset($_POST['peer_delete'])) {
            $activeTab = 'server';
        }

        $qr = null;
        $notices = ['message' => '', 'error' => ''];
        if (static::isPost() && (isset($_POST['peer_download']) || isset($_POST['peer_qr']) || isset($_POST['peer_yealink']))) {
            $activeTab = 'server';
            $res = PBXHelper::handleAction(static::csrfToken(), function () use (&$qr) {
                $id = (int) ($_POST['peer_id'] ?? 0);
                $profile = isset($_POST['peer_yealink']) ? VpnService::peerYealinkTar($id) : VpnService::peerProfile($id);
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
                'tunnel_add' => fn() => VpnService::addTunnel($_POST, $_FILES['tunnel_file'] ?? null),
                'tunnel_save' => fn() => VpnService::setTunnel($_POST),
                'tunnel_delete' => fn() => VpnService::deleteTunnel($_POST),
            ]);
        }

        $logId = (int) ($_GET['log'] ?? 0);
        $serverLog = ($activeTab === 'server' && isset($_GET['log'])) ? VpnService::log('server') : null;
        $clientLog = ($activeTab === 'client' && $logId > 0) ? VpnService::log('t' . $logId) : null;

        static::renderPage('vpn/index', [
            'csrf' => getCSRFToken(),
            'active_tab' => $activeTab,
            'status' => VpnService::status(),
            'qr' => $qr,
            'suggestedEndpoint' => VpnService::suggestedEndpoint(),
            'serverLog' => $serverLog,
            'clientLog' => $clientLog,
            'logId' => $logId,
        ], ['title' => t('vpn.server_title') . ' / ' . t('vpn.client_title')] + $notices);
    }
}
