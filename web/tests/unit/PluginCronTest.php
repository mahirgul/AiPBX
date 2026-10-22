<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/core/PluginCronSchedule.php';
require_once dirname(__DIR__, 2) . '/src/core/PluginRuntime.php';
require_once dirname(__DIR__, 2) . '/src/core/PluginCronStore.php';
require_once dirname(__DIR__, 2) . '/src/core/PluginCron.php';

/** An in-memory replacement for the table: enough to test the dispatcher. */
final class ArrayCronStore implements PluginCronStore
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    public function last(string $slug, string $job): ?array
    {
        return $this->rows[$slug . '.' . $job] ?? null;
    }

    public function start(string $slug, string $job, int $at): void
    {
        $key = $slug . '.' . $job;
        $row = $this->rows[$key] ?? [
            'runs' => 0, 'failures' => 0, 'last_status' => 0,
            'last_output' => '', 'last_finished' => null,
        ];
        $row['last_started'] = $at;
        $row['runs']++;
        $this->rows[$key] = $row;
    }

    public function finish(
        string $slug,
        string $job,
        int $at,
        int $status,
        int $durationMs,
        string $output
    ): void {
        $key = $slug . '.' . $job;
        $row = $this->rows[$key] ?? ['runs' => 0, 'failures' => 0];
        $row['last_finished'] = $at;
        $row['last_status'] = $status;
        $row['last_duration_ms'] = $durationMs;
        $row['last_output'] = $output;
        if ($status !== 0) {
            $row['failures'] = (int) ($row['failures'] ?? 0) + 1;
        }
        $this->rows[$key] = $row;
    }
}

final class PluginCronTest extends TestCase
{
    private string $root;
    private string $locks;
    private int $now;
    /** @var array<int, array{slug: string, job: string, timeout: int}> */
    private array $runs = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/aipbx-cron-' . bin2hex(random_bytes(4));
        $this->locks = $this->root . '/locks';
        mkdir($this->root . '/demo/cron', 0755, true);
        mkdir($this->locks, 0755, true);
        file_put_contents($this->root . '/demo/cron/run.php',
            "<?php\nreturn static fn(array \$settings, string \$slug): int => 0;\n");
        file_put_contents($this->root . '/demo/plugin.json', json_encode([
            'slug' => 'demo',
            'cron' => [
                ['job' => 'every-minute', 'schedule' => '* * * * *',
                 'file' => 'cron/run.php', 'timeout' => 30],
                ['job' => 'quarter-hour', 'schedule' => '*/15 * * * *',
                 'file' => 'cron/run.php'],
                ['job' => 'new-year', 'schedule' => '0 0 1 1 *',
                 'file' => 'cron/run.php'],
            ],
        ]));
        // 2026-10-21 10:00 local: the top of an hour, so */15 is due too.
        $this->now = strtotime('2026-10-21 10:00:00') ?: 0;
        $this->runs = [];
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    private function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    private function runtime(array $enabled = ['demo']): PluginRuntime
    {
        return new PluginRuntime(
            $this->root,
            static fn(string $slug): bool => in_array($slug, $enabled, true),
            static fn(string $slug): array => []
        );
    }

    /** @param callable(string, string, int): array<string, mixed>|null $runner */
    private function cron(PluginRuntime $runtime, ArrayCronStore $store, ?callable $runner = null): PluginCron
    {
        $runner ??= function (string $slug, string $job, int $timeout): array {
            $this->runs[] = ['slug' => $slug, 'job' => $job, 'timeout' => $timeout];
            return ['status' => 0, 'output' => ''];
        };
        return new PluginCron(
            $runtime,
            $store,
            $runner,
            $this->locks,
            fn(): int => $this->now
        );
    }

    public function testDispatchRunsOnlyTheJobsThatAreDue(): void
    {
        $store = new ArrayCronStore();
        $results = $this->cron($this->runtime(), $store)->dispatch();

        $byJob = [];
        foreach ($results as $result) {
            $byJob[$result['job']] = $result;
        }
        self::assertSame('ran', $byJob['every-minute']['result']);
        self::assertSame('ran', $byJob['quarter-hour']['result']);
        self::assertSame('not-due', $byJob['new-year']['result']);
        self::assertCount(2, $this->runs);
        self::assertSame(30, $this->runs[0]['timeout']);
        self::assertSame(60, $this->runs[1]['timeout'], 'default timeout');
        self::assertSame(1, $store->last('demo', 'every-minute')['runs']);
        self::assertSame(0, $store->last('demo', 'every-minute')['last_status']);
    }

    public function testTheSameMinuteIsNotRunTwice(): void
    {
        $store = new ArrayCronStore();
        $cron = $this->cron($this->runtime(), $store);
        $cron->dispatch();
        $first = count($this->runs);

        $this->now += 20; // still inside 10:00
        $results = $cron->dispatch();
        self::assertCount($first, $this->runs, 'no second run in the same minute');
        foreach ($results as $result) {
            self::assertNotSame('ran', $result['result']);
        }
    }

