<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/NetworkSettingsService.php';

/** Network → Network settings: validation, stale addresses and moving to a new IP (roadmap 12, 13). */
final class NetworkSettingsTest extends TestCase
{
    private const SETTINGS = [
        'server_ip' => '10.8.0.12', 'pjsip_external_ip' => '212.147.231.58', 'pjsip_local_net' => '192.168.100.0/24,10.8.0.0/24',
        'provision_sip_server' => '10.8.0.12', 'provision_sip_proxy' => '', 'provision_base_url' => 'https://10.8.0.12:8443',
        'provision_syslog' => '10.8.0.12', 'provision_ntp' => 'pool.ntp.org', 'provision_allowed_networks' => "10.8.0.0/24",
    ];

    public function testReplaceHostOnlyReplacesTheWholeAddress(): void
    {
        $this->assertSame('10.9.0.5', NetworkSettingsService::replaceHost('10.8.0.1', '10.8.0.1', '10.9.0.5'));
        // 10.8.0.1 is a prefix of 10.8.0.12 and a suffix of 110.8.0.1: neither may change.
        $this->assertSame('10.8.0.12', NetworkSettingsService::replaceHost('10.8.0.12', '10.8.0.1', '10.9.0.5'));
        $this->assertSame('110.8.0.1', NetworkSettingsService::replaceHost('110.8.0.1', '10.8.0.1', '10.9.0.5'));
        $this->assertSame('https://10.9.0.5:8443', NetworkSettingsService::replaceHost('https://10.8.0.1:8443', '10.8.0.1', '10.9.0.5'));
    }

    public function testServerFormValidation(): void
    {
        $v = NetworkSettingsService::validateServer(['server_ip' => ' 10.0.0.5 ', 'pjsip_external_ip' => '', 'pjsip_local_net' => "10.0.0.0/8\n192.168.1.0/24, 10.0.0.0/8"]);
        $this->assertSame(['server_ip' => '10.0.0.5', 'pjsip_external_ip' => '', 'pjsip_local_net' => '10.0.0.0/8,192.168.1.0/24'], $v);
        foreach ([
            ['server_ip' => '10.0.0.256'],
            ['pjsip_external_ip' => 'pbx.example.com'],
            ['pjsip_local_net' => '10.0.0.0'],
            ['pjsip_local_net' => '10.0.0.0/33'],
            // A line break must never reach pjsip.conf (local_net=...).
            ['pjsip_local_net' => "10.0.0.0/8\nexternal_media_address=1.2.3.4"],
        ] as $bad) {
            try {
                NetworkSettingsService::validateServer($bad);
                $this->fail('accepted: ' . json_encode($bad));
            } catch (\Exception $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testStaleAddressesAfterAnIpChange(): void
    {
        $files = ['coturn_relay' => '10.8.0.12', 'mdns' => ''];
        $this->assertSame([], NetworkSettingsService::staleAddresses(['10.8.0.12', '192.168.100.173'], self::SETTINGS, $files));
        $stale = NetworkSettingsService::staleAddresses(['10.9.0.5'], self::SETTINGS, $files);
        $this->assertSame(['server_ip', 'provision_sip_server', 'provision_base_url', 'provision_syslog', 'coturn_relay'], array_column($stale, 'where'));
        $this->assertSame(['10.8.0.12'], array_values(array_unique(array_column($stale, 'value'))));
        // A name instead of an address is not checked.
        $this->assertSame([], NetworkSettingsService::staleAddresses(['10.9.0.5'], ['provision_sip_server' => 'pbx.example.com'], []));
    }

    public function testReaddressPreview(): void
    {
        $files = ['coturn_relay' => '10.8.0.12', 'mdns' => '10.8.0.12', 'cert_ips' => '10.8.0.12'];
        $changes = NetworkSettingsService::readdressPreview('10.8.0.12', '172.20.1.7', self::SETTINGS, $files, 16);
        $by = array_column($changes, null, 'where');
        $this->assertSame('172.20.1.7', $by['server_ip']['to']);
        $this->assertSame('172.20.1.7', $by['provision_sip_server']['to']);
        $this->assertSame('https://172.20.1.7:8443', $by['provision_base_url']['to']);
        $this->assertSame('172.20.1.7', $by['provision_syslog']['to']);
        // The new address is in no local network: its network (interface prefix) is added.
        $this->assertSame('192.168.100.0/24,10.8.0.0/24,172.20.0.0/16', $by['pjsip_local_net']['to']);
        $this->assertArrayHasKey('provision_allowed_networks', $by, 'the old address was allowed, the new one is not');
        $this->assertArrayHasKey('coturn_relay', $by);
        $this->assertArrayHasKey('mdns', $by);
        $this->assertArrayHasKey('certificate', $by);
        // The public address and the NTP server elsewhere stay.
        $this->assertArrayNotHasKey('pjsip_external_ip', $by);
        $this->assertArrayNotHasKey('provision_ntp', $by);

        $this->expectException(\Exception::class);
        NetworkSettingsService::readdressPreview('10.8.0.12', '10.8.0.12', self::SETTINGS, $files);
    }
}
