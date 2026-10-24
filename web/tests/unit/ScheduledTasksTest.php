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

    public function testRunShellTask(): void
    {
        $save = ScheduledTaskService::saveTask([
            'name' => 'Test Hello Script',
            'is_active' => 1,
            'cron_expression' => '* * * * *',
            'script_type' => 'shell',
            'script_content' => 'echo "Hello from Scheduled Task"',
        ]);

        $this->assertTrue($save['ok']);
        $this->taskId = (int)$save['id'];

        $result = ScheduledTaskRunner::run($this->taskId, 'manual');
        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertStringContainsString('Hello from Scheduled Task', $result['output']);

        // Check run history
        $details = ScheduledTaskService::getTaskDetails($this->taskId);
        $this->assertCount(1, $details['runs']);
        $this->assertSame('success', $details['runs'][0]['status']);
        $this->assertSame('manual', $details['runs'][0]['triggered_by']);
    }

    public function testRunFailingTaskRaisesNotice(): void
    {
        $save = ScheduledTaskService::saveTask([
            'name' => 'Failing Script',
            'is_active' => 1,
            'cron_expression' => '* * * * *',
            'script_type' => 'shell',
            'script_content' => 'echo "fatal error" >&2; exit 42',
        ]);

        $this->assertTrue($save['ok']);
        $this->taskId = (int)$save['id'];

        $result = ScheduledTaskRunner::run($this->taskId, 'schedule');
        $this->assertFalse($result['ok']);
        $this->assertSame(42, $result['exit_code']);

        // Verify Notice was raised
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM sys_notices WHERE notice_key = ? AND resolved_at IS NULL');
        $stmt->execute(['scheduled_task_' . $this->taskId]);
        $notice = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($notice);
        $this->assertSame('fail', $notice['severity']);
        $this->assertStringContainsString('Failing Script', $notice['title']);
    }
}
