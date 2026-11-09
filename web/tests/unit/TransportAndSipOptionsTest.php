<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/TransportService.php';
require_once dirname(__DIR__, 2) . '/src/services/ExtensionSipOptions.php';
require_once dirname(__DIR__, 2) . '/src/services/PhoneProvisionService.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

final class TransportAndSipOptionsTest extends TestCase
{
    private const OTHERS = [['name' => 'transport-udp', 'protocol' => 'udp', 'port' => 5060], ['name' => 'transport-tls', 'protocol' => 'tls', 'port' => 5061]];
    private const RANGES = [[10000, 12999]];

    public function testAPortMustBeFree(): void
    {
        $this->assertSame('', TransportService::portProblem(5098, 'udp', self::OTHERS, self::RANGES));
        // UDP and TCP are different sockets; TLS holds a TCP port.
        $this->assertSame('', TransportService::portProblem(5060, 'tcp', self::OTHERS, self::RANGES));
        $this->assertNotSame('', TransportService::portProblem(5060, 'udp', self::OTHERS, self::RANGES));
        $this->assertNotSame('', TransportService::portProblem(5061, 'tcp', self::OTHERS, self::RANGES));
        foreach ([0, 70000, 443, 3306, 5038, 8089, 11000] as $bad) {
            $this->assertNotSame('', TransportService::portProblem($bad, 'udp', self::OTHERS, self::RANGES), (string) $bad);
        }
    }

    public function testUptimeIsRead(): void
    {
        $this->assertSame(1234, TransportService::parseUptime("System uptime: 1234\nLast reload: 5"));
        $this->assertNull(TransportService::parseUptime('Unable to connect to remote asterisk'));
    }

    public function testAnAddedTransportAnswersFromItsOwnPort(): void
    {
        $conf = buildCustomTransport(['name' => 'out-1', 'protocol' => 'udp', 'bind_addr' => '0.0.0.0', 'port' => 5098, 'external_address' => 'out.example.org'], "local_net=10.0.0.0/8\n", "external_media_address=1.1.1.1\n", '', '', 'tlsv1_2');
        $this->assertStringContainsString("[out-1]\ntype=transport\nprotocol=udp\nbind=0.0.0.0:5098\nsymmetric_transport=yes\n", $conf);
        $this->assertStringContainsString('external_signaling_address=out.example.org', $conf);
        $this->assertStringNotContainsString('1.1.1.1', $conf);
        $tls = buildCustomTransport(['name' => 'x', 'protocol' => 'tls', 'bind_addr' => '0.0.0.0', 'port' => 5071, 'external_address' => ''], '', "external_media_address=1.1.1.1\n", '/c.pem', '/k.pem', 'tlsv1_2');
        $this->assertStringContainsString('cert_file=/c.pem', $tls);
        $this->assertStringContainsString('external_media_address=1.1.1.1', $tls);
        $hostile = buildCustomTransport(['name' => "a]\n[b", 'protocol' => 'evil', 'bind_addr' => "1.1.1.1\nx=1", 'port' => 1, 'external_address' => "h\nx=2"], '', '', '', '', 'tlsv1_2');
        $this->assertSame(1, substr_count($hostile, '['), 'a row from a backup cannot start a section');
        $this->assertStringNotContainsString("\nx=", $hostile);
    }

    public function testExtensionOptionsAreAFixedList(): void
    {
        $v = ExtensionSipOptions::fromPost([
            'adv_transport' => 'tcp',
            'adv_dtmf_mode' => 'evil',
            'adv_force_rport' => 'no',
            'adv_allow' => 'ulaw, opus,evil,ulaw',
            'adv_language' => 'tr',
            'adv_qualify_frequency' => '30',
            'adv_media_encryption' => 'dtls',
            'adv_ice_support' => 'yes',
            'adv_media_encryption_optimistic' => 'yes',
            'adv_media_address' => '192.168.1.50',
            'adv_rtp_timeout' => '60',
            'adv_rtp_keepalive' => '15',
        ]);
        $this->assertSame('tcp', $v['transport']);
        $this->assertSame('', $v['dtmf_mode']);
        $this->assertSame('no', $v['force_rport']);
        $this->assertSame('ulaw,opus', $v['allow'], 'the order given stays');
        $this->assertSame('dtls', $v['media_encryption']);
        $this->assertSame('yes', $v['ice_support']);
        $this->assertSame('yes', $v['media_encryption_optimistic']);
        $this->assertSame('192.168.1.50', $v['media_address']);
        $this->assertSame('60', $v['rtp_timeout']);
        $this->assertSame('15', $v['rtp_keepalive']);
        $this->assertSame('', ExtensionSipOptions::fromPost(['adv_qualify_frequency' => '99999'])['qualify_frequency']);
        $this->assertSame('', ExtensionSipOptions::fromPost(['adv_language' => "tr\nx=1"])['language']);
        $this->assertSame('', ExtensionSipOptions::fromPost(['adv_media_address' => "1.1.1.1\nx=1"])['media_address']);
        $this->assertSame('', ExtensionSipOptions::fromPost(['adv_rtp_timeout' => '99999'])['rtp_timeout']);
    }

