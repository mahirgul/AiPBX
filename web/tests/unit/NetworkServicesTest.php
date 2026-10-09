<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/NetworkServicesService.php';

/**
 * Admin → Network services (roadmap item 10): form validation, the
 * aipbx-priv arguments, the DHCP check output, leases and TFTP file names.
 */
final class NetworkServicesTest extends TestCase
{
    private const IFACES = ['eth1' => ['ip' => '192.168.10.2', 'prefix' => 24]];

    private function dhcpForm(array $over = []): array
    {
        return $over + [
            'netsvc_mode' => 'dhcp',
            'netsvc_interface' => 'eth1',
            'netsvc_range_start' => '192.168.10.100',
            'netsvc_range_end' => '192.168.10.199',
            'netsvc_netmask' => '255.255.255.0',
            'netsvc_gateway' => '192.168.10.1',
            'netsvc_dns' => '192.168.10.1, 1.1.1.1',
            'netsvc_ntp' => '',
            'netsvc_lease_hours' => '12',
            'netsvc_tftp' => '1',
            'netsvc_confirm' => '1',
        ];
    }

    public function testOffNeedsNothing(): void
    {
        $v = NetworkServicesService::validate(['netsvc_mode' => 'off'], [], []);
        $this->assertSame('off', $v['netsvc_mode']);
        $this->assertSame([], NetworkServicesService::privArgs($v, [], ''));
    }

    public function testUnknownModeIsRefused(): void
    {
        $this->expectException(\Exception::class);
        NetworkServicesService::validate(['netsvc_mode' => 'router'], [], []);
    }

    public function testTftpNeedsAllowedNetworks(): void
    {
        $this->expectException(\Exception::class);
        NetworkServicesService::validate(['netsvc_mode' => 'tftp'], self::IFACES, []);
    }

    public function testTftpArguments(): void
    {
        $v = NetworkServicesService::validate(['netsvc_mode' => 'tftp'], self::IFACES, ['192.168.10.0/24', '2001:db8::/32']);
        $args = NetworkServicesService::privArgs($v, ['192.168.10.0/24', '2001:db8::/32'], 'https://pbx.example.com/provision/');
        // IPv6 networks are left out: aipbx-priv opens TFTP for IPv4 only.
        $this->assertSame(['netsvc', 'apply', 'mode=tftp', 'tftp=1', 'net=192.168.10.0/24'], $args);
    }

    public function testDhcpArguments(): void
    {
        $v = NetworkServicesService::validate($this->dhcpForm(), self::IFACES, []);
        $this->assertSame('192.168.10.1,1.1.1.1', $v['netsvc_dns']);
        $args = NetworkServicesService::privArgs($v, ['10.0.0.0/8'], 'https://pbx.example.com/provision/');
        $this->assertSame([
            'netsvc', 'apply', 'mode=dhcp',
            'iface=eth1', 'start=192.168.10.100', 'end=192.168.10.199', 'mask=255.255.255.0', 'lease=12',
            'gw=192.168.10.1', 'dns=192.168.10.1,1.1.1.1',
            'url=https://pbx.example.com/provision/',
            'tftp=1', 'net=10.0.0.0/8', 'net=192.168.10.0/24',
        ], $args);
    }

