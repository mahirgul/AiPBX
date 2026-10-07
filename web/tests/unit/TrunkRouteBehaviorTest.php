<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/helpers.php';

/**
 * Behaviour of trunk / route / transport settings through the same service calls
 * the portal forms make.
 */
final class TrunkRouteBehaviorTest extends TestCase
{
    private const CSRF = 'trunk-route-test';

    protected function setUp(): void
    {
        Fixtures::load();
        $_SESSION['csrf_token'] = self::CSRF;
        $_SESSION['user_id'] = null;
    }

    private function trunkForm(array $extra = []): array
    {
        return $extra + [
            'csrf_token' => self::CSRF, 'title' => 'Test Trunk', 'ip_address' => '192.0.2.10',
            'port' => '5060', 'transport' => 'udp', 'codecs' => 'alaw,ulaw',
            'qualify_frequency' => '60', 'connection_mode' => 'ip',
        ];
    }

    private function fixtureTrunkId(): int
    {
        return (int)getDB()->query("SELECT id FROM pbx_trunks WHERE trunk_name = '" . Fixtures::TRUNK_NAME . "'")->fetchColumn();
    }

    private function routeForm(string $name, string $pattern, int $group): array
    {
        return [
            'csrf_token' => self::CSRF, 'route_name' => $name, 'match_pattern' => $pattern,
            'route_group' => (string)$group, 'trunk_name' => [Fixtures::TRUNK_NAME], 'trunk_cid' => [''],
        ];
    }

    public function testRenamingATrunkUpdatesTheRoutesUsingIt(): void
    {
        $db = getDB();
        $db->prepare("INSERT INTO pbx_outbound_routes (route_name, match_pattern, is_active, route_group, trunks_json) VALUES ('R', '_9XXX', 1, 1, ?)")
           ->execute([json_encode([['trunk_name' => Fixtures::TRUNK_NAME, 'callerid_override' => '']])]);

        $res = TrunkService::saveTrunk($this->trunkForm(['trunk_id' => $this->fixtureTrunkId(), 'trunk_name' => 'renamedtrunk']));
        $this->assertTrue($res['success'] ?? false, $res['error'] ?? '');

        $json = json_decode($db->query("SELECT trunks_json FROM pbx_outbound_routes WHERE route_name = 'R'")->fetchColumn(), true);
        $this->assertSame('renamedtrunk', $json[0]['trunk_name']);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM pbx_trunks WHERE trunk_name = '" . Fixtures::TRUNK_NAME . "'")->fetchColumn());
    }

    public function testANewTrunkCannotReuseAnExistingName(): void
    {
        $res = TrunkService::saveTrunk($this->trunkForm(['trunk_name' => Fixtures::TRUNK_NAME]));
        $this->assertFalse($res['success'] ?? true);
        $this->assertStringContainsString('already exists', $res['error'] ?? '');
    }

    public function testDuplicatePatternIsRejectedOnlyWithinTheSameGroup(): void
    {
        $first = RouteService::saveOutboundRoute($this->routeForm('A', '_7XXX', 1));
        $this->assertTrue($first['success'] ?? false, $first['error'] ?? '');

        $dup = RouteService::saveOutboundRoute($this->routeForm('B', '_7XXX', 1));
        $this->assertFalse($dup['success'] ?? true, 'same pattern in the same group must be rejected');

        $otherGroup = RouteService::saveOutboundRoute($this->routeForm('C', '_7XXX', 2));
        $this->assertTrue($otherGroup['success'] ?? false, $otherGroup['error'] ?? '');
    }

    public function testOutboundCallerIdNormalizationIsWritten(): void
    {
        $db = getDB();
        $db->exec("UPDATE pbx_trunks SET cid_keep_last = 4, cid_prepend = '90370418' WHERE trunk_name = '" . Fixtures::TRUNK_NAME . "'");
        $db->prepare("INSERT INTO pbx_outbound_routes (route_name, match_pattern, is_active, route_group, trunks_json) VALUES ('N', '_0X.', 1, 1, ?)")
           ->execute([json_encode([['trunk_name' => Fixtures::TRUNK_NAME]])]);

        syncOutboundDialplan();
        $conf = (string)file_get_contents(ASTERISK_PBX_DIR . '/extensions_outbound.conf');
        $this->assertStringContainsString('Set(AIPBX_SRC_CID=${CALLERID(num)})', $conf);
        $this->assertStringContainsString('Set(CALLERID(num)=90370418${CALLERID(num):-4})', $conf);
    }

    public function testRemovingALocalNetworkRemovesItFromTheTransports(): void
    {
        $save = fn(string $nets) => AsteriskSettingsService::saveSettings(['csrf_token' => self::CSRF, 'pjsip_local_net' => $nets]);

        $this->assertTrue($save('10.23.3.0/24,10.8.0.0/24')['success'] ?? false);
        $this->assertTrue($save('10.0.0.0/8')['success'] ?? false);

        $keys = getDB()->query("SELECT keyword, data FROM pjsipsettings WHERE keyword LIKE 'localnet\\_%' OR keyword LIKE 'netmask\\_%'")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(['localnet_0' => '10.0.0.0', 'netmask_0' => '255.0.0.0'], array_intersect_key($keys, ['localnet_0' => 1, 'netmask_0' => 1]));
        $this->assertArrayNotHasKey('localnet_1', $keys, 'a removed network stayed in the settings');
    }
}
