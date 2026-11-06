<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/FirewallService.php';

final class FirewallCardsTest extends TestCase
{
    private const OVERVIEW = <<<'TXT'
default public
iface eth0 public
iface eth1 public
iface aipbxs0 aipbx-vpn
### zone aipbx-vpn
aipbx-vpn (active)
  target: default
  services: 
  ports: 51820/udp
  rich rules: 
### zone public
public (default, active)
  target: default
  interfaces: eth0 eth1
  services: dhcpv6-client http https ssh
  ports: 5060/udp 10000-20000/udp 8080/tcp
  protocols: 
  rich rules: 
	rule family="ipv4" source address="192.168.1.0/24" port port="123" protocol="udp" accept
	rule family="ipv4" source address="10.0.0.5" service name="tftp" log prefix="x" accept
TXT;

    private const IPS = ['eth0' => ['10.0.0.1'], 'eth1' => ['192.168.1.1']];

    private function rule(array $card, string $port, string $proto = ''): array
    {
        foreach ($card['rules'] as $r) {
            if ($r['port'] === $port && ($proto === '' || $r['protocol'] === $proto)) {
                return $r;
            }
        }
        $this->fail("no rule for {$port}");
    }

    public function testAllRuleKindsBecomeTheSameRow(): void
    {
        $zone = FirewallService::parseOverview(self::OVERVIEW)['zones']['public'];
        $this->assertSame('public', FirewallService::parseOverview(self::OVERVIEW)['default']);
        $byValue = array_column($zone['rules'], null, 'value');
        $this->assertSame(['service', '443', 'tcp', ''], [$byValue['https']['kind'], $byValue['https']['port'], $byValue['https']['protocol'], $byValue['https']['source']]);
        $this->assertSame('RTP', $byValue['10000-20000/udp']['use']);
        $rich = $byValue['rule family="ipv4" source address="192.168.1.0/24" port port="123" protocol="udp" accept'];
        $this->assertSame(['123', 'udp', '192.168.1.0/24', 'NTP'], [$rich['port'], $rich['protocol'], $rich['source'], $rich['use']]);
        $custom = $byValue['rule family="ipv4" source address="10.0.0.5" service name="tftp" log prefix="x" accept'];
        $this->assertTrue($custom['custom']);
        $this->assertSame('', $custom['port']);
    }

    public function testEveryInterfaceIsListedCardsFirst(): void
    {
        $built = FirewallService::buildCards(FirewallService::parseOverview(self::OVERVIEW), self::IPS, 'eth1');
        $this->assertSame(['eth0', 'eth1', 'aipbxs0'], array_column($built['cards'], 'iface'));
        $this->assertTrue($built['cards'][2]['virtual']);
        $this->assertFalse($built['cards'][0]['own']);
    }

    public function testNothingIsLockedTheRuleOnlySaysWhatItDoes(): void
    {
        [$eth0, $eth1] = FirewallService::buildCards(FirewallService::parseOverview(self::OVERVIEW), self::IPS, 'eth1')['cards'];
        $this->assertSame('', $this->rule($eth0, '5060')['warn'], 'SIP is still on eth1');
        $this->assertSame('', $this->rule($eth0, '443')['warn'], 'the session is on eth1');
        $this->assertSame('session', $this->rule($eth1, '443')['warn'], 'the session comes through eth1');
        $this->assertSame('', $this->rule($eth1, '8080')['warn']);
    }

    public function testAccessPortsWarnEverywhereWhenTheSessionIsUnknown(): void
    {
        [$eth0, $eth1] = FirewallService::buildCards(FirewallService::parseOverview(self::OVERVIEW), self::IPS, null)['cards'];
        $this->assertSame('session', $this->rule($eth0, '443')['warn']);
        $this->assertSame('session', $this->rule($eth1, '22')['warn']);
        $this->assertSame('', $this->rule($eth1, '5060')['warn']);
    }

    public function testTheLastCardWithACriticalPortIsTold(): void
    {
        $text = "default public\niface eth0 public\niface eth1 aipbx-card1\n"
            . "### zone aipbx-card1\naipbx-card1 (active)\n  target: default\n  services: ssh\n  ports: \n"
            . "### zone public\npublic\n  target: default\n  services: ssh\n  ports: 5060/udp\n";
        [$eth0] = FirewallService::buildCards(FirewallService::parseOverview($text), self::IPS, 'eth1')['cards'];
        $this->assertSame('last', $this->rule($eth0, '5060')['warn'], 'eth1 has no SIP');
        $this->assertSame('', $this->rule($eth0, '22')['warn'], 'eth1 has SSH');
    }

    public function testACardWithoutAnAddressDoesNotCountAsOffering(): void
    {
        $built = FirewallService::buildCards(FirewallService::parseOverview(self::OVERVIEW), ['eth0' => ['10.0.0.1']], 'eth0');
        $this->assertSame('last', $this->rule($built['cards'][0], '5060')['warn']);
    }

    public function testCardNetworksCountAsLocal(): void
    {
        $addresses = [
            ['iface' => 'eth0', 'ip' => '10.23.3.70', 'prefix' => 24],
            ['iface' => 'eth1', 'ip' => '172.16.5.9', 'prefix' => 16],
            ['iface' => 'docker0', 'ip' => '172.17.0.1', 'prefix' => 16],
            ['iface' => 'eth2', 'ip' => '203.0.113.5', 'prefix' => 32],
        ];
        $this->assertSame(['172.16.0.0/255.255.0.0'], NetworkSettingsService::connectedNetworks(['10.0.0.0/8'], $addresses), 'covered, virtual and /32 are left out');
        $this->assertSame(['10.23.3.0/255.255.255.0', '172.16.0.0/255.255.0.0'], NetworkSettingsService::connectedNetworks(['10.23.3.128/255.255.255.128'], $addresses), 'a smaller listed network does not cover the card');
    }

    public function testACardWithItsOwnZoneShowsOnlyWhatDiffersFromTheCommonRules(): void
    {
        // eth1 has its own copy: 8080 closed there, 9000 added.
        $text = str_replace('iface eth1 public', 'iface eth1 aipbx-card1', self::OVERVIEW) . <<<'TXT'

### zone aipbx-card1
aipbx-card1 (active)
  target: default
  services: dhcpv6-client http https ssh
  ports: 5060/udp 10000-20000/udp 9000/tcp
  rich rules: 
	rule family="ipv4" source address="192.168.1.0/24" port port="123" protocol="udp" accept
	rule family="ipv4" source address="10.0.0.5" service name="tftp" log prefix="x" accept
TXT;
        $built = FirewallService::buildCards(FirewallService::parseOverview($text), self::IPS, null);
        $cards = array_column($built['cards'], null, 'iface');
        $this->assertSame([], $cards['eth0']['extra'], 'a card on the common rules has nothing of its own');
        $this->assertSame([], $cards['eth0']['closed_common']);
        $this->assertSame(['9000/tcp'], array_column($cards['eth1']['extra'], 'value'));
        $this->assertSame(['8080/tcp'], array_column($cards['eth1']['closed_common'], 'value'));
        $common = array_column($built['common'], null, 'value');
        $this->assertSame(['eth1'], $common['8080/tcp']['closed_on']);
        $this->assertSame([], $common['5060/udp']['closed_on']);
        $this->assertSame('session', $common['ssh']['warn'], 'removing SSH from every card can cut the session');
        $this->assertSame('', $common['8080/tcp']['warn']);
    }
}
