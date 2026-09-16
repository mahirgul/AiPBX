<?php

use PHPUnit\Framework\TestCase;

require_once '/var/www/html/tests/Fixtures.php';
require_once '/var/www/html/src/helpers.php';
require_once '/var/www/html/src/repositories/QueueRepository.php';
require_once '/var/www/html/src/services/QueueService.php';
require_once '/var/www/html/src/queue_helper.php';
require_once '/var/www/html/src/sync/SyncQueues.php';

final class QueueMembershipTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::load();
        $db = getDB();

        // Seed users for testing queue membership & role separation
        $db->exec("DELETE FROM sys_users WHERE extension IN ('2001', '2002', '2003', '2004', '2005', '2006')");
        $stmt = $db->prepare(
            "INSERT INTO sys_users (username, password_hash, full_name, extension, role, extension_type, is_active)
             VALUES (?, 'hash', ?, ?, ?, ?, ?)"
        );
        $stmt->execute(['agent1', 'Agent One', '2001', 'cc_agent', 'sip', 1]);
        $stmt->execute(['user1', 'User One', '2002', 'user', 'sip', 1]);
        $stmt->execute(['admin1', 'Admin One', '2003', 'admin', 'sip', 1]);
        $stmt->execute(['manager1', 'Manager One', '2004', 'cc_manager', 'sip', 1]);
        $stmt->execute(['fax1', 'Fax One', '2005', 'fax_user', 'fax', 1]);
        $stmt->execute(['inactive_agent', 'Inactive Agent', '2006', 'cc_agent', 'sip', 0]);
    }

    protected function tearDown(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM sys_users WHERE extension IN ('2001', '2002', '2003', '2004', '2005', '2006')");
    }

    public function testQueueAgentsFiltersOutAdminsFaxAndInactive(): void
    {
        $agents = QueueRepository::queueAgents();
        $agentExts = array_column($agents, 'extension');

        $this->assertContains('2001', $agentExts, 'cc_agent must be included in queue agents');
        $this->assertContains('2002', $agentExts, 'user must be included in queue agents');
        $this->assertNotContains('2003', $agentExts, 'admin must NOT be included in queue agents');
        $this->assertNotContains('2004', $agentExts, 'cc_manager must NOT be included in queue agents');
        $this->assertNotContains('2005', $agentExts, 'fax_user must NOT be included in queue agents');
        $this->assertNotContains('2006', $agentExts, 'inactive agent must NOT be included in queue agents');
    }

    public function testQueueManagersFiltersOutAgentsFaxAndInactive(): void
    {
        $managers = QueueRepository::queueManagers();
        $managerExts = array_column($managers, 'extension');

        $this->assertContains('2003', $managerExts, 'admin must be included in queue managers');
        $this->assertContains('2004', $managerExts, 'cc_manager must be included in queue managers');
        $this->assertNotContains('2001', $managerExts, 'cc_agent must NOT be included in queue managers');
        $this->assertNotContains('2002', $managerExts, 'user must NOT be included in queue managers');
        $this->assertNotContains('2005', $managerExts, 'fax_user must NOT be included in queue managers');
        $this->assertNotContains('2006', $managerExts, 'inactive manager must NOT be included in queue managers');
    }

    public function testQueueServiceSavesStaticAndDynamicMembers(): void
    {
        $_SESSION['csrf_token'] = 'test-token';
        $_SESSION['user_id'] = 1;

        $post = [
            'csrf_token' => 'test-token',
            'queue_name' => 'sales_queue',
            'title' => 'Sales Queue',
            'strategy' => 'rrmemory',
            'member_mode' => [
                '2001' => 'static',
                '2002' => 'dynamic',
                '2003' => '', // none
            ],
            'supervisors' => ['2004'],
            'is_active' => 1,
        ];

        $res = QueueService::saveQueue($post);
        $this->assertTrue($res['success']);

        $db = getDB();
        $row = $db->query("SELECT members_json, static_members_json, supervisors_json FROM pbx_queues WHERE queue_name = 'sales_queue'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row);

        $members = json_decode($row['members_json'], true);
        $static = json_decode($row['static_members_json'], true);
        $supervisors = json_decode($row['supervisors_json'], true);

        $this->assertEquals(['2001', '2002'], $members);
        $this->assertEquals(['2001'], $static);
        $this->assertEquals(['2004'], $supervisors);

        // Helper tests
        $this->assertTrue(QueueHelper::isStaticMember('2001', 'sales_queue'));
        $this->assertFalse(QueueHelper::isStaticMember('2002', 'sales_queue'));
        $this->assertFalse(QueueHelper::isStaticMember('2003', 'sales_queue'));

        $this->assertEquals(['sales_queue'], QueueHelper::staticQueuesOf('2001'));
        $this->assertEmpty(QueueHelper::staticQueuesOf('2002'));

        // Static member cannot exit queue
        $this->assertFalse(QueueHelper::setMembership('2001', 'sales_queue', false), 'Static member must not be allowed to leave');
    }

    public function testSyncQueuesGeneratesStaticMemberDirective(): void
    {
        $_SESSION['csrf_token'] = 'test-token';
        $_SESSION['user_id'] = 1;

        $post = [
            'csrf_token' => 'test-token',
            'queue_name' => 'support_queue',
            'title' => 'Support Queue',
            'strategy' => 'ringall',
            'member_mode' => [
                '2001' => 'static',
                '2002' => 'dynamic',
            ],
            'is_active' => 1,
        ];

        QueueService::saveQueue($post);
        syncAllQueues();

        $confPath = ASTERISK_PBX_DIR . '/queues_pbx.conf';
        $this->assertFileExists($confPath);
        $confContent = (string)file_get_contents($confPath);

        $this->assertStringContainsString('[support_queue]', $confContent);
        $this->assertStringContainsString('member => Local/2001@from-internal-pbx/n,0,Temsilci 2001,hint:2001@from-internal-pbx', $confContent);
        // Dynamic member (2002) should NOT be statically written in queues.conf
        $this->assertStringNotContainsString('member => Local/2002@from-internal-pbx/n', $confContent);
    }
}
