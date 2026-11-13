<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/services/TrunkService.php';

/**
 * Free-text SIP fields of a trunk. A space in From user passed the reload (Asterisk
 * kept the old object) and removed the whole trunk at the next restart.
 */
final class TrunkFieldValidationTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::load();
        $_SESSION['csrf_token'] = 'trk';
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        getDB()->exec("DELETE FROM pbx_trunks WHERE trunk_name = 'fieldtr'");
        SIPHelper::deleteSettings('fieldtr');
    }

    private function save(array $extra): array
    {
        return TrunkService::saveTrunk(['csrf_token' => 'trk', 'trunk_name' => 'fieldtr', 'title' => 'Field trunk', 'ip_address' => '192.0.2.77',
            'port' => '5060', 'transport' => 'udp', 'codecs' => 'alaw,ulaw', 'connection_mode' => 'ip', 'is_active' => '1'] + $extra);
    }

    public function testTypingErrorsAreRefused(): void
    {
        foreach ([['from_user' => 'user name'], ['from_domain' => 'sip.example .net'], ['outbound_proxy' => 'sip:proxy example.net'],
                  ['match_hosts' => '192.0.2.77, 10.0.0']] as $bad) {
            $this->assertFalse($this->save($bad)['success'], json_encode($bad));
        }
    }

    public function testOrdinaryValuesAreKeptAndAProxyGetsItsScheme(): void
    {
        $res = $this->save(['from_user' => '+903125550000', 'from_domain' => 'sip.example.net', 'outbound_proxy' => 'proxy.example.net:5080',
            'match_hosts' => '192.0.2.77, 198.51.100.0/24, sbc.example.net']);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $map = SIPHelper::getSettingsMap('fieldtr');
        $this->assertSame('sip:proxy.example.net:5080', $map['outbound_proxy'] ?? '');
        $this->assertSame('+903125550000', $map['from_user'] ?? '');
    }

    public function testANewTrunkNameNeedsALetter(): void
    {
        $res = TrunkService::saveTrunk(['csrf_token' => 'trk', 'trunk_name' => '12345', 'title' => 'Digits', 'ip_address' => '192.0.2.77',
            'port' => '5060', 'transport' => 'udp', 'codecs' => 'alaw,ulaw', 'connection_mode' => 'ip', 'is_active' => '1']);
        $this->assertFalse($res['success']);
        $this->assertSame(0, (int) getDB()->query("SELECT COUNT(*) FROM pbx_trunks WHERE trunk_name = '12345'")->fetchColumn());
    }

    public function testProxyParametersAreKeptAndEscapedInTheConfig(): void
    {
        foreach (['sip:proxy.example.net;lr', 'sip:proxy.example.net\\;lr', 'sip:proxy.example.net:5061;transport=tls;lr'] as $typed) {
            $this->cleanUp();
            $res = $this->save(['outbound_proxy' => $typed]);
            $this->assertTrue($res['success'], $typed . ': ' . ($res['error'] ?? ''));
            $stored = SIPHelper::getSettingsMap('fieldtr')['outbound_proxy'] ?? '';
            $this->assertSame(str_replace('\\;', ';', $typed), $stored);
            $this->assertSame(str_replace(';', '\\;', $stored), confValue($stored));
        }
    }

    public function testAnOldValueWithASpaceNoLongerReachesTheConfig(): void
    {
        $this->assertTrue($this->save([])['success']);
        SIPHelper::setSettings('fieldtr', ['from_user' => 'user name']);
        syncAllTrunks();
        $conf = (string) file_get_contents(ASTERISK_PBX_DIR . '/pjsip_trunks.conf');
        $this->assertStringContainsString("from_user=username\n", $conf);
    }
}
