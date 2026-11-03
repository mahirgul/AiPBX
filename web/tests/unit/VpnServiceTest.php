<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/services/VpnService.php';

/** The PBX side of the VPN pages; profiles and keys are tested in scripts/test_aipbx_vpn.py. */
class VpnServiceTest extends TestCase
{
    public function testLocalNetsAddedAndTakenBack(): void
    {
        $merged = VpnService::mergeLocalNets(['192.168.1.0/24'], [], ['10.99.0.0/24']);
        $this->assertSame(['192.168.1.0/24', '10.99.0.0/24'], $merged['nets']);
        $this->assertSame(['10.99.0.0/24'], $merged['ours']);

        $merged = VpnService::mergeLocalNets($merged['nets'], $merged['ours'], []);
        $this->assertSame(['192.168.1.0/24'], $merged['nets'], 'only what the page added goes away');
    }

    public function testNetworkTheAdminCoversIsNeitherAddedNorRemoved(): void
    {
        $merged = VpnService::mergeLocalNets(['10.0.0.0/8'], [], ['10.99.0.0/24']);
        $this->assertSame(['10.0.0.0/8'], $merged['nets']);
        $this->assertSame([], $merged['ours']);
        $this->assertSame(['10.0.0.0/8'], VpnService::mergeLocalNets(['10.0.0.0/8'], $merged['ours'], [])['nets']);
    }

    public function testWantedNetsFollowTheStatus(): void
    {
        $this->assertSame([], VpnService::wantedLocalNets(['server' => ['type' => 'off', 'net' => '10.99.0.0/24'], 'tunnels' => []]));
        $this->assertSame(['10.99.0.0/24', '192.168.50.0/24'], VpnService::wantedLocalNets([
            'server' => ['type' => 'wg', 'net' => '10.99.0.0/24'],
            'tunnels' => [
                ['enabled' => true, 'routes' => ['192.168.50.0/24']],
                ['enabled' => false, 'routes' => ['192.168.51.0/24']],
            ],
        ]), 'a connection switched off carries nothing');
    }

    public function testServerFormBecomesHelperArguments(): void
    {
        $this->assertSame(['type=off'], VpnService::serverArgs(['vpn_type' => 'off', 'vpn_port' => '1']));
        $this->assertSame(['type=wg', 'port=51820', 'proto=udp', 'net=10.99.0.0/24', 'endpoint=pbx.example.com'], VpnService::serverArgs(
            ['vpn_type' => 'wg', 'vpn_port' => '51820', 'vpn_proto' => 'tcp', 'vpn_net' => ' 10.99.0.0/24 ', 'vpn_endpoint' => 'pbx.example.com']
        ), 'WireGuard is always UDP');
    }

    public function testProfileTypeComesFromTheContent(): void
    {
        $this->assertSame('wg', VpnService::detectType("# x\n[Interface]\nPrivateKey = a\n"));
        $this->assertSame('ovpn', VpnService::detectType("client\ndev tun\nremote a 1194\n"));
    }

    public function testServerFormRefusesUnknownType(): void
    {
        $this->expectException(\Exception::class);
        VpnService::serverArgs(['vpn_type' => 'tap']);
    }

    public function testServerFormRefusesControlCharacters(): void
    {
        $this->expectException(\Exception::class);
        VpnService::serverArgs(['vpn_type' => 'wg', 'vpn_port' => '51820', 'vpn_net' => "10.99.0.0/24\nx", 'vpn_endpoint' => 'a']);
    }
}
