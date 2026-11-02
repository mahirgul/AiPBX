<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/src/services/NetworkSettingsService.php';
require_once dirname(__DIR__, 2) . '/src/services/NatDiagnosticsService.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

final class NatDiagnosticsTest extends TestCase
{
    /** @param array<string, mixed> $over */
    private function run_(array $over = []): array
    {
        $in = $over + [
            'local_ips' => ['192.168.1.10'],
            'public' => '203.0.113.10',
            'media' => '',
            'local_nets' => ['192.168.1.0/24'],
            'running_signaling' => $over['public'] ?? '203.0.113.10',
            'running_media' => ($over['media'] ?? '') !== '' ? $over['media'] : ($over['public'] ?? '203.0.113.10'),
            'rtp_start' => 10000,
            'rtp_end' => 12999,
            'firewall_active' => true,
            'firewall_ports' => [['port' => '10000-20000', 'protocol' => 'udp']],
            'observed' => '203.0.113.10',
            'resolve' => static fn(string $h): string => $h === 'pbx.dyndns.example' ? '203.0.113.10' : $h,
            'channels' => [],
        ];
        return array_column(NatDiagnosticsService::evaluate($in), 'status');
    }

    public function testAHealthySetupHasNoProblem(): void
    {
        $status = $this->run_();
        $this->assertNotContains('fail', $status);
        $this->assertNotContains('warn', $status);
    }

    public function testBehindNatWithNoPublicAddress(): void
    {
        $this->assertContains('warn', $this->run_(['public' => '', 'observed' => '']));
        $status = $this->run_(['public' => '', 'observed' => '203.0.113.10']);
        $this->assertContains('warn', $status);
        $this->assertNotContains('fail', $status, 'fine for a PBX used only on its own network');
    }

    public function testAPublicAddressThatIsWrongOrPrivate(): void
    {
        $this->assertContains('fail', $this->run_(['public' => '203.0.113.99']));
        $this->assertContains('fail', $this->run_(['public' => '10.0.0.5', 'observed' => '10.0.0.5']));
    }

    public function testADynamicDnsNameIsResolvedBeforeComparing(): void
    {
        $this->assertNotContains('fail', $this->run_(['public' => 'pbx.dyndns.example']));
    }

    public function testServerOutsideTheLocalNetworks(): void
    {
        $this->assertContains('warn', $this->run_(['local_nets' => ['10.0.0.0/8']]));
    }

    public function testSavedButNotApplied(): void
    {
        $this->assertContains('warn', $this->run_(['running_signaling' => '']));
    }

    public function testFirewallMissingTheAudioPorts(): void
    {
        $this->assertContains('fail', $this->run_(['firewall_ports' => [['port' => '5060', 'protocol' => 'udp']]]));
        $this->assertContains('fail', $this->run_(['firewall_ports' => [['port' => '10000-20000', 'protocol' => 'tcp']]]));
        $this->assertNotContains('fail', $this->run_(['firewall_active' => false, 'firewall_ports' => []]));
    }

    public function testACallReceivingNothing(): void
    {
        $this->assertContains('fail', $this->run_(['channels' => [['channel' => 'PJSIP/1000-00000001', 'up' => 20, 'received' => 0, 'sent' => 900]]]));
        $this->assertNotContains('fail', $this->run_(['channels' => [['channel' => 'PJSIP/1000-00000001', 'up' => 2, 'received' => 0, 'sent' => 90]]]), 'a call just starting');
        $this->assertNotContains('fail', $this->run_(['channels' => [['channel' => 'PJSIP/1000-00000001', 'up' => 20, 'received' => 800, 'sent' => 900]]]));
    }

    public function testChannelStatsOfARealCallAreRead(): void
    {
        $output = "\n                                             ...........Receive......... .........Transmit..........\n"
            . " BridgeId ChannelId ........ UpTime.. Codec.   Count    Lost Pct  Jitter   Count    Lost Pct  Jitter RTT....\n"
            . " ===========================================================================================================\n\n"
            . " 87c3a2a9 meeting-guest-0000 00:00:28 opus     1388       0    0   0.002   1386      18    1   0.001   0.001\n"
            . "          1000-00000011      01:02:03 alaw        0       0    0   0.000    900       0    0   0.000   0.002\n"
            . "\nObjects found: 2\n";
        $this->assertSame([
            ['channel' => 'meeting-guest-0000', 'up' => 28, 'received' => 1388, 'sent' => 1386],
            ['channel' => '1000-00000011', 'up' => 3723, 'received' => 0, 'sent' => 900],
        ], NatDiagnosticsService::parseChannelStats($output));
    }

    public function testStunOutputIsRead(): void
    {
        $this->assertSame('203.0.113.7', NatDiagnosticsService::parseStunOutput("0: : IPv4. UDP reflexive addr: 203.0.113.7:38533\n"));
        $this->assertSame('', NatDiagnosticsService::parseStunOutput('timeout'));
    }

    public function testMediaAddressNeedsThePublicAddressAndNamesAreAccepted(): void
    {
        $base = ['server_ip' => '', 'pjsip_local_net' => '192.168.1.0/24'];
        $ok = NetworkSettingsService::validateServer($base + ['pjsip_external_ip' => 'pbx.dyndns.example', 'pjsip_external_media_ip' => '203.0.113.20']);
        $this->assertSame('pbx.dyndns.example', $ok['pjsip_external_ip']);
        $this->assertSame('203.0.113.20', $ok['pjsip_external_media_ip']);

        $this->expectException(\Exception::class);
        NetworkSettingsService::validateServer($base + ['pjsip_external_ip' => '', 'pjsip_external_media_ip' => '203.0.113.20']);
    }

    public function testAPublicAddressCannotCarryAnythingIntoTheConfiguration(): void
    {
        $this->expectException(\Exception::class);
        NetworkSettingsService::validateServer(['server_ip' => '', 'pjsip_local_net' => '', 'pjsip_external_ip' => "pbx.example.com\nbad=1"]);
    }
}
