<?php
/**
 * Read queries of the permanent audit record (sys_audit_log) — the write
 * side is in src/asterisk_sync.php::writeAuditLog() (called by
 * markPendingSync()/applyPendingSync()).
 */
class AuditLogRepository extends BaseRepository
{
    protected static string $table = 'sys_audit_log';

    public static function userMap(): array
    {
        return static::db()->query("SELECT id, full_name FROM sys_users ORDER BY full_name ASC")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Filtered audit record list (newest first, at most 500 rows — the same
     * simple LIMIT pattern as QueueLogRepository::searchAndParse(), no
     * separate pager).
     */
    public static function search(int $startTs, int $endTs, string $domainFilter, string $actionFilter, string $userFilter, string $searchQuery): array
    {
        $sql = "SELECT * FROM sys_audit_log WHERE 1=1";
        $params = [];

        if ($startTs > 0) {
            $sql .= " AND created_at >= ?";
            $params[] = date('Y-m-d H:i:s', $startTs);
        }
        if ($endTs > 0) {
            $sql .= " AND created_at <= ?";
            $params[] = date('Y-m-d H:i:s', $endTs);
        }
        if ($domainFilter !== '') {
            $sql .= " AND domain = ?";
            $params[] = $domainFilter;
        }
        if ($actionFilter !== '') {
            $sql .= " AND action = ?";
            $params[] = $actionFilter;
        }
        if ($userFilter !== '') {
            $sql .= " AND user_id = ?";
            $params[] = $userFilter;
        }
        if ($searchQuery !== '') {
            $sql .= " AND (entity_label LIKE ? OR username LIKE ?)";
            $params[] = "%$searchQuery%";
            $params[] = "%$searchQuery%";
        }

        $sql .= " ORDER BY created_at DESC, id DESC LIMIT 500";

        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Sign-in attempts (sys_login_logs) — this table already existed (the
     * brute-force lockout logic in checkBruteForceLockout() uses it) but was
     * SHOWN on no page (until 2026-08-24). It has a different schema
     * (ip_address/username STRING, not a user_id FK — on purpose, so it can
     * record usernames that never registered/were mistyped), so it was NOT
     * merged with sys_audit_log and is shown as a separate section on the
     * /audit-log page.
     */
    public static function searchLoginAttempts(int $startTs, int $endTs, string $statusFilter, string $searchQuery): array
    {
        $sql = "SELECT * FROM sys_login_logs WHERE 1=1";
        $params = [];

        if ($startTs > 0) {
            $sql .= " AND created_at >= ?";
            $params[] = date('Y-m-d H:i:s', $startTs);
        }
        if ($endTs > 0) {
            $sql .= " AND created_at <= ?";
            $params[] = date('Y-m-d H:i:s', $endTs);
        }
        if ($statusFilter !== '') {
            $sql .= " AND status = ?";
            $params[] = $statusFilter;
        }
        if ($searchQuery !== '') {
            $sql .= " AND (username LIKE ? OR ip_address LIKE ?)";
            $params[] = "%$searchQuery%";
            $params[] = "%$searchQuery%";
        }

        $sql .= " ORDER BY created_at DESC, id DESC LIMIT 500";

        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