    public function testDhcpWithoutTftpHasNoNetworks(): void
    {
        $v = NetworkServicesService::validate($this->dhcpForm(['netsvc_tftp' => '']), self::IFACES, []);
        $args = NetworkServicesService::privArgs($v, ['10.0.0.0/8'], 'http://not-https/provision/');
        $this->assertContains('tftp=0', $args);
        $this->assertEmpty(array_filter($args, fn($a) => str_starts_with($a, 'net=') || str_starts_with($a, 'url=')));
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function badDhcpForms(): array
    {
        return [
            'not confirmed' => [['netsvc_confirm' => '']],
            'unknown interface' => [['netsvc_interface' => 'eth9']],
            'range outside the subnet' => [['netsvc_range_end' => '192.168.11.20']],
            'range reversed' => [['netsvc_range_start' => '192.168.10.200']],
            'range holds the server' => [['netsvc_range_start' => '192.168.10.2']],
            'bad netmask' => [['netsvc_netmask' => '255.0.255.0']],
            'gateway elsewhere' => [['netsvc_gateway' => '10.0.0.1']],
            'bad dns' => [['netsvc_dns' => '1.1.1.1;reboot']],
            'too many ntp' => [['netsvc_ntp' => '1.1.1.1,1.1.1.2,1.1.1.3,1.1.1.4']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badDhcpForms')]
    public function testBadDhcpFormsAreRefused(array $over): void
    {
        $this->expectException(\Exception::class);
        NetworkServicesService::validate($this->dhcpForm($over), self::IFACES, []);
    }

    public function testParseProbe(): void
    {
        $out = "offer server=192.168.10.1 address=192.168.10.57\nnoise\noffer server=192.168.10.1 address=192.168.10.58\noffer server=10.0.0.1 address=10.0.0.9\n";
        $this->assertSame(['192.168.10.1', '10.0.0.1'], NetworkServicesService::parseProbe($out));
        $this->assertSame([], NetworkServicesService::parseProbe(''));
    }

    public function testLeasesAndWaitingPhones(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'leases');
        $later = time() + 3600;
        file_put_contents($file, implode("\n", [
            "$later 00:15:65:aa:bb:01 192.168.10.101 SIP-T46U 01:00:15:65:aa:bb:01",
            "$later 3c:22:fb:00:00:01 192.168.10.102 laptop *",
            "0 00:0b:82:aa:bb:02 192.168.10.103 * *",
            "garbage line",
        ]) . "\n");
        $leases = NetworkServicesService::leases($file);
        unlink($file);

        $this->assertCount(3, $leases);
        $byMac = array_column($leases, null, 'mac');
        $this->assertSame('yealink', $byMac['001565aabb01']['vendor']);
        $this->assertSame('', $byMac['3c22fb000001']['vendor']);
        $this->assertSame('grandstream', $byMac['000b82aabb02']['vendor']);
        $this->assertSame('', $byMac['000b82aabb02']['hostname']);

        $db = getDB();
        $db->exec("DELETE FROM pbx_phones_waiting WHERE mac IN ('001565aabb01', '3c22fb000001', '000b82aabb02')");
        try {
            // Only phones (known vendor) become waiting phones.
            $this->assertSame(2, NetworkServicesService::syncLeasesToWaiting($leases));
            $rows = $db->query("SELECT mac, vendor, ip, user_agent FROM pbx_phones_waiting WHERE mac IN ('001565aabb01', '3c22fb000001', '000b82aabb02') ORDER BY mac")->fetchAll(PDO::FETCH_ASSOC);
            $this->assertSame(['000b82aabb02', '001565aabb01'], array_column($rows, 'mac'));
            $this->assertSame('DHCP', $rows[0]['user_agent']);
            // Running again updates, never duplicates.
            NetworkServicesService::syncLeasesToWaiting($leases);
            $this->assertSame(2, (int) $db->query("SELECT COUNT(*) FROM pbx_phones_waiting WHERE mac IN ('001565aabb01', '000b82aabb02')")->fetchColumn());
        } finally {
            $db->exec("DELETE FROM pbx_phones_waiting WHERE mac IN ('001565aabb01', '3c22fb000001', '000b82aabb02')");
        }
    }

    public function testTftpFileNames(): void
    {
        $this->assertTrue(NetworkServicesService::validTftpName('firmware-T46U-66.86.0.15.rom'));
        $this->assertTrue(NetworkServicesService::validTftpName('spa525g2.bin'));
        $this->assertTrue(NetworkServicesService::validTftpName('y000000000108.cfg'));
        // Per-phone configuration names (a MAC address) are refused.
        $this->assertFalse(NetworkServicesService::validTftpName('001565aabbcc.cfg'));
        $this->assertFalse(NetworkServicesService::validTftpName('cfg00-15-65-AA-BB-CC.xml'));
        $this->assertFalse(NetworkServicesService::validTftpName('../etc/passwd'));
        $this->assertFalse(NetworkServicesService::validTftpName('.hidden'));
    }

    public function testOptionHintsName66And150(): void
    {
        $hints = NetworkServicesService::dhcpOptionHints('https://pbx.example.com/provision/', '192.168.10.2');
        $byOption = array_column($hints, 'value', 'option');
        $this->assertSame('https://pbx.example.com/provision/', $byOption['66']);
        $this->assertSame('192.168.10.2', $byOption['150']);
    }

    public function testSwitchesMapToTheStoredMode(): void
    {
        $v = NetworkServicesService::validate([], self::IFACES, []);
        $this->assertSame('off', $v['netsvc_mode']);
        $v = NetworkServicesService::validate(['netsvc_tftp' => '1'], self::IFACES, ['192.168.10.0/24']);
        $this->assertSame('tftp', $v['netsvc_mode']);
        $this->assertSame('1', $v['netsvc_tftp']);

        // DHCP without TFTP.
        $form = $this->dhcpForm(['netsvc_dhcp_on' => '1']);
        unset($form['netsvc_mode'], $form['netsvc_tftp']);
        $v = NetworkServicesService::validate($form, self::IFACES, []);
        $this->assertSame('dhcp', $v['netsvc_mode']);
        $this->assertSame('0', $v['netsvc_tftp']);
        $this->assertContains('tftp=0', NetworkServicesService::privArgs($v, [], 'https://pbx.example.com/provision/'));
    }
}
