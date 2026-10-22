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

    /** @return list<array<string, mixed>> newest first */
    public static function recent(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        return static::db()->query(
            'SELECT id, created_at, severity, title, body, source, read_at, emailed_at
             FROM pbx_notices ORDER BY id DESC LIMIT ' . $limit
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function unreadCount(): int
    {
        return static::count('read_at IS NULL');
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

    public static function markRead(int $id): int
    {
        $st = static::db()->prepare('UPDATE pbx_notices SET read_at = NOW() WHERE id = ? AND read_at IS NULL');
        $st->execute([$id]);
        return $st->rowCount();
    }

    public static function markAllRead(): int
    {
        $st = static::db()->query('UPDATE pbx_notices SET read_at = NOW() WHERE read_at IS NULL');
        return $st->rowCount();
    }

    public static function markEmailed(int $id): void
    {
        static::db()->prepare('UPDATE pbx_notices SET emailed_at = NOW() WHERE id = ?')->execute([$id]);
    }
}
