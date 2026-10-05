<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ConferenceService.php';
require_once __DIR__ . '/../../src/services/RingGroupService.php';
require_once __DIR__ . '/../../src/services/UserService.php';

/**
 * The conference and ring group saves called internalNumberValidate(),
 * which was never defined in the project: saving died with "Call to
 * undefined function". Extension numbers could also collide with queue/
 * conference/group numbers.
 */
final class InternalNumberConflictTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = getDB();
        $_SESSION['csrf_token'] = 'test-token';
        $_SESSION['user_id'] = null;
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $this->db->exec("DELETE FROM pbx_conferences WHERE room_number IN ('7301','7302')");
        $this->db->exec("DELETE FROM pbx_ring_groups WHERE group_number IN ('7301','7303')");
        $this->db->exec("DELETE FROM sys_users WHERE username LIKE 'inttest_%'");
    }

    public function testConferenceCanBeSaved(): void
    {
        $res = ConferenceService::saveConference(['csrf_token' => 'test-token', 'room_number' => '7301', 'title' => 'Test Odası']);
        $this->assertTrue($res['success'], $res['error'] ?? '');
    }

    public function testRingGroupCannotReuseConferenceNumber(): void
    {
        ConferenceService::saveConference(['csrf_token' => 'test-token', 'room_number' => '7301', 'title' => 'Test Odası']);
        $res = RingGroupService::saveRingGroup(['csrf_token' => 'test-token', 'group_number' => '7301', 'name' => 'Test Grubu', 'numbers_list' => '1001']);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('Konferans Odası', $res['error']);

        $ok = RingGroupService::saveRingGroup(['csrf_token' => 'test-token', 'group_number' => '7303', 'name' => 'Test Grubu', 'numbers_list' => '1001']);
        $this->assertTrue($ok['success'], $ok['error'] ?? '');
    }

    public function testEditingKeepsOwnNumber(): void
    {
        ConferenceService::saveConference(['csrf_token' => 'test-token', 'room_number' => '7302', 'title' => 'Oda']);
        $id = (int) $this->db->query("SELECT id FROM pbx_conferences WHERE room_number = '7302'")->fetchColumn();
        $res = ConferenceService::saveConference(['csrf_token' => 'test-token', 'id' => $id, 'room_number' => '7302', 'title' => 'Oda (yeni ad)']);
        $this->assertTrue($res['success'], $res['error'] ?? '');
    }

    public function testUserExtensionCannotReuseConferenceNumber(): void
    {
        ConferenceService::saveConference(['csrf_token' => 'test-token', 'room_number' => '7302', 'title' => 'Oda']);
        $res = UserService::saveUser([
            'csrf_token' => 'test-token', 'username' => 'inttest_a', 'full_name' => 'A',
            'password' => 'T' . bin2hex(random_bytes(8)), 'role' => 'cc_agent', 'extension' => '7302',
        ]);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('Konferans Odası', $res['error']);
    }
}
