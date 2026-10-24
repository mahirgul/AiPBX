<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScheduledTasksTest extends TestCase
{
    private ?int $taskId = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Make sure table exists
        $db = getDB();
        $db->exec('DELETE FROM pbx_scheduled_task_versions');
        $db->exec('DELETE FROM pbx_scheduled_task_runs');
        $db->exec('DELETE FROM pbx_scheduled_tasks');
    }

    protected function tearDown(): void
    {
        if ($this->taskId !== null) {
            ScheduledTaskService::deleteTask($this->taskId);
        }
        parent::tearDown();
    }

    public function testSaveValidation(): void
    {
        // Invalid name and cron
        $res = ScheduledTaskService::saveTask([
            'name' => '',
            'cron_expression' => 'bad cron',
            'script_type' => 'ruby', // unsupported
            'script_content' => '',
        ]);

        $this->assertFalse($res['ok']);
        $this->assertArrayHasKey('name', $res['errors']);
        $this->assertArrayHasKey('cron_expression', $res['errors']);
        $this->assertArrayHasKey('script_type', $res['errors']);
        $this->assertArrayHasKey('script_content', $res['errors']);
    }

    public function testCreateAndEditTask(): void
    {
        $save = ScheduledTaskService::saveTask([
            'name' => 'CDR Export Daily',
            'description' => 'Exports daily CDR to backup directory',
            'is_active' => 1,
            'cron_expression' => '0 3 * * *',
            'script_type' => 'shell',
            'script_content' => 'echo "Exporting CDRs..."',
            'timeout_sec' => 120,
            'memory_limit_mb' => 128,
            'cpu_limit_pct' => 40,
        ], 1);

        $this->assertTrue($save['ok']);
        $this->taskId = (int)$save['id'];
        $this->assertGreaterThan(0, $this->taskId);

        // Check details
        $details = ScheduledTaskService::getTaskDetails($this->taskId);
        $this->assertNotNull($details);
        $this->assertSame('CDR Export Daily', $details['name']);
        $this->assertSame(1, (int)$details['is_active']);
        $this->assertCount(1, $details['versions']);

        // Update task with new content
        $update = ScheduledTaskService::saveTask([
            'id' => $this->taskId,
            'name' => 'CDR Export Daily v2',
            'cron_expression' => '*/15 * * * *',
            'script_type' => 'shell',
            'script_content' => 'echo "Exporting CDRs v2..."',
        ], 1);

        $this->assertTrue($update['ok']);
        $details2 = ScheduledTaskService::getTaskDetails($this->taskId);
        $this->assertSame('CDR Export Daily v2', $details2['name']);
        $this->assertCount(2, $details2['versions']);
    }

    public function testThePortalNeverRunsATaskItself(): void
    {
        // Tests run as an ordinary user, like the portal (www-data): there is no
        // unsandboxed fallback, the run is refused and recorded as failed.
        $save = ScheduledTaskService::saveTask(['name' => 'Refused', 'is_active' => 1, 'cron_expression' => '* * * * *',
            'script_type' => 'shell', 'script_content' => 'touch /tmp/aipbx_task_must_not_run']);
        $this->assertTrue($save['ok']);
        $this->taskId = (int) $save['id'];
        @unlink('/tmp/aipbx_task_must_not_run');

        $result = ScheduledTaskRunner::run($this->taskId, 'manual');
        $this->assertFalse($result['ok']);
        $this->assertSame(t('scheduled_tasks.err_not_root'), $result['error']);
        $this->assertFileDoesNotExist('/tmp/aipbx_task_must_not_run');
        $details = ScheduledTaskService::getTaskDetails($this->taskId);
        $this->assertSame('failed', $details['runs'][0]['status']);
        $this->assertContains('notice:scheduled_task_' . $this->taskId, array_column(NoticeService::forUser(1)['open'], 'key'), 'a failed run is an alert');
    }

    public function testSandboxRunsAsTheTaskUserWithOnlyItsFolderWritable(): void
    {
        $cmd = ScheduledTaskRunner::sandboxCommand(['id' => 7, 'timeout_sec' => 60, 'memory_limit_mb' => 128, 'cpu_limit_pct' => 20, 'network_access' => 0],
            3, '/var/lib/aipbx/tasks/7', '/var/lib/aipbx/tasks/7/.run_3.sh', '/bin/bash');
        foreach (["--uid=aipbx-task", "'ProtectSystem=strict'", "'ReadWritePaths=/var/lib/aipbx/tasks/7'", "'ProtectHome=yes'",
                  "'NoNewPrivileges=yes'", "'MemoryMax=128M'", "'CPUQuota=20%'", "'RuntimeMaxSec=60'", "'PrivateNetwork=yes'"] as $part) {
            $this->assertStringContainsString($part, $cmd);
        }
        $net = ScheduledTaskRunner::sandboxCommand(['id' => 7, 'network_access' => 1], 3, '/d', '/d/s.sh', '/bin/bash');
        $this->assertStringNotContainsString('PrivateNetwork', $net, 'network access asked for');
    }

    public function testRunNowIsOnlyARequest(): void
    {
        $save = ScheduledTaskService::saveTask(['name' => 'Queued', 'cron_expression' => '0 3 * * *', 'script_type' => 'shell', 'script_content' => 'true']);
        $this->taskId = (int) $save['id'];
        $this->assertTrue(ScheduledTaskRepository::requestRun($this->taskId));
        $taken = array_column(ScheduledTaskRepository::takeRunRequests(), 'id');
        $this->assertContains((string) $this->taskId, array_map('strval', $taken));
        $this->assertSame([], ScheduledTaskRepository::takeRunRequests(), 'taken once');
    }
}
