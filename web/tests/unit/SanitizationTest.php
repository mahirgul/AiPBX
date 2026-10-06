<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every free-text field written to the Asterisk config goes through these functions.
 * Goal: prove that no line break can be injected to open a new directive/section.
 *
 * This is the project's number 1 vulnerability class (config injection) —
 * found and closed twice, separately, in the 2026-08-20 and 2026-08-31
 * audits. These tests guarantee that the lid never opens again.
 */
final class SanitizationTest extends TestCase
{
    public static function satirSonuVaryantlari(): array
    {
        return [
            'LF'          => ["evil\nmax_contacts=99"],
            'CRLF'        => ["evil\r\nmax_contacts=99"],
            'CR'          => ["evil\rmax_contacts=99"],
            'bolum'       => ["evil\n[hacked]\ntype=endpoint"],
            'tab+LF'      => ["evil\t\ncontext=from-internal"],
            'coklu satir' => ["a\nb\nc\nd"],
        ];
    }
    #[DataProvider('satirSonuVaryantlari')]
    public function testToCleanAsciiSatirSonuBirakmaz(string $input): void
    {
        $out = toCleanAscii($input);
        $this->assertStringNotContainsString("\n", $out, 'LF sizdi');
        $this->assertStringNotContainsString("\r", $out, 'CR sizdi');
    }

    public function testToCleanAsciiBolumBasligiAcamaz(): void
    {
        $out = toCleanAscii("evil\n[hacked]\ntype=endpoint");
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*\[/m',
            $out,
            'temizlenmis ciktida satir basinda [bolum] olusabiliyor — config injection!'
        );
    }

    public function testToCleanAsciiMesruDegerleriBozmaz(): void
    {
        $this->assertSame('alaw,ulaw,g729', toCleanAscii('alaw,ulaw,g729'));
        $this->assertSame('from-trunk-inbound', toCleanAscii('from-trunk-inbound'));
        $this->assertSame('198.51.100.80', toCleanAscii('198.51.100.80'));
        $this->assertSame('sip:host:5060', toCleanAscii('sip:host:5060'));
    }

    public function testToCleanAsciiTurkceKarakterleriCevirir(): void
    {
        // The Asterisk config expects ASCII; Turkish titles must stay readable.
        $this->assertSame('Cagri Merkezi', toCleanAscii('Çağrı Merkezi'));
        $this->assertSame('Genel Mudurluk', toCleanAscii('Genel Müdürlük'));
    }

    public function testToCleanAsciiBosDegerleriTasimaz(): void
    {
        $this->assertSame('', toCleanAscii(''));
        $this->assertSame('', toCleanAscii(null));
    }

    public static function gecerliDestTypeleri(): array
    {
        return [['queue'], ['ivr'], ['time_condition'], ['extension'], ['fax'], ['announcement'], ['hangup']];
    }
    #[DataProvider('gecerliDestTypeleri')]
    public function testSanitizeDestTypeBilinenDegerleriKorur(string $type): void
    {
        $this->assertSame($type, sanitizeDestType($type));
    }

    public static function gecersizDestTypeleri(): array
    {
        return [
            'bilinmeyen'  => ['evil'],
            'satir sonu'  => ["queue\nexten => _X.,1,System(rm -rf /)"],
            'bos'         => [''],
            'buyuk harf'  => ['QUEUE'],
            'bosluklu'    => ['queue ; evil'],
        ];
    }
    #[DataProvider('gecersizDestTypeleri')]
    public function testSanitizeDestTypeGecersizDegerleriHangupaDusurur(string $type): void
    {
        $this->assertSame(
            'hangup',
            sanitizeDestType($type),
            'whitelist disi bir dest_type oldugu gibi gecti — dialplan enjeksiyonu mumkun'
        );
    }

    #[DataProvider('satirSonuVaryantlari')]
    public function testNormalizeSipTransportRejectsLineBreaks(string $input): void
    {
        $this->assertSame('transport-udp', normalizeSipTransport('transport-' . $input));
        $this->assertSame('transport-udp', normalizeSipTransport($input));
    }

    public function testNormalizeSipTransportKeepsValidValues(): void
    {
        $this->assertSame('transport-tls', normalizeSipTransport('TLS'));
        $this->assertSame('transport-tcp', normalizeSipTransport(' transport-tcp '));
        $this->assertSame('transport-wss', normalizeSipTransport('wss'));
        $this->assertSame('transport-udp', normalizeSipTransport('sctp'));
        $this->assertSame('transport-udp', normalizeSipTransport(''));
    }

    #[DataProvider('satirSonuVaryantlari')]
    public function testCleanPickupGroupRejectsLineBreaks(string $input): void
    {
        $out = cleanPickupGroup($input);
        $this->assertStringNotContainsString("\n", $out);
        $this->assertStringNotContainsString("\r", $out);
        $this->assertStringNotContainsString('[', $out);
        $this->assertStringNotContainsString('=', $out);
    }

    public function testCleanPickupGroupKeepsGroupLists(): void
    {
        $this->assertSame('sales,support', cleanPickupGroup('sales,support'));
        $this->assertSame('group_1', cleanPickupGroup(' group_1 '));
        $this->assertSame('', cleanPickupGroup(''));
    }
}
