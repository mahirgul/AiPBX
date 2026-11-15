<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/services/SipCaptureService.php';

final class SipCaptureServiceTest extends TestCase
{
    public function testPartyExtractsUserFromSipAndTelUris(): void
    {
        $this->assertSame('1001', SipCaptureService::party('"Kemal" <sip:1001@pbx.local>;tag=abc'));
        $this->assertSame('1002', SipCaptureService::party('<sips:1002@pbx.local:5061>'));
        $this->assertSame('+905321112233', SipCaptureService::party('tel:+905321112233;isub=1'));
        $this->assertSame('', SipCaptureService::party('invalid'));
    }

    public function testSipParsesRequestsResponsesAndShortHeaders(): void
    {
        $invite = "INVITE sip:1002@pbx.local SIP/2.0\r\n"
            . "From: \"Alice\" <sip:1001@pbx.local>;tag=1\r\n"
            . "To: <sip:1002@pbx.local>\r\n"
            . "Call-ID: call-1@pbx\r\n"
            . "CSeq: 1 INVITE\r\n"
            . "User-Agent: Yealink SIP-T54W\r\n\r\n";
        $parsed = SipCaptureService::sip($invite);
        $this->assertNotNull($parsed);
        $this->assertTrue($parsed['request']);
        $this->assertSame('INVITE', $parsed['label']);
        $this->assertSame('INVITE', $parsed['method']);
        $this->assertSame(0, $parsed['status']);
        $this->assertSame('call-1@pbx', $parsed['call_id']);
        $this->assertSame('1001', $parsed['from']);
        $this->assertSame('1002', $parsed['to']);
        $this->assertSame('Yealink SIP-T54W', $parsed['ua']);

        $ok = "SIP/2.0 200 OK\r\n"
            . "f: <sip:1001@pbx.local>;tag=1\r\n"
            . "t: <sip:1002@pbx.local>;tag=2\r\n"
            . "i: call-1@pbx\r\n"
            . "CSeq: 1 INVITE\r\n\r\n";
        $resp = SipCaptureService::sip($ok);
        $this->assertNotNull($resp);
        $this->assertFalse($resp['request']);
        $this->assertSame('200 OK', $resp['label']);
        $this->assertSame('INVITE', $resp['method']);
        $this->assertSame(200, $resp['status']);
        $this->assertSame('call-1@pbx', $resp['call_id']);

        $this->assertNull(SipCaptureService::sip("NOT SIP\r\n\r\n"));
    }

    public function testPacketParsesIpv4AndIpv6UdpSipPackets(): void
    {
        $sip = "OPTIONS sip:1002@pbx.local SIP/2.0\r\nFrom: <sip:1001@pbx>\r\nTo: <sip:1002@pbx>\r\nCall-ID: c-v4\r\nCSeq: 1 OPTIONS\r\n\r\n";
        $pkt4 = $this->buildIpv4UdpPacket('10.0.0.10', 5060, '10.0.0.1', 5060, $sip);
        $msg4 = SipCaptureService::packet($pkt4);
        $this->assertNotNull($msg4);
        $this->assertSame('10.0.0.10:5060', $msg4['src']);
        $this->assertSame('10.0.0.1:5060', $msg4['dst']);
        $this->assertSame('c-v4', $msg4['call_id']);

        $pkt6 = $this->buildIpv6UdpPacket('::ffff:192.0.2.5', 5080, '2001:db8::1', 5060, $sip);
        $msg6 = SipCaptureService::packet($pkt6);
        $this->assertNotNull($msg6);
        $this->assertSame('192.0.2.5:5080', $msg6['src']);
        $this->assertSame('2001:db8::1:5060', $msg6['dst']);

        $this->assertNull(SipCaptureService::packet("\x45\x00"));
        $this->assertNull(SipCaptureService::packet("\x35\x00"));
    }

