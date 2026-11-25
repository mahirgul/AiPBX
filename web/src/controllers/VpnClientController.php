<?php
require_once __DIR__ . '/VpnServerController.php';

/** Network → VPN Client: renders the unified VPN page on the Client tab. */
class VpnClientController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');
        VpnServerController::renderVpn('client');
    }
}
