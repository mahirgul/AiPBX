<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/sip_helper.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

/**
 * The contract of the `sip` key-value table.
 *
 * Part of the trunk settings lives in this shadow table and SyncTrunks reads
 * it BEFORE pbx_trunks. So when a field is CLEARED in the panel, the old row
 * here MUST be deleted too — otherwise the old value wins forever and the
 * field can never be cleared again.
 *
 * Real incident (2026-09-01): match_hosts=127.0.0.1 was written to the CCIS
 * gateway trunk. Because of PJSIP's IP-based endpoint matching this assigned
 * ALL SIP traffic from localhost (WebRTC registrations included) to that trunk
 * and WebRTC registration started getting 404. The field was cleared in the
 * panel, but the row stayed in the `sip` table, so the change had NO EFFECT.
 */
final class SipShadowTableTest extends TestCase
{
    private const TRUNK = 'shadowtest';

    protected function setUp(): void
    {
        if (DB_NAME !== 'asterisk_test') {
            $this->fail('tests must run only on asterisk_test');
        }
        getDB()->exec("DELETE FROM sip WHERE id = '" . self::TRUNK . "'");
    }

    /** Minimal array representing a trunk row. */
    private function trunk(array $ustyaz = []): array
    {
        return array_merge([
            'trunk_name'  => self::TRUNK,
            'title'       => 'Shadow Test',
            'ip_address'  => '192.0.2.50',
            'port'        => 5060,
            'transport'   => 'udp',
            'codecs'      => 'alaw,ulaw',
        ], $ustyaz);
    }

    private function sipValue(string $keyword): ?string
    {
        $st = getDB()->prepare('SELECT data FROM sip WHERE id = ? AND keyword = ?');
        $st->execute([self::TRUNK, $keyword]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function testValueCanBeWritten(): void
    {
        SIPHelper::syncTrunkToSIP($this->trunk(['match_hosts' => '10.1.1.1']));
        $this->assertSame('10.1.1.1', $this->sipValue('match_hosts'));
    }

    public function testClearedFieldIsReallyRemoved(): void
    {
        // Fill first
        SIPHelper::syncTrunkToSIP($this->trunk(['match_hosts' => '127.0.0.1']));
        $this->assertSame('127.0.0.1', $this->sipValue('match_hosts'), 'precondition: the value must be written');

        // Then save as if cleared in the panel
        SIPHelper::syncTrunkToSIP($this->trunk(['match_hosts' => '']));

        $this->assertNull(
            $this->sipValue('match_hosts'),
            'a CLEARED field STAYED in the sip table: the old value shadows pbx_trunks '
            . 'and the field can never be cleared again (exactly the bug that broke WebRTC)'
        );
    }

    public static function conditionalFields(): array
    {
        return [
            'match_hosts'        => ['match_hosts', '10.9.9.9'],
            'outbound_proxy'     => ['outbound_proxy', 'sip:proxy.example.net'],
            'from_user'          => ['from_user', '5551234567'],
            'from_domain'        => ['from_domain', 'example.net'],
            'outbound_caller_id' => ['outbound_caller_id', '5551234567'],
            'auth_username'      => ['auth_username', 'kullanici'],
        ];
    }
    #[DataProvider('conditionalFields')]
    public function testEveryConditionalFieldCanBeCleared(string $field, string $value): void
    {
        SIPHelper::syncTrunkToSIP($this->trunk([$field => $value]));
        $this->assertSame($value, $this->sipValue($field), "precondition: {$field} must be written");

        SIPHelper::syncTrunkToSIP($this->trunk([$field => '']));
        $this->assertNull($this->sipValue($field), "{$field} stayed in the sip table after being cleared");
    }

    public function testFilledFieldsAreNotRemoved(): void
    {
        // With filled and empty fields at the same time, only the empty ones must be deleted.
        SIPHelper::syncTrunkToSIP($this->trunk([
            'match_hosts' => '10.2.2.2',
            'from_user'   => 'abc',
        ]));
        SIPHelper::syncTrunkToSIP($this->trunk([
            'match_hosts' => '10.2.2.2',
            'from_user'   => '',
        ]));

        $this->assertSame('10.2.2.2', $this->sipValue('match_hosts'), 'a filled field was removed by mistake');
        $this->assertNull($this->sipValue('from_user'), 'an empty field was not removed');
    }
}
