<?php
/**
 * Runs the jobs a manifest declares, on their own schedules.
 *
 * One crontab line runs this every minute. It asks the loader which plugins are
 * enabled, reads their `cron` lists and runs only what is due. Compared with
 * generating a crontab per plugin, this keeps four promises:
 *
 *   - installing, disabling or removing a plugin never needs a privileged write
 *     to /etc; the manifest is the schedule;
 *   - a job can never run twice at once (a non-blocking lock per slug+job), even
 *     if the previous minute's run is still going;
 *   - a plugin that fatals, hangs or exhausts memory cannot take the portal or
 *     the dispatcher down: the job runs in its own PHP process with a timeout
 *     (PluginProcessRunner), and its outcome is recorded;
 *   - the state is visible: `--status` and the portal can show the last run,
 *     its exit code and how long it took.
 *
 * Time is the database's minute, not a job's own clock: a run that starts at
 * 10:00:00.800 still counts as the 10:00 run, and a second dispatcher in that
 * minute sees `last_started` and skips it.
 *
 * Missed minutes are not backfilled. If the portal is down for an hour, an
 * every-minute job runs once when it returns — ten catch-up runs of a profile
 * publisher, a collector or a notifier would be worse than one.
 */

final class PluginCron
{
    /** @var callable(string, string, int): array{status:int, output:string} */
    private $runner;

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private PluginRuntime $runtime,
        private PluginCronStore $store,
        callable $runner,
        private string $lockDir,
        ?callable $clock = null
    ) {
        $this->runner = $runner;
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Runs every due job of every enabled plugin.
     *
     * @return array<int, array<string, mixed>> one entry per declared job
     */
    public function dispatch(?string $onlySlug = null): array
    {
        $results = [];
        $slugs = $onlySlug === null ? $this->runtime->enabledSlugs() : [$onlySlug];
        foreach ($slugs as $slug) {
            foreach ($this->runtime->jobs($slug) as $job) {
                $name = (string) ($job['job'] ?? '');
                if ($name === '') {
                    continue;
                }
                $results[] = $this->run($slug, $name, false);
            }
        }
        return $results;
    }

    /**
     * Runs one job. `$force` is the manual path: ignore the schedule and the
     * "already ran this minute" guard, but still take the lock, because a human
     * running it while cron does would be the same overlap.
     *
     * @return array<string, mixed>
     */
    public function run(string $slug, string $name, bool $force = false): array
    {
        $result = ['slug' => $slug, 'job' => $name, 'result' => 'unknown',
                   'status' => null, 'duration_ms' => null];

        // A manifest is not an instruction until the loader says the plugin is
        // on. Refusing here means a manual `--run` cannot be used to execute a
        // disabled plugin's code behind the administrator's back.
        if (!in_array($slug, $this->runtime->enabledSlugs(), true)) {
            $result['result'] = 'disabled';
            return $result;
        }

        $job = $this->runtime->job($slug, $name);
        if ($job === null) {
            return $result;
        }
        try {
            $schedule = new PluginCronSchedule((string) ($job['schedule'] ?? ''));
        } catch (InvalidArgumentException $e) {
            $result['result'] = 'invalid';
            return $result;
        }

        $now = $this->now();
        if (!$force && !$schedule->matches((new DateTimeImmutable())->setTimestamp($now))) {
            $result['result'] = 'not-due';
            return $result;
        }

        // A dispatcher that ran earlier in this minute already started it.
        // (Set before the job runs, so a crash still counts as "started".)
        $minuteStart = $now - ($now % 60);
        $last = $this->store->last($slug, $name);
        if (!$force && (int) ($last['last_started'] ?? 0) >= $minuteStart) {
            $result['result'] = 'not-due';
            return $result;
        }

        $lock = $this->lock($slug, $name);
        if ($lock === null) {
            $result['result'] = 'locked';
            return $result;
        }

        try {
            $this->store->start($slug, $name, $now);
            $started = microtime(true);
            try {
                $run = ($this->runner)($slug, $name, $this->timeout($job));
                $status = (int) $run['status'];
                $output = (string) $run['output'];
            } catch (Throwable $e) {
                // The runner itself failed (no PHP binary, unreadable script):
                // that is a failed run, not a crashed dispatcher.
                $status = 1;
                $output = $e->getMessage();
            }
            $duration = (int) round((microtime(true) - $started) * 1000);
            $this->store->finish($slug, $name, $this->now(), $status, $duration, $output);
            $result['result'] = 'ran';
            $result['status'] = $status;
            $result['duration_ms'] = $duration;
            return $result;
        } finally {
            $this->unlock($lock);
        }
    }

    /**
     * The declared jobs and what happened last time, for a CLI `--status` or a
     * portal table. No job is run here.
     *
     * @return array<int, array<string, mixed>>
     */
    public function status(): array
    {
        $rows = [];
        $enabled = $this->runtime->enabledSlugs();
        foreach (array_keys($this->runtime->manifests()) as $slug) {
            foreach ($this->runtime->jobs($slug) as $job) {
                $name = (string) ($job['job'] ?? '');
                $last = $this->store->last($slug, $name);
                $rows[] = [
                    'slug' => $slug,
                    'job' => $name,
                    'schedule' => (string) ($job['schedule'] ?? ''),
                    'entry' => $job['class'] ?? $job['file'] ?? '',
                    'timeout' => $this->timeout($job),
                    'last_started' => $last['last_started'] ?? null,
                    'last_finished' => $last['last_finished'] ?? null,
                    'last_status' => $last['last_status'] ?? null,
                    'last_duration_ms' => $last['last_duration_ms'] ?? null,
                    'last_output' => $last['last_output'] ?? '',
                    'enabled' => in_array($slug, $enabled, true),
                ];
            }
        }
        return $rows;
    }

    /** @param array<string, mixed> $job */
    private function timeout(array $job): int
    {
        $timeout = $job['timeout'] ?? 60;
        return is_int($timeout) ? max(1, min(3600, $timeout)) : 60;
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }

    /** @return resource|null an exclusive handle, or null when the job is running */
    private function lock(string $slug, string $name)
    {
        if (!is_dir($this->lockDir) && !@mkdir($this->lockDir, 0750, true) && !is_dir($this->lockDir)) {
            return null;
        }
        $path = $this->lockDir . DIRECTORY_SEPARATOR . $slug . '.' . $name . '.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /** @param resource $handle */
    private function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
