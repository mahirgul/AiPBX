<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/services/PhoneLogService.php';

/**
 * PBX → Phone logs, the FTP / TFTP / DHCP tabs: lines of vsftpd's transfer log
 * and dnsmasq's journal turned into rows (time, IP, phone, result, file).
 */
final class PhoneLogEventsTest extends TestCase
{
    public function testFtpLines(): void
    {
        $ok = PhoneLogService::parseFtp('Thu Oct 15 11:31:12 2026 [pid 824877] [NECSDT700] OK DOWNLOAD: Client "10.23.3.70", "/dt-0060b98cb528.cfg", 1265 bytes, 3676.64Kbyte/sec',
            new \DateTimeZone('Europe/Istanbul'));
        $this->assertNotNull($ok);
        $this->assertSame('10.23.3.70', $ok['ip']);
        $this->assertSame('0060b98cb528', $ok['mac']);
        $this->assertSame('OK DOWNLOAD', $ok['result']);
        $this->assertTrue($ok['ok']);
        $this->assertSame('dt-0060b98cb528.cfg', $ok['file']);
        $this->assertStringContainsString('NECSDT700', $ok['detail']);
        // vsftpd writes the server's local time: the row carries its offset.
        $this->assertSame('2026-10-15T11:31:12+03:00', $ok['time']);

        $fail = PhoneLogService::parseFtp('Fri Oct 16 10:03:20 2026 [pid 3498480] [NECSDT700] FAIL LOGIN: Client "10.12.3.90"');
        $this->assertSame('FAIL LOGIN', $fail['result']);
        $this->assertFalse($fail['ok']);
        $this->assertSame('', $fail['mac']);
        $this->assertNull(PhoneLogService::parseFtp('Fri Oct 16 10:03:20 2026 [pid 3498481] CONNECT: Client "127.0.0.1"'));
        $this->assertNull(PhoneLogService::parseFtp('garbage'));
    }

    public function testTftpLines(): void
    {
        $sent = PhoneLogService::parseTftp('2026-10-15T13:39:43+0200 mpc dnsmasq-tftp[3136937]: sent /var/lib/aipbx/tftp/dt-0060b9ef8b1f-phone.cfg to 10.12.3.90');
        $this->assertSame(['10.12.3.90', '0060b9ef8b1f', 'sent', true, 'dt-0060b9ef8b1f-phone.cfg'],
            [$sent['ip'], $sent['mac'], $sent['result'], $sent['ok'], $sent['file']]);
        $this->assertSame('2026-10-15T13:39:43+02:00', $sent['time']);

        $missing = PhoneLogService::parseTftp('2026-10-15T13:40:01+0200 mpc dnsmasq-tftp[1]: file /var/lib/aipbx/tftp/dt-000000000000.cfg not found for 10.12.3.68');
        $this->assertSame(['10.12.3.68', '', 'not found', false], [$missing['ip'], $missing['mac'], $missing['result'], $missing['ok']]);

        $err = PhoneLogService::parseTftp('2026-10-15T13:40:02+0200 mpc dnsmasq-tftp[1]: error 0 Early terminate received from 10.12.3.68');
        $this->assertSame('error', $err['result']);
        $this->assertSame('10.12.3.68', $err['ip']);
        $this->assertNull(PhoneLogService::parseTftp('2026-10-15T13:39:43+0200 mpc dnsmasq-tftp[1]: TFTP root is /var/lib/aipbx/tftp'));
    }

    public function testDhcpLines(): void
    {
        $ack = PhoneLogService::parseDhcp('2026-10-16T09:00:01+0300 faksserver dnsmasq-dhcp[870374]: DHCPACK(ens19) 10.12.3.90 00:60:b9:ef:8b:1f DT710');
        $this->assertSame(['10.12.3.90', '0060b9ef8b1f', 'DHCPACK', true], [$ack['ip'], $ack['mac'], $ack['result'], $ack['ok']]);
        $disc = PhoneLogService::parseDhcp('2026-10-16T09:00:00+0300 faksserver dnsmasq-dhcp[870374]: DHCPDISCOVER(ens19) 00:60:b9:ef:8b:1f');
        $this->assertSame(['', '0060b9ef8b1f', 'DHCPDISCOVER'], [$disc['ip'], $disc['mac'], $disc['result']]);
        $this->assertFalse(PhoneLogService::parseDhcp('2026-10-16T09:00:02+0300 h dnsmasq-dhcp[1]: DHCPNAK(ens19) 10.12.3.91 00:60:b9:ef:8b:1f wrong network')['ok']);
        $this->assertNull(PhoneLogService::parseDhcp('2026-10-16T08:59:00+0300 h dnsmasq-dhcp[1]: DHCP, IP range 10.12.3.100 -- 10.12.3.200, lease time 12h'));
    }

    public function testEventsAreNewestFirstAndFiltered(): void
    {
        $raw = "Thu Oct 15 11:31:12 2026 [pid 1] [NECSDT700] OK DOWNLOAD: Client \"10.12.3.90\", \"/dt-0060b9ef8b1f.cfg\", 10 bytes, 1Kbyte/sec\n"
            . "Thu Oct 15 11:32:12 2026 [pid 2] [NECSDT700] FAIL LOGIN: Client \"10.12.3.68\"\n";
        $rows = PhoneLogService::events('ftp', '', '', $raw);
        $this->assertSame(['10.12.3.68', '10.12.3.90'], array_column($rows, 'ip'));
        $this->assertSame(['10.12.3.90'], array_column(PhoneLogService::events('ftp', '10.12.3.90', '', $raw), 'ip'));
        $this->assertSame(['10.12.3.68'], array_column(PhoneLogService::events('ftp', '', 'fail', $raw), 'ip'));
        $this->assertSame(['10.12.3.90'], array_column(PhoneLogService::events('ftp', '00:60:B9:EF:8B:1F', '', $raw), 'ip'));
    }
}
