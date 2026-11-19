<?php

declare(strict_types=1);

require_once __DIR__ . '/CloudAiService.php';

/**
 * Faz 4: Durable async job queue for AI features (`ai-voicemail`, `ai-call-analysis`).
 * Provides idempotent enqueueing, atomic lease-based claiming with restart recovery,
 * bounded retries, cancellation, and atomic cloud quota reservation.
 */
final class AiJobService
{
    /** @var array<string, int> */
    private static array $activeReservations = [];

    public static function activeReservedQuota(string $providerId, string $col): int
    {
        return self::$activeReservations[$providerId . ':' . $col] ?? 0;
    }

    public static function consumeReservedQuota(string $providerId, string $col): int
    {
        $k = $providerId . ':' . $col;
        $v = self::$activeReservations[$k] ?? 0;
        unset(self::$activeReservations[$k]);
        return $v;
    }

    public static function releaseReservedQuota(string $providerId, string $col): void
    {
        $v = self::consumeReservedQuota($providerId, $col);
        if ($v <= 0 || !in_array($col, ['stt_seconds', 'tts_chars', 'llm_tokens'], true)) {
            return;
        }
        try {
            getDB()->prepare("UPDATE ai_cloud_usage SET {$col} = GREATEST(0, {$col} - ?) WHERE provider = ? AND month = ?")
                ->execute([$v, $providerId, date('Y-m')]);
        } catch (\Throwable) {
        }
    }
    /**
     * Enqueues a job idempotently by `idempotency_key`.
     * If a job with the same `idempotency_key` already exists, returns its ID without duplicating it.
     *
     * @param array<string, mixed> $payload
     */
    public static function enqueue(
        string $feature,
        string $task,
        string $entityType,
        string $entityId,
        string $idempotencyKey,
        array $payload = [],
        ?string $bindingRef = null,
        int $maxAttempts = 3
    ): int {
        $db = getDB();
        $now = date('Y-m-d H:i:s');
        $maxAttempts = max(1, min(255, $maxAttempts));
        $payloadJson = $payload !== []
            ? (json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}')
            : null;

        $st = $db->prepare(
            "INSERT IGNORE INTO ai_jobs
             (feature, task, entity_type, entity_id, idempotency_key, binding_ref, status, attempts, max_attempts, payload_json, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', 0, ?, ?, ?, ?)"
        );
        $st->execute([
            $feature,
            $task,
            $entityType,
            $entityId,
            $idempotencyKey,
            $bindingRef,
            $maxAttempts,
            $payloadJson,
            $now,
            $now,
        ]);

        if ($st->rowCount() > 0) {
            $id = (int) $db->lastInsertId();
            if ($id > 0) {
                return $id;
            }
        }

        $sel = $db->prepare('SELECT id FROM ai_jobs WHERE idempotency_key = ? LIMIT 1');
        $sel->execute([$idempotencyKey]);
        return (int) $sel->fetchColumn();
    }

    /**
     * Atomically claims the next `pending` job or recovers an expired `running` job
     * whose `lease_expires_at < NOW()` (restart recovery).
     *
     * @return array<string, mixed>|null
     */
    public static function claimNext(string $workerId, int $leaseSeconds = 120): ?array
    {
        $db = getDB();
        $leaseSeconds = max(1, $leaseSeconds);
        $ownTx = !$db->inTransaction();
        if ($ownTx) {
            $db->beginTransaction();
        }

        try {
            $sel = $db->prepare(
                "SELECT id FROM ai_jobs
                 WHERE (status = 'pending' OR (status = 'running' AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()))
                   AND attempts < max_attempts
                 ORDER BY CASE WHEN status = 'running' THEN 0 ELSE 1 END, id ASC
                 LIMIT 1
                 FOR UPDATE"
            );
            $sel->execute();
            $jobId = $sel->fetchColumn();
            if ($jobId === false) {
                if ($ownTx) {
                    $db->commit();
                }
                return null;
            }
            $jobId = (int) $jobId;

            $leaseOwner = substr($workerId, 0, 44) . '#' . bin2hex(random_bytes(6));

            $upd = $db->prepare(
                "UPDATE ai_jobs
                 SET status = 'running',
                     attempts = attempts + 1,
                     lease_owner = ?,
                     lease_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                     updated_at = NOW()
                 WHERE id = ?
                   AND (status = 'pending' OR (status = 'running' AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()))"
            );
            $upd->execute([$leaseOwner, $leaseSeconds, $jobId]);

            if ($upd->rowCount() === 0) {
                if ($ownTx) {
                    $db->rollBack();
                }
                return null;
            }

            $rowSt = $db->prepare('SELECT * FROM ai_jobs WHERE id = ? LIMIT 1');
            $rowSt->execute([$jobId]);
            $row = $rowSt->fetch(PDO::FETCH_ASSOC);

            if ($ownTx) {
                $db->commit();
            }

            if (!$row) {
                return null;
            }

            return self::hydrateRow($row);
        } catch (\Throwable $e) {
            if ($ownTx) {
                try {
                    $db->rollBack();
                } catch (\Throwable) {
                }
            }
            throw $e;
        }
    }

    /**
     * Atomically claims a specific `ai_jobs` row by ID when it is `pending` (or expired `running`).
     *
     * @return array<string, mixed>|null
     */
    public static function claimById(int $jobId, string $workerId, int $leaseSeconds = 120): ?array
    {
        if ($jobId <= 0) {
            return null;
        }
        $db = getDB();
        $leaseSeconds = max(1, $leaseSeconds);
        $leaseOwner = substr($workerId, 0, 44) . '#' . bin2hex(random_bytes(6));

        $upd = $db->prepare(
            "UPDATE ai_jobs
             SET status = 'running',
                 attempts = attempts + 1,
                 lease_owner = ?,
                 lease_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                 updated_at = NOW()
             WHERE id = ?
               AND attempts < max_attempts
               AND (status = 'pending' OR (status = 'running' AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()))"
        );
        $upd->execute([$leaseOwner, $leaseSeconds, $jobId]);
        if ($upd->rowCount() === 0) {
            return null;
        }

        return self::find($jobId);
    }

    public static function isLeaseActive(int $jobId, ?string $expectedLeaseOwner): bool
    {
        if ($jobId <= 0 || $expectedLeaseOwner === null || $expectedLeaseOwner === '') {
            return false;
        }
        $st = getDB()->prepare("SELECT 1 FROM ai_jobs WHERE id = ? AND status = 'running' AND lease_owner = ? LIMIT 1");
        $st->execute([$jobId, $expectedLeaseOwner]);
        return (bool) $st->fetchColumn();
    }

    /**
     * Marks a job as succeeded and stores its result JSON, fenced by status='running' and optional lease_owner.
     *
     * @param array<string, mixed> $result
     */
    public static function complete(int $jobId, array $result = [], ?string $expectedLeaseOwner = null): bool
    {
        $resultJson = $result !== []
            ? (json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}')
            : null;

        $sql = "UPDATE ai_jobs
                SET status = 'succeeded',
                    result_json = ?,
                    lease_owner = NULL,
                    lease_expires_at = NULL,
                    last_error = NULL,
                    updated_at = NOW(),
                    finished_at = NOW()
                WHERE id = ? AND status = 'running'";
        $params = [$resultJson, $jobId];
        if ($expectedLeaseOwner !== null) {
            $sql .= ' AND lease_owner = ?';
            $params[] = $expectedLeaseOwner;
        }

        $st = getDB()->prepare($sql);
        $st->execute($params);
        return $st->rowCount() > 0;
    }

    /**
     * Marks a job as failed; re-queues as `pending` if `$retryable === true`
     * and `attempts < max_attempts`. Non-retryable errors fail immediately.
     * Fenced atomically by `status = 'running'` and optional `lease_owner`.
     */
    public static function fail(int $jobId, string $error, bool $retryable = true, ?string $expectedLeaseOwner = null): bool
    {
        $db = getDB();
        $leaseClause = $expectedLeaseOwner !== null ? ' AND lease_owner = ?' : '';
        $baseParams = $expectedLeaseOwner !== null ? [$error, $jobId, $expectedLeaseOwner] : [$error, $jobId];

        if ($retryable) {
            $upd = $db->prepare(
                "UPDATE ai_jobs
                 SET status = 'pending',
                     lease_owner = NULL,
                     lease_expires_at = NULL,
                     last_error = ?,
                     updated_at = NOW(),
                     finished_at = NULL
                 WHERE id = ? AND status = 'running' AND attempts < max_attempts{$leaseClause}"
            );
            $upd->execute($baseParams);
            if ($upd->rowCount() > 0) {
                return true;
            }
        }

        $upd = $db->prepare(
            "UPDATE ai_jobs
             SET status = 'failed',
                 lease_owner = NULL,
                 lease_expires_at = NULL,
                 last_error = ?,
                 updated_at = NOW(),
                 finished_at = NOW()
             WHERE id = ? AND status = 'running'{$leaseClause}"
        );
        $upd->execute($baseParams);
        return $upd->rowCount() > 0;
    }

    /**
     * Cancels a `pending` or `running` job. Returns true if a row was cancelled.
     */
    public static function cancel(int $jobId): bool
    {
        $st = getDB()->prepare(
            "UPDATE ai_jobs
             SET status = 'cancelled',
                 lease_owner = NULL,
                 lease_expires_at = NULL,
                 updated_at = NOW(),
                 finished_at = NOW()
             WHERE id = ? AND status IN ('pending', 'running')"
        );
        $st->execute([$jobId]);
        return $st->rowCount() > 0;
    }

    /**
     * Recovers `running` jobs whose leases have expired (`lease_expires_at < NOW()`).
     * Jobs with remaining attempts are returned to `pending`; exhausted ones become `failed`.
     */
    public static function recoverStaleLeases(): int
    {
        $db = getDB();
        $requeued = $db->prepare(
            "UPDATE ai_jobs
             SET status = 'pending',
                 lease_owner = NULL,
                 lease_expires_at = NULL,
                 last_error = COALESCE(last_error, 'Lease expired; recovered for retry'),
                 updated_at = NOW()
             WHERE status = 'running'
               AND lease_expires_at IS NOT NULL
               AND lease_expires_at < NOW()
               AND attempts < max_attempts"
        );
        $requeued->execute();
        $count = $requeued->rowCount();

        $exhausted = $db->prepare(
            "UPDATE ai_jobs
             SET status = 'failed',
                 lease_owner = NULL,
                 lease_expires_at = NULL,
                 last_error = COALESCE(last_error, 'Lease expired after max attempts'),
                 updated_at = NOW(),
                 finished_at = NOW()
             WHERE status = 'running'
               AND lease_expires_at IS NOT NULL
               AND lease_expires_at < NOW()
               AND attempts >= max_attempts"
        );
        $exhausted->execute();
        return $count + $exhausted->rowCount();
    }

    /**
     * Atomically checks `CloudAiService::overLimit` / `sys_settings` quota and reserves
     * `$units` in `ai_cloud_usage` for the current month so concurrent workers do not
     * overshoot paid cloud quotas or blindly retry uncertain paid requests.
     */
    public static function checkAndReserveQuota(string $providerId, string $metric, int $units = 1): bool
    {
        $providerId = trim($providerId);
        if ($providerId === '') {
            return false;
        }
        $units = max(0, $units);

        $map = match ($metric) {
            'stt', 'stt_min', 'stt_seconds' => ['job' => 'stt', 'col' => 'stt_seconds', 'limit_key' => 'stt_min', 'mult' => 60],
            'tts', 'tts_chars' => ['job' => 'tts', 'col' => 'tts_chars', 'limit_key' => 'tts_chars', 'mult' => 1],
            'llm', 'llm_tokens' => ['job' => 'llm', 'col' => 'llm_tokens', 'limit_key' => 'llm_tokens', 'mult' => 1],
            default => null,
        };
        if ($map === null) {
            return false;
        }

        if (CloudAiService::overLimit($providerId, $map['job'])) {
            return false;
        }

        $limits = CloudAiService::limits($providerId);
        $limit = (float) $limits[$map['limit_key']] * $map['mult'];
        if ($limit <= 0.0) {
            return true;
        }

        $db = getDB();
        $month = date('Y-m');
        $col = $map['col'];
        $ownTx = !$db->inTransaction();
        if ($ownTx) {
            $db->beginTransaction();
        }

        try {
            $ins = $db->prepare('INSERT IGNORE INTO ai_cloud_usage (provider, month) VALUES (?, ?)');
            $ins->execute([$providerId, $month]);

            $sel = $db->prepare("SELECT {$col} FROM ai_cloud_usage WHERE provider = ? AND month = ? FOR UPDATE");
            $sel->execute([$providerId, $month]);
            $current = (float) ($sel->fetchColumn() ?: 0);

            if ($current >= $limit || ($current + $units) > $limit) {
                if ($ownTx) {
                    $db->rollBack();
                }
                return false;
            }

            if ($units > 0) {
                $upd = $db->prepare("UPDATE ai_cloud_usage SET {$col} = {$col} + ? WHERE provider = ? AND month = ?");
                $upd->execute([$units, $providerId, $month]);
                $k = $providerId . ':' . $col;
                self::$activeReservations[$k] = (self::$activeReservations[$k] ?? 0) + $units;
            }

            if ($ownTx) {
                $db->commit();
            }
            return true;
        } catch (\Throwable $e) {
            if ($ownTx) {
                try {
                    $db->rollBack();
                } catch (\Throwable) {
                }
            }
            return false;
        }
    }

    /** @return array<string, mixed>|null */
    public static function find(int $jobId): ?array
    {
        $st = getDB()->prepare('SELECT * FROM ai_jobs WHERE id = ? LIMIT 1');
        $st->execute([$jobId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydrateRow($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recent(?string $feature = null, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $db = getDB();
        if ($feature !== null && $feature !== '') {
            $st = $db->prepare("SELECT * FROM ai_jobs WHERE feature = ? ORDER BY id DESC LIMIT {$limit}");
            $st->execute([$feature]);
        } else {
            $st = $db->query("SELECT * FROM ai_jobs ORDER BY id DESC LIMIT {$limit}");
        }
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        return array_map([self::class, 'hydrateRow'], $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrateRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['attempts'] = (int) $row['attempts'];
        $row['max_attempts'] = (int) $row['max_attempts'];
        $payload = isset($row['payload_json']) && is_string($row['payload_json']) && $row['payload_json'] !== ''
            ? json_decode($row['payload_json'], true)
            : [];
        $row['payload'] = is_array($payload) ? $payload : [];
        $result = isset($row['result_json']) && is_string($row['result_json']) && $row['result_json'] !== ''
            ? json_decode($row['result_json'], true)
            : [];
        $row['result'] = is_array($result) ? $result : [];
        return $row;
    }
}
