<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/PhoneRegionService.php';

final class PhoneRegionTest extends TestCase
{
    private const MAC = '000413aabb01';

    protected function tearDown(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM pbx_phones WHERE mac = '" . self::MAC . "'");
        $db->exec("DELETE FROM pbx_phone_regions WHERE name LIKE 'zz%'");
        $db->exec("UPDATE pbx_phone_regions SET sip_server = '', sip_proxy = '', syslog = '', transport = '', codecs = '', base_url = ''");
        PhoneRegionService::resetCache();
    }

    private function region(string $name): array
    {
        foreach (PhoneRegionService::all() as $r) {
            if ($r['name'] === $name) {
                return $r;
            }
        }
        $this->fail('no region ' . $name);
    }

    public function testInstallationsStartWithInternalAndExternal(): void
    {
        $this->assertNotEmpty($this->region('Internal')['is_default']);
        $this->assertEmpty($this->region('External')['is_default']);
        $this->assertSame((int) $this->region('Internal')['id'], PhoneRegionService::defaultId());
    }

    public function testAnEmptyRegionChangesNothingAndNoneClears(): void
    {
        $general = ['provision_sip_proxy' => 'proxy.lan', 'provision_syslog' => '10.0.0.1', 'provision_transport' => 'udp', 'provision_ntp' => 'ntp.lan'];
        $this->assertSame($general, PhoneRegionService::apply($general, ['sip_proxy' => '', 'syslog' => '', 'transport' => '']));
        $out = PhoneRegionService::apply($general, ['sip_proxy' => 'none', 'syslog' => 'none', 'transport' => 'tls']);
        $this->assertSame('', $out['provision_sip_proxy']);
        $this->assertSame('', $out['provision_syslog']);
        $this->assertSame('tls', $out['provision_transport']);
        $this->assertSame('ntp.lan', $out['provision_ntp']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badFields(): array
    {
        return [
            'server' => [['sip_server' => 'a b']],
            'server none' => [['sip_server' => 'a;b']],
            'proxy' => [['sip_proxy' => 'x"><y']],
            'syslog' => [['syslog' => 'host.name']],
            'url' => [['base_url' => 'ftp://x']],
            'time zone' => [['timezone' => 'Mars/Base']],
            'sip443 name' => [['sip443_sources' => 'phones.example.org']],
            'sip443 prefix' => [['sip443_sources' => '10.0.0.0/33']],
            'sip443 inject' => [['sip443_sources' => '10.0.0.1 1; } include /etc/passwd;']],
            'sip443 with nothing' => [['sip443' => '1']],
            'sip443 by an address only' => [['sip443' => '1', 'sip_server' => '10.0.0.5']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badFields')]
    public function testBadFieldsAreRefused(array $data): void
    {
        $this->expectException(\Exception::class);
        PhoneRegionService::validate($data);
    }

    public function testValidateKeepsWhatIsRight(): void
    {
        $out = PhoneRegionService::validate(['sip_server' => 'fax.example.org', 'sip_proxy' => 'none', 'syslog' => 'none', 'transport' => 'tls', 'srtp' => '2',
            'base_url' => 'https://fax.example.org/', 'codecs' => ['pcmu', 'opus', 'bad'], 'timezone' => 'Europe/Istanbul', 'language' => 'tr', 'ntp' => 'pool.ntp.org']);
        $this->assertSame('https://fax.example.org', $out['base_url']);
        $this->assertSame('opus,pcmu', $out['codecs']);
        $this->assertSame('none', $out['sip_proxy']);
        $this->assertSame('', PhoneRegionService::validate(['transport' => 'carrier-pigeon'])['transport']);
        $this->assertSame('91.113.163.218, 10.1.0.0/16, 2001:db8::/32',
            PhoneRegionService::validate(['sip443_sources' => ' 91.113.163.218,10.1.0.0/16 , 2001:db8::/32, 91.113.163.218'])['sip443_sources']);
    }

    public function testSip443TakesTheRegionsOwnNameOrItsAddresses(): void
    {
        $out = PhoneRegionService::validate(['sip443' => '1', 'sip_server' => 'sip1.example.org', 'transport' => 'udp', 'sip_port' => '5060']);
        $this->assertSame(['1', 'tls', '443'], [$out['sip443'], $out['transport'], $out['sip_port']], 'these phones talk TLS to 443');
        $this->assertSame('sip1.example.org', PhoneRegionService::sip443Name($out));
        $this->assertSame('', PhoneRegionService::sip443Name(['sip_server' => '10.0.0.5']), 'an IP address names nothing');
        $this->assertSame('1', PhoneRegionService::validate(['sip443' => '1', 'sip443_sources' => '91.113.163.218'])['sip443']);
        $this->assertSame('0', PhoneRegionService::validate([])['sip443']);
    }

    public function testAPhoneGetsItsRegionsAddresses(): void
    {
        $external = $this->region('External');
        $id = PhoneProvisionService::upsertPhone(0, self::MAC, 'snom-d865', 0);
        $db = getDB();
        $phone = PhoneProvisionService::getPhone($id);
        $this->assertSame((int) $this->region('Internal')['id'], (int) $phone['region_id'], 'a new phone starts in the default region');

        $general = PhoneProvisionService::buildContext($phone, ['id' => 0, 'extension' => '1000', 'full_name' => 'A', 'sip_password' => 'x']);
        $generalUrl = PhoneProvisionService::provisioningUrl($phone);

        $db->prepare("UPDATE pbx_phone_regions SET sip_server = 'out.example.org', transport = 'tls', codecs = 'pcma', syslog = 'none', base_url = 'https://out.example.org' WHERE id = ?")->execute([$external['id']]);
        PhoneRegionService::resetCache();
        $this->assertSame($general['server'], PhoneProvisionService::buildContext($phone, ['id' => 0, 'extension' => '1000', 'full_name' => 'A', 'sip_password' => 'x'])['server'], 'another region changes nothing here');

        PhoneProvisionService::upsertPhone($id, self::MAC, 'snom-d865', 0, '', (int) $external['id']);
        $phone = PhoneProvisionService::getPhone($id);
        $ctx = PhoneProvisionService::buildContext($phone, ['id' => 0, 'extension' => '1000', 'full_name' => 'A', 'sip_password' => 'x']);
        $this->assertSame('out.example.org', $ctx['server']);
        $this->assertSame('tls', $ctx['transport']);
        $this->assertSame(['pcma'], $ctx['codecs']);
        $this->assertSame('', $ctx['syslog']);
        $this->assertStringStartsWith('https://out.example.org/provision/', PhoneProvisionService::provisioningUrl($phone));
        $this->assertNotSame($generalUrl, PhoneProvisionService::provisioningUrl($phone));

        PhoneProvisionService::upsertPhone($id, self::MAC, 'snom-d865', 0, 'note');
        $this->assertSame((int) $external['id'], (int) PhoneProvisionService::getPhone($id)['region_id'], 'a save without a region keeps it');
    }

    public function testAnUnknownRegionIsRefused(): void
    {
        $this->expectException(\Exception::class);
        PhoneProvisionService::upsertPhone(0, self::MAC, 'snom-d865', 0, '', 999999);
    }

    public function testDefaultAndUsedRegionsCannotBeDeleted(): void
    {
        $_SESSION['csrf_token'] = 'test';
        $default = PhoneRegionService::delete(['csrf_token' => 'test', 'region_id' => PhoneRegionService::defaultId()]);
        $this->assertFalse($default['success'] ?? true);
        $external = $this->region('External');
        PhoneProvisionService::upsertPhone(0, self::MAC, 'snom-d865', 0, '', (int) $external['id']);
        $used = PhoneRegionService::delete(['csrf_token' => 'test', 'region_id' => (int) $external['id']]);
        $this->assertFalse($used['success'] ?? true);
        $this->assertSame('External', $this->region('External')['name']);
        $added = PhoneRegionService::save(['csrf_token' => 'test', 'name' => 'zzThird', 'sip_server' => 'x.example.org']);
        $this->assertNotEmpty($added['success'] ?? false, json_encode($added));
        PhoneRegionService::resetCache();
        $gone = PhoneRegionService::delete(['csrf_token' => 'test', 'region_id' => (int) $this->region('zzThird')['id']]);
        $this->assertNotEmpty($gone['success'] ?? false, json_encode($gone));
    }
}