    public function testReadCallsAndDialogFromPcap(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sipcap');
        $this->assertIsString($tmp);
        try {
            $sip1 = "INVITE sip:1002@pbx SIP/2.0\r\nFrom: <sip:1001@pbx>\r\nTo: <sip:1002@pbx>\r\nCall-ID: dlg-1\r\nCSeq: 1 INVITE\r\n\r\n";
            $sip2 = "SIP/2.0 200 OK\r\nFrom: <sip:1001@pbx>\r\nTo: <sip:1002@pbx>\r\nCall-ID: dlg-1\r\nCSeq: 1 INVITE\r\n\r\n";
            $sip3 = "REGISTER sip:pbx SIP/2.0\r\nFrom: <sip:1003@pbx>\r\nTo: <sip:1003@pbx>\r\nCall-ID: dlg-2\r\nCSeq: 1 REGISTER\r\n\r\n";

            $pkt1 = $this->buildIpv4UdpPacket('10.0.0.10', 5060, '10.0.0.1', 5060, $sip1);
            $pkt2 = $this->buildIpv4UdpPacket('10.0.0.1', 5060, '10.0.0.10', 5060, $sip2);
            $pkt3 = $this->buildIpv4UdpPacket('10.0.0.20', 5062, '10.0.0.1', 5060, $sip3);

            $pcapHeader = pack('VvvVVVV', 0xA1B2C3D4, 2, 4, 0, 0, 65535, 101);
            $rec = static fn(int $sec, string $p) => pack('VVVV', $sec, 500, strlen($p), strlen($p)) . $p;

            file_put_contents($tmp, $pcapHeader . $rec(1700000001, $pkt1) . $rec(1700000002, $pkt2));
            $first = SipCaptureService::read($tmp);
            $this->assertCount(2, $first['messages']);
            $this->assertGreaterThan(24, $first['offset']);

            file_put_contents($tmp, $rec(1700000003, $pkt3), FILE_APPEND);
            $second = SipCaptureService::read($tmp, $first['offset']);
            $this->assertCount(1, $second['messages']);
            $this->assertSame('dlg-2', $second['messages'][0]['call_id']);

            $all = SipCaptureService::read($tmp)['messages'];
            $calls = SipCaptureService::calls($all);
            $this->assertCount(2, $calls);
            $this->assertSame('dlg-2', $calls[0]['call_id']);
            $this->assertSame('dlg-1', $calls[1]['call_id']);
            $this->assertSame(200, $calls[1]['status']);
            $this->assertCount(1, SipCaptureService::calls($all, '1001'));

            $dlg = SipCaptureService::dialog($all, 'dlg-1');
            $this->assertCount(2, $dlg);
            $this->assertSame('INVITE', $dlg[0]['label']);
            $this->assertSame('200 OK', $dlg[1]['label']);
        } finally {
            @unlink($tmp);
        }
    }

    public function testFilesAndPathRejectTraversal(): void
    {
        $dir = sys_get_temp_dir() . '/sipcap_dir_' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        $open = 'sip-20261114-120000.pcap';
        $closed = 'sip-20261114-110000_20261114-115959.pcap';
        try {
            file_put_contents($dir . '/' . $open, 'x');
            // The name is server-local time; the first packet (here 3 hours off the name) decides.
            $first = (new \DateTime('2026-11-14 11:00:00'))->getTimestamp() + 10800;
            file_put_contents($dir . '/' . $closed, pack('VvvlVVV', 0xA1B2C3D4, 2, 4, 0, 0, 65535, 101) . pack('VVVV', $first, 0, 0, 0));
            file_put_contents($dir . '/ignore.pcap', 'z');

            $files = SipCaptureService::files($dir);
            $this->assertCount(2, $files);
            $this->assertSame($open, $files[0]['name']);
            $this->assertTrue($files[0]['open']);
            $this->assertSame($closed, $files[1]['name']);
            $this->assertFalse($files[1]['open']);
            $this->assertSame($first, $files[1]['start']);
            $this->assertSame($first + 3599, $files[1]['end']);

            $this->assertSame($dir . '/' . $open, SipCaptureService::path($open, $dir));

            foreach (['../' . $open, 'other.pcap', 'sip-20261114-999999.pcap'] as $bad) {
                $threw = false;
                try {
                    SipCaptureService::path($bad, $dir);
                } catch (\Exception) {
                    $threw = true;
                }
                $this->assertTrue($threw, "Expected path() to reject $bad");
            }
        } finally {
            @unlink($dir . '/' . $open);
            @unlink($dir . '/' . $closed);
            @unlink($dir . '/ignore.pcap');
            @rmdir($dir);
        }
    }

    private function buildIpv4UdpPacket(string $srcIp, int $srcPort, string $dstIp, int $dstPort, string $payload): string
    {
        $udpLen = 8 + strlen($payload);
        $udp = pack('nnnn', $srcPort, $dstPort, $udpLen, 0) . $payload;
        $ip = pack('CCnnnCCn', 0x45, 0, 20 + $udpLen, 1, 0, 64, 17, 0) . inet_pton($srcIp) . inet_pton($dstIp);
        return $ip . $udp;
    }

    private function buildIpv6UdpPacket(string $srcIp, int $srcPort, string $dstIp, int $dstPort, string $payload): string
    {
        $udpLen = 8 + strlen($payload);
        $udp = pack('nnnn', $srcPort, $dstPort, $udpLen, 0) . $payload;
        $ip = pack('NnCC', 0x60000000, $udpLen, 17, 64) . inet_pton($srcIp) . inet_pton($dstIp);
        return $ip . $udp;
    }
}
