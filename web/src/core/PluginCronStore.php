<?php
/**
 * Where a plugin job's last run is remembered.
 *
 * The dispatcher needs three facts and nothing else: did this job already start
 * in the current minute, when did it last finish, and did it fail. Keeping them
 * in the database rather than in a file means they survive a restart, can be
 * read by the portal and by health checks, and do not disappear when a plugin
 * is disabled and re-enabled.
 *
 * The state lives in a CORE table (`sys_plugin_cron_runs`) on purpose: it is
 * the loader's bookkeeping about someone else's code, so it must outlive that
 * plugin, and it must never be dropped by a plugin's own schema. It is not the
 * plugin's data and does not get the `plg_` prefix.
 */
interface PluginCronStore
{
    /** @return array<string, mixed>|null the last recorded run, or null */
    public function last(string $slug, string $job): ?array;

    /** Records the start before the job runs, so a second dispatcher skips it. */
    public function start(string $slug, string $job, int $at): void;

    /** Records the outcome, including a failure. */
    public function finish(
        string $slug,
        string $job,
        int $at,
        int $status,
        int $durationMs,
        string $output
    ): void;
}
