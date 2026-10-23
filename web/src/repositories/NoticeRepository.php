<?php
require_once __DIR__ . '/../core/BaseRepository.php';

/**
 * Tables: pbx_notices.
 *
 * Only the queries a notice needs that BaseRepository does not already give:
 * the newest N, the unread count, the dedupe lookup and the two "read" updates.
 */
class NoticeRepository extends BaseRepository
{
    protected static string $table = 'pbx_notices';

    /**
     * The newest notices, each flagged read or unread for this user.
     *
     * @return list<array<string, mixed>> newest first
     */
    public static function recent(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $st = static::db()->prepare(
            'SELECT n.id, n.created_at, n.severity, n.title, n.body, n.source,
                    (r.notice_id IS NOT NULL) AS is_read, n.emailed_at
             FROM pbx_notices n
             LEFT JOIN pbx_notice_reads r ON r.notice_id = n.id AND r.user_id = ?
             ORDER BY n.id DESC LIMIT ' . $limit
        );
        $st->execute([$userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** How many notices this user has not opened — not the whole install's. */
    public static function unreadCount(int $userId): int
    {
        $st = static::db()->prepare(
            'SELECT COUNT(*) FROM pbx_notices n
             LEFT JOIN pbx_notice_reads r ON r.notice_id = n.id AND r.user_id = ?
             WHERE r.notice_id IS NULL'
        );
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    }

    /**
     * The newest notice carrying this dedupe key inside the last $seconds.
     * Zero seconds means "do not dedupe" and always answers null.
     *
     * @return array<string, mixed>|null
     */
    public static function findRecentByKey(string $key, int $seconds): ?array
    {
        if ($key === '' || $seconds <= 0) {
            return null;
        }
        // The interval is cast, not bound: MySQL accepts ? in a comparison but
        // not inside INTERVAL in every version this runs on.
        $st = static::db()->prepare(
            'SELECT id, created_at FROM pbx_notices
             WHERE dedupe_key = ? AND created_at > (NOW() - INTERVAL ' . (int) $seconds . ' SECOND)
             ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * @param array{severity: string, title: string, body: string, source: string, dedupe_key: string} $notice
     */
    public static function add(array $notice): int
    {
        $st = static::db()->prepare(
            'INSERT INTO pbx_notices (created_at, severity, title, body, source, dedupe_key)
             VALUES (NOW(), ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $notice['severity'],
            mb_substr($notice['title'], 0, 150),
            $notice['body'],
            mb_substr($notice['source'], 0, 32),
            mb_substr($notice['dedupe_key'], 0, 64),
        ]);
        return (int) static::db()->lastInsertId();
    }

    /**
     * Mark one notice read for this user. IGNORE keeps a second click from
     * failing on the composite key; the notice must exist, so an unknown id
     * cannot leave an orphan row behind.
     */
    public static function markRead(int $id, int $userId): int
    {
        $st = static::db()->prepare(
            'INSERT IGNORE INTO pbx_notice_reads (notice_id, user_id, read_at)
             SELECT id, ?, NOW() FROM pbx_notices WHERE id = ?'
        );
        $st->execute([$userId, $id]);
        return $st->rowCount();
    }

    /** Mark every notice this user has not opened; other users are untouched. */
    public static function markAllRead(int $userId): int
    {
        $st = static::db()->prepare(
            'INSERT IGNORE INTO pbx_notice_reads (notice_id, user_id, read_at)
             SELECT n.id, ?, NOW() FROM pbx_notices n
             LEFT JOIN pbx_notice_reads r ON r.notice_id = n.id AND r.user_id = ?
             WHERE r.notice_id IS NULL'
        );
        $st->execute([$userId, $userId]);
        return $st->rowCount();
    }

    public static function markEmailed(int $id): void
    {
        static::db()->prepare('UPDATE pbx_notices SET emailed_at = NOW() WHERE id = ?')->execute([$id]);
    }
}
