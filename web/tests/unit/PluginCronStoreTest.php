<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/core/PluginCronStore.php';
require_once __DIR__ . '/../../src/core/PdoCronStore.php';

/**
 * The bookkeeping table, against a real MariaDB.
 *
 * The fake store used by PluginCronTest proves the dispatcher's decisions; it
 * cannot prove that the SQL is valid, that `runs = runs + 1` is atomic, or that
 * the column types accept what the dispatcher writes. This class covers that,
 * and it exists because of a real failure: a timed-out job is recorded as -1,
 * and `last_status` was unsigned, so MySQL rejected the row
 * ("Out of range value") exactly when a job had hung. The migration now makes
 * that column signed; this test fails if somebody makes it unsigned again.
 *
 * Needs `sys_plugin_cron_runs` (db/migrations/..._create_plugin_cron_runs.php).
 * CI creates it with `phinx migrate -e testing` before PHPUnit runs.
 */
final class PluginCronStoreTest extends TestCase
{
    private PDO $db;
    private PdoCronStore $store;

    protected function setUp(): void
    {
        $this->db = getDB();
        if ($this->db->query("SHOW TABLES LIKE 'sys_plugin_cron_runs'")->fetchColumn() === false) {
            $this->markTestSkipped(
                'sys_plugin_cron_runs is missing: run `php vendor/bin/phinx migrate -e testing`'
            );
        }
        $this->db->exec("DELETE FROM sys_plugin_cron_runs WHERE slug LIKE 'test-%'");
        $this->store = new PdoCronStore($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->exec("DELETE FROM sys_plugin_cron_runs WHERE slug LIKE 'test-%'");
        }
    }

    public function testAnUnknownJobHasNoRow(): void
    {
        $this->assertNull($this->store->last('test-store', 'never-ran'));
    }

    public function testStartThenFinishRecordsTheRun(): void
    {
        $this->store->start('test-store', 'tick', 1000);
        $this->store->finish('test-store', 'tick', 1001, 0, 42, "line one\nline two\n");

        $row = $this->store->last('test-store', 'tick');
        $this->assertNotNull($row);
        $this->assertSame(1000, (int) $row['last_started']);
        $this->assertSame(1001, (int) $row['last_finished']);
        $this->assertSame(0, (int) $row['last_status']);
        $this->assertSame(42, (int) $row['last_duration_ms']);
        $this->assertSame("line one\nline two\n", $row['last_output']);
        $this->assertSame(1, (int) $row['runs']);
        $this->assertSame(0, (int) $row['failures']);
    }

    public function testTheSecondRunIncrementsInsteadOfDuplicating(): void
    {
        $this->store->start('test-store', 'tick', 1000);
        $this->store->finish('test-store', 'tick', 1001, 0, 10, 'ok');
        $this->store->start('test-store', 'tick', 1060);
        $this->store->finish('test-store', 'tick', 1061, 3, 20, 'boom');

        $row = $this->store->last('test-store', 'tick');
        $this->assertSame(2, (int) $row['runs']);
        $this->assertSame(1, (int) $row['failures']);
        $this->assertSame(3, (int) $row['last_status']);
        $this->assertSame('boom', $row['last_output']);
        $this->assertSame(1, (int) $this->db->query(
            "SELECT COUNT(*) FROM sys_plugin_cron_runs WHERE slug = 'test-store'"
        )->fetchColumn());
    }

    /**
     * A timed-out job is recorded as -1 by PluginProcessRunner. The column has
     * to hold it: an unsigned int makes this insert throw, and the dispatcher
     * would die on the one case the timeout exists for.
     */
    public function testATimedOutRunIsRecordedAsMinusOne(): void
    {
        $this->store->start('test-store', 'hang', 1000);
        $this->store->finish('test-store', 'hang', 1002, -1, 1000, 'timed out after 1 s');

        $row = $this->store->last('test-store', 'hang');
        $this->assertSame(-1, (int) $row['last_status']);
        $this->assertSame(1, (int) $row['failures']);
    }

    public function testTheOutputIsBoundedToTheColumn(): void
    {
        // A job that prints without end must not fail the INSERT: the column is
        // VARCHAR(2048) and mb_substr() keeps the head.
        $noise = str_repeat('x', 5000);
        $this->store->start('test-store', 'loud', 1000);
        $this->store->finish('test-store', 'loud', 1001, 0, 5, $noise);

        $row = $this->store->last('test-store', 'loud');
        $this->assertLessThanOrEqual(2000, mb_strlen((string) $row['last_output']));
    }

    public function testTwoJobsOfOnePluginHaveTheirOwnRows(): void
    {
        $this->store->start('test-store', 'one', 1000);
        $this->store->finish('test-store', 'one', 1001, 0, 10, 'one');
        $this->store->start('test-store', 'two', 1000);
        $this->store->finish('test-store', 'two', 1001, 7, 10, 'two');

        $this->assertSame('one', $this->store->last('test-store', 'one')['last_output']);
        $this->assertSame(7, (int) $this->store->last('test-store', 'two')['last_status']);
    }
}
