<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';
require_once dirname(__DIR__, 2) . '/src/services/ExtensionService.php';
require_once dirname(__DIR__, 2) . '/src/services/RingGroupService.php';

/**
 * Ring-group member classification and delete guard.
 *
 * A registered extension is internal whatever its length: the old
 * length-only heuristic sent 6-digit and 0-leading extensions out through
 * the outbound routes. And a group cannot be deleted while IVRs, time
 * conditions, announcements or fallbacks still point at it — the old
 * DID-only check orphaned a live Goto(app-ringgroup-N).
 */
final class RingGroupRoutingTest extends TestCase
{
    private const EXT6 = '742011';

    private PDO $db;

    protected function setUp(): void
    {
        Fixtures::load();
        $this->db = getDB();
        $_SESSION['csrf_token'] = 'test-token';
        $_SESSION['user_id'] = null;
        $this->db->exec("DELETE FROM sys_users WHERE extension = '" . self::EXT6 . "'");
        $this->db->exec("DELETE FROM sip WHERE id = '" . self::EXT6 . "'");
        $this->db->exec("DELETE FROM pbx_ring_groups WHERE group_number IN ('7491','7492')");
        $this->db->exec("DELETE FROM pbx_ivr_entries WHERE dest_type = 'ring_group'");
    }

    protected function tearDown(): void
    {
        $this->db->exec("DELETE FROM pbx_ivr_entries WHERE dest_type = 'ring_group'");
        $this->db->exec("DELETE FROM pbx_ring_groups WHERE group_number IN ('7491','7492')");
        $this->db->exec("DELETE FROM sys_users WHERE extension = '" . self::EXT6 . "'");
        $this->db->exec("DELETE FROM sip WHERE id = '" . self::EXT6 . "'");
    }

    private function read(): string
    {
        syncRingGroups();
        $path = ASTERISK_PBX_DIR . '/extensions_ringgroups.conf';
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    private function addGroup(string $number, string $members, string $strategy = 'ringall'): int
    {
        $this->db->prepare(
            'INSERT INTO pbx_ring_groups (group_number, internal_number, name, numbers_list, ring_strategy, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$number, $number, 'Routing Test ' . $number, $members, $strategy]);
        return (int) $this->db->lastInsertId();
    }

    public function testRegisteredLongExtensionStaysInternal(): void
    {
        $res = ExtensionService::saveExtension([
            'csrf_token' => 'test-token',
            'extension' => self::EXT6,
            'full_name' => 'Six Digit Member',
            'sip_password' => 'M' . bin2hex(random_bytes(8)),
            'extension_type' => 'sip',
            'is_active' => '1',
        ]);
        $this->assertTrue($res['success'], $res['error'] ?? '');

        $this->addGroup('7491', self::EXT6 . ', 3001, 00905551234567');
        $conf = $this->read();

        $this->assertStringContainsString(
            'Local/' . self::EXT6 . '@from-internal-pbx-ortak',
            $conf,
            'a registered 6-digit extension must not be sent to the outbound routes'
        );
        $this->assertStringContainsString(
            'Local/00905551234567@from-internal-outbound-1',
            $conf,
            'a real external number must still go outbound'
        );
    }

    public function testCallerGroupPropagatesToMemberLegs(): void
    {
        $this->addGroup('7491', '3001, 3002');
        $conf = $this->read();

        $this->assertStringContainsString(
            'Set(__PERMISSION_GROUP_ID=${PERMISSION_GROUP_ID})',
            $conf,
            'external member legs must inherit the caller group for the permission check'
        );
    }

    public function testRandomPicksOneTargetPerCall(): void
    {
        // "Pick a target at random": one member per call, chosen in the
        // dialplan — never everybody at once, never a frozen sync-time order.
        $this->addGroup('7491', '3001, 3002, 3003', 'random');
        $conf = $this->read();

        $start = strpos($conf, '[app-ringgroup-');
        $this->assertNotFalse($start);
        $ctx = substr($conf, $start);
        $this->assertStringContainsString('Set(RG_PICK_', $ctx);
        $this->assertStringContainsString('${RAND(1,3)}', $ctx);
        $this->assertStringContainsString('(rg_rand_', $ctx);
        $this->assertDoesNotMatchRegularExpression(
            '/Dial\(Local\/3001.*&.*Local\/3002/',
            $ctx,
            'random must not ring everybody at once like ringall'
        );
    }

    public function testRingallStillRingsEverybodyAtOnce(): void
    {
        $this->addGroup('7491', '3001, 3002', 'ringall');
        $conf = $this->read();

        $start = strpos($conf, '[app-ringgroup-');
        $this->assertNotFalse($start);
        $ctx = substr($conf, $start);
        $this->assertMatchesRegularExpression('/Dial\(Local\/3001[^)]*&[^)]*Local\/3002/', $ctx);
        $this->assertStringNotContainsString('RG_PICK_', $ctx);
    }

    public function testDeleteBlockedByIvrReference(): void
    {
        $id = $this->addGroup('7492', '3001');
        $ivrId = (int) $this->db->query("SELECT id FROM pbx_ivrs WHERE title = 'Test IVR'")->fetchColumn();
        $this->assertGreaterThan(0, $ivrId, 'precondition: fixture IVR missing');
        $this->db->prepare(
            "INSERT INTO pbx_ivr_entries (ivr_id, digit, dest_type, dest_id) VALUES (?, '5', 'ring_group', ?)"
        )->execute([$ivrId, (string) $id]);

        // handleAction() converts the throw into an error result (the real
        // contract, as the panel sees it): the group must survive.
        $res = RingGroupService::deleteRingGroup($id, 'test-token');
        $this->assertFalse($res['success'], 'a referenced group must not be deleted');
        $this->assertStringContainsString('estination', $res['error'] ?? '');
        $this->assertNotFalse(
            $this->db->query("SELECT 1 FROM pbx_ring_groups WHERE id = {$id}")->fetchColumn(),
            'the blocked delete must leave the group in place'
        );
    }

    public function testDeleteSucceedsWhenUnreferenced(): void
    {
        $id = $this->addGroup('7492', '3001');
        $res = RingGroupService::deleteRingGroup($id, 'test-token');
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $this->assertFalse($this->db->query("SELECT 1 FROM pbx_ring_groups WHERE id = {$id}")->fetchColumn());
    }
}
