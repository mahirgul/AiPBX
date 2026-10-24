<?php

declare(strict_types=1);

/**
 * Repository for pbx_scheduled_tasks, runs, and versions (Roadmap 2.1).
 */
class ScheduledTaskRepository extends BaseRepository
{
    protected static string $table = 'pbx_scheduled_tasks';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function findActive(): array
    {
        return self::findWhere('is_active = 1', [], 'name ASC');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getRunsForTask(int $taskId, int $limit = 25): array
    {
        $stmt = self::db()->prepare(
            'SELECT * FROM pbx_scheduled_task_runs WHERE task_id = ? ORDER BY started_at DESC, id DESC LIMIT ' . (int)$limit
        );
        $stmt->execute([$taskId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * A run is still going while it is marked running and younger than its
     * timeout (plus slack): a run whose process died (reboot, kill) would
     * otherwise block the task for ever.
     */
    public static function isTaskRunning(int $taskId): bool
    {
        $stmt = self::db()->prepare(
            "SELECT COUNT(*) FROM pbx_scheduled_task_runs r JOIN pbx_scheduled_tasks t ON t.id = r.task_id
             WHERE r.task_id = ? AND r.status = 'running' AND r.started_at > NOW() - INTERVAL (t.timeout_sec + 120) SECOND"
        );
        $stmt->execute([$taskId]);
        return ((int)$stmt->fetchColumn()) > 0;
    }

    /** "Run now" from the portal: the dispatcher picks it up within a minute. */
    public static function requestRun(int $taskId): bool
    {
        $stmt = self::db()->prepare('UPDATE pbx_scheduled_tasks SET run_requested_at = NOW() WHERE id = ?');
        $stmt->execute([$taskId]);
        return $stmt->rowCount() > 0;
    }

    /** Tasks with a pending "run now", cleared as they are taken. @return list<array> */
    public static function takeRunRequests(): array
    {
        $db = self::db();
        $rows = $db->query('SELECT * FROM pbx_scheduled_tasks WHERE run_requested_at IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $db->prepare('UPDATE pbx_scheduled_tasks SET run_requested_at = NULL WHERE id = ?')->execute([(int) $r['id']]);
        }
        return $rows;
    }

    public static function recordRunStart(int $taskId, string $triggeredBy = 'schedule'): int
    {
        $db = self::db();
        $stmt = $db->prepare(
            'INSERT INTO pbx_scheduled_task_runs (task_id, status, triggered_by, started_at) VALUES (?, "running", ?, NOW())'
        );
        $stmt->execute([$taskId, $triggeredBy]);
        $runId = (int)$db->lastInsertId();

        $upd = $db->prepare('UPDATE pbx_scheduled_tasks SET last_run_at = NOW(), last_status = "running" WHERE id = ?');
        $upd->execute([$taskId]);

        return $runId;
    }

    public static function recordRunFinish(int $runId, int $exitCode, ?string $output, ?string $error = null): void
    {
        $db = self::db();

        // Calculate duration and fetch task_id
        $stmt = $db->prepare('SELECT task_id, started_at FROM pbx_scheduled_task_runs WHERE id = ?');
        $stmt->execute([$runId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            return;
        }

        $startedAt = strtotime((string)$run['started_at']);
        $finishedAt = time();
        $durationMs = max(0, ($finishedAt - $startedAt) * 1000);

        // Max 64 KB of output as requested by roadmap
        if ($output !== null && strlen($output) > 65536) {
            $output = substr($output, -65536);
        }

        $status = ($exitCode === 0) ? 'success' : 'failed';
        if ($exitCode === 124 || $exitCode === 137) {
            $status = 'timeout';
        }

        $updRun = $db->prepare(
            'UPDATE pbx_scheduled_task_runs
             SET status = ?, finished_at = NOW(), duration_ms = ?, exit_code = ?, output = ?, error_message = ?
             WHERE id = ?'
        );
        $updRun->execute([$status, $durationMs, $exitCode, $output, $error, $runId]);

        $updTask = $db->prepare('UPDATE pbx_scheduled_tasks SET last_status = ? WHERE id = ?');
        $updTask->execute([$status, $run['task_id']]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getVersions(int $taskId): array
    {
        $stmt = self::db()->prepare(
            'SELECT * FROM pbx_scheduled_task_versions WHERE task_id = ? ORDER BY version_num DESC'
        );
        $stmt->execute([$taskId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function recordVersion(int $taskId, string $content, ?int $changedBy = null): void
    {
        $db = self::db();
        $stmt = $db->prepare('SELECT MAX(version_num) FROM pbx_scheduled_task_versions WHERE task_id = ?');
        $stmt->execute([$taskId]);
        $maxVer = (int)$stmt->fetchColumn();
        $nextVer = $maxVer + 1;

        $ins = $db->prepare(
            'INSERT INTO pbx_scheduled_task_versions (task_id, version_num, script_content, changed_by, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $ins->execute([$taskId, $nextVer, $content, $changedBy]);
    }
}