    public function testEndpointLinesComeOnlyFromOwnKeys(): void
    {
        $this->assertSame('', ExtensionSipOptions::endpointLines(['transport' => 'transport-udp', 'direct_media' => 'yes', 'secret' => 'x']), 'old rows do not reach the phone');
        $lines = ExtensionSipOptions::endpointLines([
            'adv_transport' => 'tls',
            'adv_allow' => 'opus,ulaw',
            'adv_direct_media' => 'yes',
            'adv_ice_support' => 'yes',
            'adv_media_address' => '10.0.0.1',
            'adv_rtp_timeout' => '30',
            'adv_transport_evil' => 'x',
        ]);
        $this->assertSame("transport=transport-tls\ndisallow=all\nallow=opus,ulaw\ndirect_media=yes\nice_support=yes\nmedia_address=10.0.0.1\nrtp_timeout=30\n", $lines);
        $this->assertSame('', ExtensionSipOptions::endpointLines(['adv_transport' => 'evil']), 'a stored value is checked again');
        $this->assertSame('30', ExtensionSipOptions::qualifyFrequency(['adv_qualify_frequency' => '30']));
        $this->assertTrue(ExtensionSipOptions::isOwnKey('adv_allow'));
        $this->assertFalse(ExtensionSipOptions::isOwnKey('allow'));
    }

    public function testTheSipTemplateIsNotTiedToAProtocol(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/sync/SyncExtensions.php');
        $at = (int) strpos($source, '[endpoint-sip](!)');
        $this->assertStringNotContainsString('transport=transport-udp', substr($source, $at, 400), 'a phone on TCP or TLS got no calls with this');
    }

    public function testRegionNamesItsPortAndTcpReachesTheFiles(): void
    {
        $this->assertSame('tcp', PhoneRegionService::validate(['transport' => 'tcp'])['transport']);
        $this->assertSame('5098', PhoneRegionService::validate(['sip_port' => '5098'])['sip_port']);
        $this->expectException(\Exception::class);
        PhoneRegionService::validate(['sip_port' => '70000']);
    }

    /** @return array<string, array{0: PhoneTemplate, 1: string, 2: string}> */
    public static function tcpLines(): array
    {
        return [
            'yealink' => [new YealinkTemplate(), 'yealink-t46u', 'account.1.sip_server.1.transport_type = 1'],
            'grandstream' => [new GrandstreamTemplate(), 'grandstream-grp2612', '<P130>1</P130>'],
            'fanvil' => [new FanvilTemplate(), 'fanvil-x4u', 'SIP1 Transport     :1'],
            'snom' => [new SnomTemplate(), 'snom-d865', ';transport=tcp'],
            'cisco spa' => [new CiscoSpaTemplate(), 'cisco-spa303', '>TCP<'],
            'poly' => [new PolyTemplate(), 'poly-vvx450', 'reg.1.server.1.transport="TCPOnly"'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tcpLines')]
    public function testTcpIsWrittenForEachVendor(PhoneTemplate $template, string $model, string $line): void
    {
        $ctx = ['transport' => 'tcp', 'model' => $model, 'mac' => '000413aabbcc', 'extension' => '1000', 'display_name' => 'A', 'sip_password' => 'x',
            'server' => 'pbx.example', 'proxy' => '', 'port' => 5060, 'srtp' => 0, 'codecs' => ['pcma'], 'ntp' => 'n', 'syslog' => '', 'utc_offset' => 0, 'language' => 'en',
            'voicemail' => '*97', 'pickup_prefix' => '*21', 'cisco_load' => '', 'admin_password' => 'p', 'keys' => [], 'extra_settings' => [], 'extra_attributes' => [], 'config_version' => 1];
        $this->assertStringContainsString($line, $template->render($ctx));
    }

    public function testFaxDetectionOnlyWhereARouteAsks(): void
    {
        $this->assertSame('', faxDetectLines(['fax_detect' => 0, 'dest_type' => 'queue'], '100'));
        $this->assertSame('', faxDetectLines(['fax_detect' => 1, 'dest_type' => 'fax'], '100'), 'a fax route has nothing to tell apart');
        $lines = faxDetectLines(['fax_detect' => 1, 'dest_type' => 'queue', 'fax_detect_seconds' => 99, 'fax_target' => "90\n same => n,System(x)"], '${EXTEN}');
        $this->assertStringContainsString('Gosub(sub-fax-detect,s,1(15))', $lines);
        $this->assertStringContainsString('Set(FAX_TARGET=90)', $lines);
        $this->assertStringNotContainsString('System', $lines);
        $this->assertStringContainsString('Set(FAX_TARGET=${EXTEN})', faxDetectLines(['fax_detect' => 1, 'dest_type' => 'ivr', 'fax_detect_seconds' => 5, 'fax_target' => ''], '${EXTEN}'));
    }

    public function testTheFaxJumpLivesInItsOwnContext(): void
    {
        $sub = faxDetectSubroutine();
        $this->assertStringStartsWith('[sub-fax-detect]', $sub);
        $this->assertStringContainsString('exten => fax,1,', $sub);
        $this->assertStringContainsString('Goto(from-trunk-fax,${FAX_TARGET},1)', $sub);
        $this->assertSame(1, substr_count($sub, '['), 'a trunk with fax_detect on must not find a fax extension on other routes');
    }
}
