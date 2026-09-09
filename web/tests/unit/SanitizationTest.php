<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Asterisk config'ine yazılan her serbest-metin alan bu fonksiyonlardan geçer.
 * Amaç: satır sonu enjekte edip yeni direktif/bölüm açılamadığını kanıtlamak.
 *
 * Bu, projenin 1 numaralı zafiyet sınıfı (config injection) — 2026-08-20 ve
 * 2026-08-31 denetimlerinde iki kez ayrı ayrı bulunup kapatıldı. Bu testler
 * o kapağın bir daha açılmamasını garanti eder.
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
        // Asterisk config'i ASCII bekliyor; Türkçe başlıklar okunabilir kalmalı.
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
}
