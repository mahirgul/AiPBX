<?php

/**
 * Repository for call-center break / pause reason definitions (cc_pause_reasons).
 */
class PauseReasonRepository extends BaseRepository
{
    protected static string $table = 'cc_pause_reasons';

    /** @return list<array<string, mixed>> */
    public static function allOrdered(): array
    {
        return static::db()
            ->query('SELECT * FROM cc_pause_reasons ORDER BY sort_order ASC, id ASC')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public static function activeOrdered(): array
    {
        return static::db()
            ->query('SELECT * FROM cc_pause_reasons WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, array<string, mixed>> */
    public static function mapByName(): array
    {
        $map = [];
        foreach (static::allOrdered() as $r) {
            $map[(string) $r['name']] = $r;
        }
        return $map;
    }

    public static function saveReason(
        int $id,
        string $code,
        string $name,
        string $icon,
        ?int $maxMinutes,
        int $sortOrder,
        int $isActive,
        int $warnOnExceed = 1,
        int $autoUnpause = 0
    ): void {
        $code = trim($code);
        $name = trim($name);
        $icon = trim($icon);
        if ($icon === '') {
            $icon = '☕';
        }
        if ($code === '' || $name === '') {
            throw new RuntimeException(t('common.error') . ': Code and name are required.');
        }
        $db = static::db();
        if ($id > 0) {
            $stmt = $db->prepare('UPDATE cc_pause_reasons SET code = ?, name = ?, icon = ?, max_minutes = ?, warn_on_exceed = ?, auto_unpause = ?, sort_order = ?, is_active = ? WHERE id = ?');
            $stmt->execute([$code, $name, $icon, $maxMinutes, $warnOnExceed, $autoUnpause, $sortOrder, $isActive, $id]);
        } else {
            $stmt = $db->prepare('INSERT INTO cc_pause_reasons (code, name, icon, max_minutes, warn_on_exceed, auto_unpause, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$code, $name, $icon, $maxMinutes, $warnOnExceed, $autoUnpause, $sortOrder, $isActive]);
        }
    }

    public static function toggleActive(int $id): void
    {
        $stmt = static::db()->prepare('UPDATE cc_pause_reasons SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function deleteById(int $id): void
    {
        $stmt = static::db()->prepare('DELETE FROM cc_pause_reasons WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Automatically unpause agents whose active break exceeded max_minutes
     * for reasons configured with auto_unpause = 1.
     */
    public static function enforceAutoUnpause(): int
    {
        $sql = "SELECT DISTINCT p.agent_extension
                FROM cc_pause_logs p
                JOIN cc_pause_reasons r ON r.name = p.pause_reason
                WHERE p.status = 'PAUSED'
                  AND r.is_active = 1
                  AND r.auto_unpause = 1
                  AND r.max_minutes IS NOT NULL
                  AND r.max_minutes > 0
                  AND TIMESTAMPDIFF(MINUTE, p.start_time, NOW()) >= r.max_minutes";
        $exts = static::db()->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        if (!$exts) {
            return 0;
        }
        $count = 0;
        if (class_exists('QueueHelper')) {
            foreach ($exts as $ext) {
                $ext = trim((string) $ext);
                if ($ext !== '') {
                    QueueHelper::unpauseMember($ext);
                    $count++;
                }
            }
        }
        return $count;
    }
}
