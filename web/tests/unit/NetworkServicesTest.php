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
        $args = NetworkServicesService::privArgs($v, ['10.0.0.0/8'], 'ftp://wrong/provision/');
        $this->assertContains('tftp=0', $args);
        $this->assertEmpty(array_filter($args, fn($a) => str_starts_with($a, 'net=') || str_starts_with($a, 'url=')));
    }

    public function testPlainHttpAddressGoesToOption66(): void
    {
        $v = NetworkServicesService::validate($this->dhcpForm([]), self::IFACES, []);
        $this->assertContains('url=http://10.23.3.70/provision/', NetworkServicesService::privArgs($v, [], 'http://10.23.3.70/provision/'));
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

    public function testFtpNeedsPasswordsAndCarriesOnlyHashes(): void
    {
        // On without any user: NECSDT700 with NEC's default password, no anonymous login.
        $v = NetworkServicesService::validate(['netsvc_mode' => 'off', 'netsvc_ftp' => '1'], self::IFACES, ['192.168.10.0/24', '2001:db8::/32']);
        $args = NetworkServicesService::ftpArgs($v, ['192.168.10.0/24', '2001:db8::/32']);
        $this->assertSame(['netsvc', 'ftp-on'], array_slice($args, 0, 2));
        $this->assertMatchesRegularExpression('#^user=NECSDT700:\$6\$[A-Za-z0-9./]{1,16}\$[A-Za-z0-9./]{86}$#', $args[2]);
        $this->assertTrue(password_verify('NECSdt700', substr($args[2], strlen('user=NECSDT700:'))));
        $this->assertSame(['net=192.168.10.0/24'], array_slice($args, 3));
        $this->assertNotContains('anonymous=1', $args);
        $this->assertSame('', $v['netsvc_ftp_fixed_hash']);

        // Anonymous login only when switched on.
        $v = NetworkServicesService::validate(['netsvc_mode' => 'off', 'netsvc_ftp' => '1', 'netsvc_ftp_anonymous' => '1'], self::IFACES, ['192.168.10.0/24']);
        $this->assertContains('anonymous=1', NetworkServicesService::ftpArgs($v, ['192.168.10.0/24']));

        // An own NECSDT700 password is stored as a hash and kept by an empty field.
        $v = NetworkServicesService::validate(['netsvc_mode' => 'off', 'netsvc_ftp' => '1', 'netsvc_ftp_fixed_password' => 'Phone-77'], self::IFACES, ['192.168.10.0/24']);
        $this->assertTrue(password_verify('Phone-77', $v['netsvc_ftp_fixed_hash']));
        $kept = NetworkServicesService::validate(['netsvc_mode' => 'off', 'netsvc_ftp' => '1'], self::IFACES, ['192.168.10.0/24'], [], $v['netsvc_ftp_fixed_hash']);
        $this->assertSame($v['netsvc_ftp_fixed_hash'], $kept['netsvc_ftp_fixed_hash']);
        $this->assertSame('user=NECSDT700:' . $v['netsvc_ftp_fixed_hash'], NetworkServicesService::ftpArgs($kept, ['192.168.10.0/24'])[2]);
        $this->assertStringNotContainsString('Phone-77', implode(' ', NetworkServicesService::ftpArgs($kept, ['192.168.10.0/24'])));

        // An extra user goes over as name:hash only, after NECSDT700.
        $v = NetworkServicesService::validate(['netsvc_mode' => 'off', 'netsvc_ftp' => '1',
            'netsvc_ftp_new_user' => 'yealink', 'netsvc_ftp_new_password' => 'Secret123'], self::IFACES, ['192.168.10.0/24']);
        $args = NetworkServicesService::ftpArgs($v, ['192.168.10.0/24']);
        $this->assertMatchesRegularExpression('#^user=yealink:\$6\$[A-Za-z0-9./]{1,16}\$[A-Za-z0-9./]{86}$#', $args[3], 'the form aipbx-priv accepts');
        $this->assertTrue(password_verify('Secret123', substr($args[3], strlen('user=yealink:'))));
        $this->assertStringNotContainsString('Secret123', implode(' ', $args));

        // Kept on the next save, removed when ticked, password replaced by the same name.
        $users = NetworkServicesService::ftpUsers($v['netsvc_ftp_users']);
        $v2 = NetworkServicesService::validate(['netsvc_mode' => 'off', 'netsvc_ftp' => '1',
            'netsvc_ftp_new_user' => 'snom', 'netsvc_ftp_new_password' => 'pass1234'], self::IFACES, ['192.168.10.0/24'], $users);
        $this->assertSame(['yealink', 'snom'], array_column(NetworkServicesService::ftpUsers($v2['netsvc_ftp_users']), 'user'));
        $v3 = NetworkServicesService::validate(['netsvc_mode' => 'off', 'netsvc_ftp' => '1', 'netsvc_ftp_remove' => ['yealink'],
            'netsvc_ftp_new_user' => 'snom', 'netsvc_ftp_new_password' => 'other123'], self::IFACES, ['192.168.10.0/24'],
            NetworkServicesService::ftpUsers($v2['netsvc_ftp_users']));
        $left = NetworkServicesService::ftpUsers($v3['netsvc_ftp_users']);
        $this->assertSame(['snom'], array_column($left, 'user'));
        $this->assertTrue(password_verify('other123', $left[0]['hash']));
    }

    public function testFtpOffAndBadFtpForms(): void
    {
        $v = NetworkServicesService::validate(['netsvc_mode' => 'off'], self::IFACES, []);
        $this->assertSame(['netsvc', 'ftp-off'], NetworkServicesService::ftpArgs($v, []));
        // The phone network of DHCP mode is enough for FTP.
        $v = NetworkServicesService::validate($this->dhcpForm(['netsvc_ftp' => '1']), self::IFACES, []);
        $this->assertSame('net=192.168.10.0/24', array_slice(NetworkServicesService::ftpArgs($v, []), -1)[0]);

        foreach ([
            ['netsvc_ftp_new_user' => 'x', 'netsvc_ftp_new_password' => 'abc'],        // too short
            ['netsvc_ftp_new_user' => 'x', 'netsvc_ftp_new_password' => 'has space'],
            ['netsvc_ftp_new_user' => 'bad user', 'netsvc_ftp_new_password' => 'pass1234'],
            ['netsvc_ftp_new_user' => "x\nnet=0.0.0.0/0", 'netsvc_ftp_new_password' => 'pass1234'],
            ['netsvc_ftp_new_user' => 'NECSDT700', 'netsvc_ftp_new_password' => 'pass1234'],
            ['netsvc_ftp_new_user' => 'anonymous', 'netsvc_ftp_new_password' => 'pass1234'],
            ['netsvc_ftp_fixed_password' => 'abc'],                                       // too short
            ['netsvc_ftp_fixed_password' => "pa ss\nword"],
        ] as $over) {
            try {
                NetworkServicesService::validate($over + ['netsvc_mode' => 'off', 'netsvc_ftp' => '1'], self::IFACES, ['192.168.10.0/24']);
                $this->fail('accepted: ' . json_encode($over));
            } catch (\Exception $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->expectException(\Exception::class);
        NetworkServicesService::validate(['netsvc_mode' => 'off', 'netsvc_ftp' => '1'], self::IFACES, []);
    }

    public function testExtraDhcpOptions(): void
    {
        $v = NetworkServicesService::validate($this->dhcpForm(['netsvc_dhcp_options' => "66, ftp://192.168.10.2/\n# NEC\n\n43,0a:04:c0:a8:0a:02"]), self::IFACES, []);
        $this->assertSame("66,ftp://192.168.10.2/\n43,0a:04:c0:a8:0a:02", $v['netsvc_dhcp_options']);
        $args = NetworkServicesService::privArgs($v, [], 'https://pbx.example.com/provision/');
        $this->assertContains('opt=66,ftp://192.168.10.2/', $args);
        $this->assertContains('opt=43,0a:04:c0:a8:0a:02', $args);

        foreach (["66,\"x\"", "300,1", "0,1", "66,a#b", "66,a\\b", "x,1", "66,", "66,dhcp-script=/tmp/x\nfoo"] as $bad) {
            try {
                NetworkServicesService::validate($this->dhcpForm(['netsvc_dhcp_options' => $bad]), self::IFACES, []);
                $this->fail('accepted: ' . json_encode($bad));
            } catch (\Exception $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testSyslogArgs(): void
    {
        $v = NetworkServicesService::validate($this->dhcpForm(['netsvc_syslog' => '1']), self::IFACES, ['10.0.0.0/8']);
        $this->assertSame('1', $v['netsvc_syslog']);
        $args = NetworkServicesService::syslogArgs($v, ['10.0.0.0/8']);
        $this->assertSame(['netsvc', 'syslog-on'], array_slice($args, 0, 2));
        $this->assertContains('net=10.0.0.0/8', $args);
        $this->assertSame(['netsvc', 'syslog-off'], NetworkServicesService::syslogArgs(['netsvc_syslog' => '0'] + $v, []));
    }

    public function testNtpServerArgs(): void
    {
        $v = NetworkServicesService::validate($this->dhcpForm(['netsvc_ntp_server' => '1']), self::IFACES, ['10.0.0.0/8']);
        $this->assertSame('1', $v['netsvc_ntp_server']);
        $args = NetworkServicesService::ntpArgs($v, ['10.0.0.0/8']);
        $this->assertSame(['netsvc', 'ntp-on'], array_slice($args, 0, 2));
        $this->assertContains('net=10.0.0.0/8', $args);
        $this->assertSame(['netsvc', 'ntp-off'], NetworkServicesService::ntpArgs(['netsvc_ntp_server' => '0'] + $v, []));
        // Without allowed networks (and no DHCP) there is nobody to serve.
        $this->expectException(\Exception::class);
        NetworkServicesService::validate(['netsvc_mode' => 'off', 'netsvc_ntp_server' => '1'], self::IFACES, []);
    }
}
