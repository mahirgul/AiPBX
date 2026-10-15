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
    public static function lineBreakVariants(): array
    {
        return [
            'LF'          => ["evil\nmax_contacts=99"],
            'CRLF'        => ["evil\r\nmax_contacts=99"],
            'CR'          => ["evil\rmax_contacts=99"],
            'section'     => ["evil\n[hacked]\ntype=endpoint"],
            'tab+LF'      => ["evil\t\ncontext=from-internal"],
            'many lines'  => ["a\nb\nc\nd"],
        ];
    }
    #[DataProvider('lineBreakVariants')]
    public function testToCleanAsciiLeavesNoLineBreak(string $input): void
    {
        $out = toCleanAscii($input);
        $this->assertStringNotContainsString("\n", $out, 'LF leaked');
        $this->assertStringNotContainsString("\r", $out, 'CR leaked');
    }

    public function testToCleanAsciiCannotOpenASectionHeader(): void
    {
        $out = toCleanAscii("evil\n[hacked]\ntype=endpoint");
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*\[/m',
            $out,
            'the cleaned output can start a line with [section] — config injection!'
        );
    }

    public function testToCleanAsciiKeepsValidValues(): void
    {
        $this->assertSame('alaw,ulaw,g729', toCleanAscii('alaw,ulaw,g729'));
        $this->assertSame('from-trunk-inbound', toCleanAscii('from-trunk-inbound'));
        $this->assertSame('198.51.100.80', toCleanAscii('198.51.100.80'));
        $this->assertSame('sip:host:5060', toCleanAscii('sip:host:5060'));
    }

    public function testToCleanAsciiConvertsTurkishLetters(): void
    {
        // The Asterisk config expects ASCII; Turkish titles must stay readable.
        $this->assertSame('Cagri Merkezi', toCleanAscii('Çağrı Merkezi'));
        $this->assertSame('Genel Mudurluk', toCleanAscii('Genel Müdürlük'));
    }

    public function testToCleanAsciiPassesNoEmptyValues(): void
    {
        $this->assertSame('', toCleanAscii(''));
        $this->assertSame('', toCleanAscii(null));
    }

    public static function validDestTypes(): array
    {
        return [['queue'], ['ivr'], ['time_condition'], ['extension'], ['fax'], ['announcement'], ['hangup']];
    }
    #[DataProvider('validDestTypes')]
    public function testSanitizeDestTypeKeepsKnownValues(string $type): void
    {
        $this->assertSame($type, sanitizeDestType($type));
    }

    public static function invalidDestTypes(): array
    {
        return [
            'unknown'     => ['evil'],
            'line break'  => ["queue\nexten => _X.,1,System(rm -rf /)"],
            'empty'       => [''],
            'buyuk harf'  => ['QUEUE'],
            'with spaces' => ['queue ; evil'],
        ];
    }
    #[DataProvider('invalidDestTypes')]
    public function testSanitizeDestTypeTurnsInvalidValuesIntoHangup(string $type): void
    {
        $this->assertSame(
            'hangup',
            sanitizeDestType($type),
            'a dest_type outside the whitelist passed through — dialplan injection possible'
        );
    }
}
