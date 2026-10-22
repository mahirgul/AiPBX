<?php
/**
 * The cron state in `sys_plugin_cron_runs` (see the migration beside this
 * draft). One row per slug+job, written before and after every run.
 *
 * The upserts are deliberately written as SQL rather than read-modify-write:
 * two dispatchers can race on the same row between the lock check and the
 * write, and `runs = runs + 1` is atomic while `$row['runs'] + 1` is not.
 */

final class PdoCronStore implements PluginCronStore
{
    public function __construct(private PDO $db)
    {
    }

    public function last(string $slug, string $job): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM sys_plugin_cron_runs WHERE slug = ? AND job = ? LIMIT 1'
        );
        $stmt->execute([$slug, $job]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function start(string $slug, string $job, int $at): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO sys_plugin_cron_runs
                (slug, job, last_started, runs, failures, last_status, last_output)
             VALUES (?, ?, ?, 1, 0, 0, \'\')
             ON DUPLICATE KEY UPDATE
                last_started = VALUES(last_started),
                runs = runs + 1'
        );
        $stmt->execute([$slug, $job, $at]);
    }

    public function finish(
        string $slug,
        string $job,
        int $at,
        int $status,
        int $durationMs,
        string $output
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO sys_plugin_cron_runs
                (slug, job, last_started, last_finished, last_status,
                 last_duration_ms, last_output, runs, failures)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, IF(? <> 0, 1, 0))
             ON DUPLICATE KEY UPDATE
                last_finished = VALUES(last_finished),
                last_status = VALUES(last_status),
                last_duration_ms = VALUES(last_duration_ms),
                last_output = VALUES(last_output),
                failures = failures + IF(VALUES(last_status) <> 0, 1, 0)'
        );
        $stmt->execute([
            $slug, $job, $at, $at, $status, $durationMs,
            mb_substr($output, 0, 2000), $status,
        ]);
    }
}
