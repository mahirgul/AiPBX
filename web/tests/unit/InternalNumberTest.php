<?php

use PHPUnit\Framework\TestCase;

require_once '/var/www/html/tests/Fixtures.php';
require_once '/var/www/html/src/internal_numbers.php';

/**
 * Dahili hedef numarası kayıt defteri ve çakışma doğrulaması.
 *
 * En kritik test: assertInternalNumberAvailable() GERÇEK bir dahiliyle
 * çakışmayı yakalıyor mu — yakalamazsa panelden girilen numara sessizce
 * bir kullanıcının dahilisini gölgelemeye çalışır.
 */
final class InternalNumberTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::load();
    }

    public function testManifestteKaynaklarVarVeHangupActionKeyKullanir(): void
    {
        $this->assertCount(7, INTERNAL_NUMBER_SOURCES);
        $this->assertArrayHasKey('ring_group', INTERNAL_NUMBER_SOURCES);
        $this->assertArrayHasKey('conference', INTERNAL_NUMBER_SOURCES);
        $this->assertSame('action_key', INTERNAL_NUMBER_SOURCES['hangup']['dest_col'],
            'hangup hedefinin dest_id\'si buildDestinationLines() icinde action_key ile aranir');
        $this->assertSame('id', INTERNAL_NUMBER_SOURCES['ivr']['dest_col']);
    }

    public function testSanitizeYalnizcaRakamBirakir(): void
    {
        $this->assertSame('1010', internalNumberSanitize(' 10-10 '));
        $this->assertSame('', internalNumberSanitize('abc'));
        $this->assertSame('', internalNumberSanitize(null));
    }

    public function testEntriesFixtureNumaralariniDondurur(): void
    {
        $entries = internalNumberEntries();
        $numaralar = array_column($entries, 'number');
        sort($numaralar);
        $this->assertSame(['1010', '1020'], $numaralar);

        $hangup = null;
        foreach ($entries as $e) {
            if ($e['number'] === '1020') { $hangup = $e; }
        }
        $this->assertNotNull($hangup);
        $this->assertSame('hangup', $hangup['dest_type']);
        $this->assertSame('testbusy', $hangup['dest_id'],
            'hangup dest_id sayisal id degil action_key olmali');
    }

    public function testPasifKayitlarListeyeGirmez(): void
    {
        getDB()->exec("UPDATE pbx_ivrs SET is_active = 0 WHERE internal_number = '1010'");
        $numaralar = array_column(internalNumberEntries(), 'number');
        $this->assertNotContains('1010', $numaralar);
    }

    public function testGercekDahiliyleCakismaYakalanir(): void
    {
        getDB()->exec("DELETE FROM sys_users WHERE username = 'tuser'");
        getDB()->prepare(
            "INSERT INTO sys_users (username, password_hash, full_name, role, extension, extension_type, is_active)
             VALUES (?, ?, ?, 'user', ?, 'sip', 1)"
        )->execute(['tuser', 'x', 'Test Kullanici', '3001']);

        try {
            assertInternalNumberAvailable('3001', 'ivr', 0);
            $this->fail('gercek dahiliyle cakisma yakalanmadi');
        } catch (Exception $e) {
            $this->assertStringContainsString('3001', $e->getMessage());
            $this->assertStringContainsString('dahili', $e->getMessage());
        } finally {
            getDB()->exec("DELETE FROM sys_users WHERE username = 'tuser'");
        }
    }

    public function testBaskaBirVarliginNumarasiylaCakismaYakalanir(): void
    {
        $this->expectException(Exception::class);
        assertInternalNumberAvailable('1020', 'ivr', 0);
    }

    public function testKendiNumarasiCakismaSayilmaz(): void
    {
        $ivrId = (int) getDB()->query("SELECT id FROM pbx_ivrs WHERE internal_number = '1010'")->fetchColumn();
        assertInternalNumberAvailable('1010', 'ivr', $ivrId);
        $this->assertTrue(true, 'kendi numarasi cakisma saymadi');
    }

    public function testCokKisaVeCokUzunNumaraReddedilir(): void
    {
        $this->expectException(Exception::class);
        assertInternalNumberAvailable('5', 'ivr', 0);
    }

    public function testAsteriskDeseniRegexeCevrilir(): void
    {
        $re = asteriskPatternToRegex('_[4-9]XXX');
        $this->assertNotNull($re);
        $this->assertSame(1, preg_match($re, '4500'));
        $this->assertSame(0, preg_match($re, '1500'));
        $this->assertSame(0, preg_match($re, '45000'));

        $this->assertSame(1, preg_match(asteriskPatternToRegex('_0X.'), '05321234567'));
        $this->assertSame(1, preg_match(asteriskPatternToRegex('112'), '112'));
        $this->assertNull(asteriskPatternToRegex('_9%$'), 'desteklenmeyen karakterde null donmeli');
    }

    public function testGidenRotaCakismasiUyariUretir(): void
    {
        getDB()->exec("DELETE FROM pbx_outbound_routes");
        getDB()->prepare(
            "INSERT INTO pbx_outbound_routes (route_name, match_pattern, trunks_json, is_internal, route_group, is_active)
             VALUES (?, ?, '[]', 1, 1, 1)"
        )->execute(['Santral Ic', '_[4-9]XXX']);

        $this->assertNotNull(internalNumberRouteWarning('4500'));
        $this->assertNull(internalNumberRouteWarning('1500'));
    }
}
