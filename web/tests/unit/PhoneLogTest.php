<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/PhoneLogService.php';

/** PBX → Phone logs: reading, following and filtering the syslog file. */
final class PhoneLogTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'phonelog');
        putenv('AIPBX_PHONE_LOG=' . $this->file);
        getDB()->exec("DELETE FROM pbx_phones WHERE mac = '0060b9ef8b1f'");
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        putenv('AIPBX_PHONE_LOG');
        getDB()->exec("DELETE FROM pbx_phones WHERE mac = '0060b9ef8b1f'");
    }

    private function append(string ...$lines): void
    {
        file_put_contents($this->file, implode('', array_map(fn($l) => $l . "\n", $lines)), FILE_APPEND);
    }

    public function testReadsAndFollows(): void
    {
        $this->assertFalse(PhoneLogService::read('', '', null)['exists'] && filesize($this->file) > 0);
        $this->append(
            '2026-10-15T11:10:01+03:00 10.12.3.90 err [MAC:0060B9EF8B1F] [tXML] (CFG) ERROR: ConfigurationComplete, configuration is invalid',
            '2026-10-15T11:10:02+03:00 10.12.3.68 info [00:00:46] NECS SIP, Version: 2.3.82.21'
        );
        $r = PhoneLogService::read('', '', null);
        $this->assertCount(2, $r['lines']);
        $this->assertSame(['10.12.3.90', 'err', '0060b9ef8b1f'], [$r['lines'][0]['ip'], $r['lines'][0]['severity'], $r['lines'][0]['mac']]);
        $this->assertSame(filesize($this->file), $r['cursor']['pos']);

        // A line still being written is left for the next call.
        file_put_contents($this->file, '2026-10-15T11:10:03+03:00 10.12.3.90 info half', FILE_APPEND);
        $r2 = PhoneLogService::read('', '', $r['cursor']);
        $this->assertSame([], $r2['lines']);
        file_put_contents($this->file, " done\n", FILE_APPEND);
        $r3 = PhoneLogService::read('', '', $r2['cursor']);
        $this->assertSame(['half done'], array_column($r3['lines'], 'message'));

        // Rotated (other inode): read the new file from its start.
        unlink($this->file);
        $this->append('2026-10-15T11:11:00+03:00 10.12.3.68 info after rotation');
        $r4 = PhoneLogService::read('', '', ['ino' => $r3['cursor']['ino'] + 1, 'pos' => $r3['cursor']['pos']]);
        $this->assertSame(['after rotation'], array_column($r4['lines'], 'message'));
    }

    public function testFilterByIpMacAndText(): void
    {
        $this->append(
            '2026-10-15T11:10:01+03:00 10.12.3.90 err [MAC:0060B9EF8B1F] (CFG) ERROR: invalid',
            '2026-10-15T11:10:02+03:00 10.12.3.90 info no mac in this line',
            '2026-10-15T11:10:03+03:00 10.12.3.68 info other phone',
            '2026-10-15T11:10:04+03:00 10.12.3.134 info yealink registered'
        );
        $msgs = fn(string $who, string $text = '') => array_column(PhoneLogService::read($who, $text, null)['lines'], 'message');
        $this->assertSame(['other phone'], $msgs('10.12.3.68'));
        // The MAC stands for the address it was seen at in the log, too.
        $this->assertSame(['[MAC:0060B9EF8B1F] (CFG) ERROR: invalid', 'no mac in this line'], $msgs('00:60:B9:EF:8B:1F'));
        $this->assertSame(['no mac in this line'], $msgs('0060b9ef8b1f', 'NO MAC'));
        // ... and the address on the Phones page.
        getDB()->exec("INSERT INTO pbx_phones (mac, model, token, last_ip) VALUES ('0060b9ef8b1f', 'nec-dt710', REPEAT('a', 40), '10.12.3.134')");
        $this->assertContains('yealink registered', $msgs('0060-b9ef-8b1f'));
        // Part of an address.
        $this->assertCount(4, PhoneLogService::read('10.12.3.', '', null)['lines']);
    }

    public function testMacInText(): void
    {
        $this->assertSame('0060b9ef8b1f', PhoneLogService::macInText('NECS SIP, Version: 2.3.82.21, MAC: 00:60:B9:EF:8B:1F'));
        $this->assertSame('', PhoneLogService::macInText('no address here'));
    }
}
