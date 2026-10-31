<?php

declare(strict_types=1);

/**
 * Database access for the Inbound Blacklist / Spam blocking.
 * Table: pbx_inbound_blacklist.
 */
class InboundBlacklistRepository extends BaseRepository
{
    protected static string $table = 'pbx_inbound_blacklist';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function allOrdered(): array
    {
        return static::findAll('id DESC');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function findActive(): array
    {
        return static::findWhere('is_active = 1', [], 'number ASC');
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findByNumber(string $number): ?array
    {
        $rows = static::findWhere('number = ?', [$number]);
        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $stmt = static::db()->prepare(
            'INSERT INTO pbx_inbound_blacklist (number, title, block_action, is_active, hit_count, created_at, updated_at) 
             VALUES (?, ?, ?, ?, 0, NOW(), NOW())'
        );
        $stmt->execute([
            $data['number'],
            $data['title'] ?? null,
            $data['block_action'] ?? 'busy',
            isset($data['is_active']) ? (int)$data['is_active'] : 1,
        ]);
        return (int)static::db()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): bool
    {
        $stmt = static::db()->prepare(
            'UPDATE pbx_inbound_blacklist 
             SET number = ?, title = ?, block_action = ?, is_active = ?, updated_at = NOW() 
             WHERE id = ?'
        );
        return $stmt->execute([
            $data['number'],
            $data['title'] ?? null,
            $data['block_action'] ?? 'busy',
            isset($data['is_active']) ? (int)$data['is_active'] : 1,
            $id,
        ]);
    }

    public static function delete(int $id): bool
    {
        $stmt = static::db()->prepare('DELETE FROM pbx_inbound_blacklist WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public static function toggleActive(int $id): bool
    {
        $stmt = static::db()->prepare('UPDATE pbx_inbound_blacklist SET is_active = IF(is_active = 1, 0, 1), updated_at = NOW() WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public static function recordHit(string $number): bool
    {
        $stmt = static::db()->prepare(
            'UPDATE pbx_inbound_blacklist 
             SET hit_count = hit_count + 1, last_blocked_at = NOW() 
             WHERE number = ?'
        );
        return $stmt->execute([$number]);
    }
}
