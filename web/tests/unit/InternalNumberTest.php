<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/internal_numbers.php';

/**
 * Internal destination number registry and conflict validation.
 *
 * The most critical test: does assertInternalNumberAvailable() catch a
 * collision with a REAL extension — otherwise a number entered in the panel
 * silently tries to shadow a user's extension.
 */
final class InternalNumberTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::load();
    }

    public function testManifestHasSourcesAndHangupUsesActionKey(): void
    {
        $this->assertCount(8, INTERNAL_NUMBER_SOURCES);
        $this->assertArrayHasKey('ai_app', INTERNAL_NUMBER_SOURCES);
        $this->assertArrayHasKey('ring_group', INTERNAL_NUMBER_SOURCES);
        $this->assertArrayHasKey('conference', INTERNAL_NUMBER_SOURCES);
        $this->assertSame('action_key', INTERNAL_NUMBER_SOURCES['hangup']['dest_col'],
            'buildDestinationLines() looks up the dest_id of a hangup destination by action_key');
        $this->assertSame('id', INTERNAL_NUMBER_SOURCES['ivr']['dest_col']);
    }

    public function testSanitizeKeepsDigitsOnly(): void
    {
        $this->assertSame('1010', internalNumberSanitize(' 10-10 '));
        $this->assertSame('', internalNumberSanitize('abc'));
        $this->assertSame('', internalNumberSanitize(null));
    }

    public function testEntriesReturnTheFixtureNumbers(): void
    {
        $entries = internalNumberEntries();
        $numbers = array_column($entries, 'number');
        sort($numbers);
        $this->assertSame(['1010', '1020'], $numbers);

        $hangup = null;
        foreach ($entries as $e) {
            if ($e['number'] === '1020') { $hangup = $e; }
        }
        $this->assertNotNull($hangup);
        $this->assertSame('hangup', $hangup['dest_type']);
        $this->assertSame('testbusy', $hangup['dest_id'],
            'hangup dest_id must be the action_key, not a numeric id');
    }

    public function testInactiveRecordsAreNotListed(): void
    {
        getDB()->exec("UPDATE pbx_ivrs SET is_active = 0 WHERE internal_number = '1010'");
        $numbers = array_column(internalNumberEntries(), 'number');
        $this->assertNotContains('1010', $numbers);
    }

    public function testClashWithARealExtensionIsCaught(): void
    {
        getDB()->exec("DELETE FROM sys_users WHERE username = 'tuser'");
        getDB()->prepare(
            "INSERT INTO sys_users (username, password_hash, full_name, role, extension, extension_type, is_active)
             VALUES (?, ?, ?, 'user', ?, 'sip', 1)"
        )->execute(['tuser', 'x', 'Test Kullanici', '3001']);

        try {
            assertInternalNumberAvailable('3001', 'ivr', 0);
            $this->fail('conflict with a real extension was not detected');
        } catch (Exception $e) {
            $this->assertStringContainsString('3001', $e->getMessage());
            $this->assertStringContainsString('extension', $e->getMessage());
        } finally {
            getDB()->exec("DELETE FROM sys_users WHERE username = 'tuser'");
        }
    }

    public function testClashWithAnotherItemsNumberIsCaught(): void
    {
        $this->expectException(Exception::class);
        assertInternalNumberAvailable('1020', 'ivr', 0);
    }

    public function testOwnNumberIsNotAClash(): void
    {
        $ivrId = (int) getDB()->query("SELECT id FROM pbx_ivrs WHERE internal_number = '1010'")->fetchColumn();
        assertInternalNumberAvailable('1010', 'ivr', $ivrId);
        $this->assertTrue(true, 'its own number was not counted as a conflict');
    }

    public function testTooShortAndTooLongNumbersAreRejected(): void
    {
        $this->expectException(Exception::class);
        assertInternalNumberAvailable('5', 'ivr', 0);
    }

    public function testAsteriskPatternBecomesRegex(): void
    {
        $re = asteriskPatternToRegex('_[4-9]XXX');
        $this->assertNotNull($re);
        $this->assertSame(1, preg_match($re, '4500'));
        $this->assertSame(0, preg_match($re, '1500'));
        $this->assertSame(0, preg_match($re, '45000'));

        $this->assertSame(1, preg_match(asteriskPatternToRegex('_0X.'), '05321234567'));
        $this->assertSame(1, preg_match(asteriskPatternToRegex('112'), '112'));
        $this->assertNull(asteriskPatternToRegex('_9%$'), 'an unsupported character must return null');
    }

    public function testOutboundRouteClashGivesAWarning(): void
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