    public function testTheNextMinuteRunsAgain(): void
    {
        $store = new ArrayCronStore();
        $cron = $this->cron($this->runtime(), $store);
        $cron->dispatch();
        $first = count($this->runs);

        $this->now += 60; // 10:01, every-minute is due again; quarter-hour is not
        $results = $cron->dispatch();
        self::assertSame($first + 1, count($this->runs));
        $byJob = [];
        foreach ($results as $result) {
            $byJob[$result['job']] = $result;
        }
        self::assertSame('ran', $byJob['every-minute']['result']);
        self::assertSame('not-due', $byJob['quarter-hour']['result']);
    }

    public function testARunningJobHoldsTheLockAndTheNextDispatchSkipsIt(): void
    {
        $lock = fopen($this->locks . '/demo.every-minute.lock', 'c');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        $store = new ArrayCronStore();
        $results = $this->cron($this->runtime(), $store)->dispatch();
        $locked = array_values(array_filter($results,
            static fn(array $r): bool => $r['job'] === 'every-minute'));
        self::assertSame('locked', $locked[0]['result']);
        foreach ($this->runs as $run) {
            self::assertNotSame('every-minute', $run['job'], 'a locked job does not start');
        }
        self::assertNull($store->last('demo', 'every-minute'));

        flock($lock, LOCK_UN);
        fclose($lock);
    }

    public function testAFailedJobIsRecordedAndDoesNotStopTheOthers(): void
    {
        $store = new ArrayCronStore();
        $calls = 0;
        $cron = $this->cron($this->runtime(), $store,
            function (string $slug, string $job, int $timeout) use (&$calls): array {
                $calls++;
                if ($job === 'every-minute') {
                    return ['status' => 3, 'output' => "PHP Fatal error: boom\n"];
                }
                return ['status' => 0, 'output' => ''];
            });

        $cron->dispatch();
        self::assertSame(2, $calls);
        $row = $store->last('demo', 'every-minute');
        self::assertSame(3, $row['last_status']);
        self::assertSame(1, $row['failures']);
        self::assertStringContainsString('boom', $row['last_output']);
        self::assertSame(0, $store->last('demo', 'quarter-hour')['last_status']);
    }

    public function testARunnerThatThrowsIsARecordedFailure(): void
    {
        $store = new ArrayCronStore();
        $cron = $this->cron($this->runtime(), $store,
            static function (string $slug, string $job, int $timeout): array {
                throw new RuntimeException('cannot start the plugin job process');
            });

        $results = $cron->dispatch();
        self::assertSame('ran', $results[0]['result']);
        self::assertSame(1, $results[0]['status']);
        self::assertStringContainsString('cannot start',
            $store->last('demo', 'every-minute')['last_output']);
    }

    public function testForceRunsAJobWhoseScheduleDoesNotMatch(): void
    {
        $store = new ArrayCronStore();
        $result = $this->cron($this->runtime(), $store)->run('demo', 'new-year', true);
        self::assertSame('ran', $result['result']);
        self::assertCount(1, $this->runs);
    }

    public function testForceStillRespectsTheLock(): void
    {
        $lock = fopen($this->locks . '/demo.new-year.lock', 'c');
        flock($lock, LOCK_EX | LOCK_NB);

        $store = new ArrayCronStore();
        $result = $this->cron($this->runtime(), $store)->run('demo', 'new-year', true);
        self::assertSame('locked', $result['result']);
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    public function testADisabledPluginIsNeitherDispatchedNorRunOnDemand(): void
    {
        $store = new ArrayCronStore();
        $cron = $this->cron($this->runtime([]), $store);
        self::assertSame([], $cron->dispatch());
        self::assertSame('disabled', $cron->run('demo', 'every-minute', true)['result']);
        self::assertSame([], $this->runs);
    }

    public function testAnUnknownJobIsReportedNotGuessed(): void
    {
        $store = new ArrayCronStore();
        self::assertSame('unknown',
            $this->cron($this->runtime(), $store)->run('demo', 'nope', true)['result']);
        // A plugin the loader does not know is not an unknown job: it is off.
        self::assertSame('disabled',
            $this->cron($this->runtime(), $store)->run('ghost', 'nope', true)['result']);
    }

    public function testStatusShowsDeclaredJobsAndTheirLastOutcome(): void
    {
        $store = new ArrayCronStore();
        $cron = $this->cron($this->runtime(), $store);
        $cron->dispatch();

        $rows = $cron->status();
        self::assertCount(3, $rows);
        $row = $rows[0];
        self::assertSame('demo', $row['slug']);
        self::assertSame('every-minute', $row['job']);
        self::assertSame('* * * * *', $row['schedule']);
        self::assertTrue($row['enabled']);
        self::assertSame(0, $row['last_status']);
        self::assertNotNull($row['last_started']);
    }

    public function testStatusStillsListsADisabledPluginsJobs(): void
    {
        $rows = $this->cron($this->runtime([]), new ArrayCronStore())->status();
        self::assertCount(3, $rows);
        self::assertFalse($rows[0]['enabled']);
    }
}
