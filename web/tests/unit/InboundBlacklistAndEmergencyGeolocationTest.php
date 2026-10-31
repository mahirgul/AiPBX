<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/services/InboundBlacklistService.php';
require_once dirname(__DIR__, 2) . '/src/sync/SyncInboundDialplan.php';
require_once dirname(__DIR__, 2) . '/src/sync/SyncOutboundDialplan.php';
require_once dirname(__DIR__, 2) . '/src/sync/SyncGeneralDialplan.php';

final class InboundBlacklistAndEmergencyGeolocationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        Fixtures::load();
        $this->db = getDB();
        $this->db->exec("DELETE FROM pbx_inbound_blacklist WHERE number LIKE '999%' OR number LIKE '_999%'");
        $this->db->exec("DELETE FROM pbx_outbound_routes WHERE route_name = 'Unit_Emergency_Test'");
    }

    protected function tearDown(): void
    {
        $this->db->exec("DELETE FROM pbx_inbound_blacklist WHERE number LIKE '999%' OR number LIKE '_999%'");
        $this->db->exec("DELETE FROM pbx_outbound_routes WHERE route_name = 'Unit_Emergency_Test'");
    }

    public function testNormalizeNumber(): void
    {
        $this->assertSame('08501234567', InboundBlacklistService::normalizeNumber('  0850 123 45 67  '));
        $this->assertSame('+905551234567', InboundBlacklistService::normalizeNumber('+90 (555) 123-4567'));
        $this->assertSame('_0850.', InboundBlacklistService::normalizeNumber(' _0850. '));
        $this->assertSame('_+1800.', InboundBlacklistService::normalizeNumber('_+1800.'));
    }

    public function testBlacklistCrudAndAstDbSync(): void
    {
        $addRes = InboundBlacklistService::add('9991234567', 'Spam caller test', 'busy', true);
        $this->assertTrue($addRes['success']);
        $this->assertGreaterThan(0, $addRes['id'] ?? 0);
        $id = (int)$addRes['id'];

        $entry = InboundBlacklistService::getById($id);
        $this->assertNotNull($entry);
        $this->assertSame('9991234567', $entry['number']);
        $this->assertSame('busy', $entry['block_action']);
        $this->assertSame(1, (int)$entry['is_active']);

        // Duplicate rejection
        $dupRes = InboundBlacklistService::add('9991234567', 'Duplicate', 'congestion', true);
        $this->assertFalse($dupRes['success']);

        // Update action and title
        $updRes = InboundBlacklistService::update($id, '9991234567', 'Updated spam title', 'announcement', true);
        $this->assertTrue($updRes['success']);
        $updated = InboundBlacklistService::getById($id);
        $this->assertSame('announcement', $updated['block_action']);
        $this->assertSame('Updated spam title', $updated['title']);

        // Toggle active
        $this->assertTrue(InboundBlacklistService::toggleActive($id));
        $toggled = InboundBlacklistService::getById($id);
        $this->assertSame(0, (int)$toggled['is_active']);

        // Delete
        $this->assertTrue(InboundBlacklistService::delete($id));
        $this->assertNull(InboundBlacklistService::getById($id));
    }

    public function testInboundBlacklistSubroutineGeneratesExpectedDialplan(): void
    {
        InboundBlacklistService::add('_9990.', 'Pattern spam', 'drop', true);

        $dialplan = inboundBlacklistSubroutine();
        $this->assertStringContainsString('[sub-inbound-blacklist]', $dialplan);
        $this->assertStringContainsString('DB_EXISTS(blacklist/', $dialplan);
        $this->assertStringContainsString('DB(blacklist_action/', $dialplan);
        $this->assertStringContainsString('[sub-inbound-blacklist-patterns]', $dialplan);
        $this->assertStringContainsString('exten => _9990.,1,Set(BLK_MATCH=1)', $dialplan);
        $this->assertStringContainsString('Set(BLK_ACTION=drop)', $dialplan);
    }

    public function testEmergencyRouteAndGeolocationHeaders(): void
    {
        // Insert an emergency route with Geolocation
        $this->db->prepare(
            "INSERT INTO pbx_outbound_routes 
             (route_name, match_pattern, trunks_json, is_emergency, geolocation_uri, geolocation_routing, route_group, is_active, sort_order) 
             VALUES (?, ?, ?, 1, ?, 'yes', 1, 1, 999)"
        )->execute([
            'Unit_Emergency_Test',
            '112',
            json_encode([['trunk_name' => 'trunk1', 'callerid_override' => '']]),
            'geo:41.0082,28.9784;u=15',
        ]);

        __syncOutboundDialplanBody();
        $confFile = getenv('ASTERISK_PBX_DIR') . '/extensions_outbound.conf';
        $this->assertFileExists($confFile);
        $conf = file_get_contents($confFile);
        $this->assertStringContainsString('exten => 112,1,NoOp(Outbound Call via Route Unit_Emergency_Test', $conf);
        $this->assertStringContainsString('same => n,Set(CDR(accountcode)=EMERGENCY)', $conf);
        $this->assertStringContainsString('same => n,Set(__AIPBX_GEOLOCATION_URI=<geo:41.0082,28.9784;u=15>)', $conf);
        $this->assertStringContainsString('same => n,Set(__AIPBX_GEOLOCATION_ROUTING=yes)', $conf);

        // General dialplan sub-callee-jb check
        __syncGeneralDialplanBody();
        $genConfFile = getenv('ASTERISK_PBX_DIR') . '/extensions_general.conf';
        $this->assertFileExists($genConfFile);
        $genConf = file_get_contents($genConfFile);
        $this->assertStringContainsString('[sub-callee-jb]', $genConf);
        $this->assertStringContainsString('Set(PJSIP_HEADER(add,Geolocation)=${AIPBX_GEOLOCATION_URI})', $genConf);
        $this->assertStringContainsString('Set(PJSIP_HEADER(add,Geolocation-Routing)=${AIPBX_GEOLOCATION_ROUTING})', $genConf);
    }

    public function testInvalidBlacklistInputs(): void
    {
        // Empty number
        $res = InboundBlacklistService::add('   ', 'Empty');
        $this->assertFalse($res['success']);

        // Only pattern prefix without pattern
        $res2 = InboundBlacklistService::add('_', 'Just underscore');
        $this->assertFalse($res2['success']);

        // Invalid action falls back to 'busy'
        $res3 = InboundBlacklistService::add('9994445566', 'Fallback action', 'nonexistent_action');
        $this->assertTrue($res3['success']);
        $entry = InboundBlacklistService::getById((int)$res3['id']);
        $this->assertNotNull($entry);
        $this->assertSame('busy', $entry['block_action']);

        // Update non-existent record
        $res4 = InboundBlacklistService::update(99999999, '9991112233', 'Not found');
        $this->assertFalse($res4['success']);
    }

    public function testRebuildAstDb(): void
    {
        InboundBlacklistService::add('9993332211', 'AstDB rebuild test', 'congestion', true);
        InboundBlacklistService::rebuildAstDb();
        $entry = InboundBlacklistRepository::findByNumber('9993332211');
        $this->assertNotNull($entry);
        $this->assertSame('congestion', $entry['block_action']);
    }

    public function testLastCallerForExtension(): void
    {
        // Insert CDR row
        $this->db->prepare(
            "INSERT INTO asteriskcdr (calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata, duration, billsec, disposition, amaflags, uniqueid) 
             VALUES (NOW(), 'Spam Tester', '9997776655', '1099', 'from-trunk', 'PJSIP/trunk-001', 'PJSIP/1099-001', 'Dial', 'PJSIP/1099', 15, 10, 'ANSWERED', 3, 'test.123.456')"
        )->execute();

        $last = InboundBlacklistService::getLastCallerForExtension('1099');
        $this->assertSame('9997776655', $last);

        // Non-existent extension returns null
        $this->assertNull(InboundBlacklistService::getLastCallerForExtension('9999'));
    }

    public function testEmergencyDefaultGeolocationFallback(): void
    {
        $this->db->prepare(
            "INSERT INTO sys_settings (setting_key, setting_value) VALUES ('emergency_default_geolocation_uri', 'geo:39.9334,32.8597') 
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute();

        // Route without explicit geolocation URI
        $this->db->prepare(
            "INSERT INTO pbx_outbound_routes 
             (route_name, match_pattern, trunks_json, is_emergency, geolocation_uri, geolocation_routing, route_group, is_active, sort_order) 
             VALUES ('Unit_Emergency_Test', '911', ?, 1, NULL, 'yes', 1, 1, 999)"
        )->execute([
            json_encode([['trunk_name' => 'trunk1', 'callerid_override' => '']]),
        ]);

        __syncOutboundDialplanBody();
        $confFile = getenv('ASTERISK_PBX_DIR') . '/extensions_outbound.conf';
        $this->assertFileExists($confFile);
        $conf = file_get_contents($confFile);
        $this->assertStringContainsString('exten => 911,1,NoOp(Outbound Call via Route Unit_Emergency_Test', $conf);
        $this->assertStringContainsString('same => n,Set(__AIPBX_GEOLOCATION_URI=<geo:39.9334,32.8597>)', $conf);
    }
}
