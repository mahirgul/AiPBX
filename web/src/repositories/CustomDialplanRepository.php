<?php

declare(strict_types=1);

/**
 * Database access for Custom Asterisk Dialplans.
 * Table: pbx_custom_dialplans.
 */
class CustomDialplanRepository extends BaseRepository
{
    protected static string $table = 'pbx_custom_dialplans';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function allOrdered(): array
    {
        return static::findAll('sort_order ASC, id ASC');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function findActive(): array
    {
        return static::findWhere('is_active = 1', [], 'sort_order ASC, id ASC');
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findByContextName(string $contextName): ?array
    {
        $rows = static::findWhere('context_name = ?', [trim($contextName)]);
        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $stmt = static::db()->prepare(
            'INSERT INTO pbx_custom_dialplans 
             (title, context_name, dialplan_content, include_in_internal, description, sort_order, is_active, created_at, updated_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            trim((string)$data['title']),
            trim((string)$data['context_name']),
            (string)$data['dialplan_content'],
            !empty($data['include_in_internal']) ? 1 : 0,
            isset($data['description']) && trim((string)$data['description']) !== '' ? trim((string)$data['description']) : null,
            isset($data['sort_order']) ? (int)$data['sort_order'] : 10,
            isset($data['is_active']) ? ((int)$data['is_active'] ? 1 : 0) : 1,
        ]);
        return (int)static::db()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): bool
    {
        $stmt = static::db()->prepare(
            'UPDATE pbx_custom_dialplans 
             SET title = ?, context_name = ?, dialplan_content = ?, include_in_internal = ?, description = ?, sort_order = ?, is_active = ?, updated_at = NOW() 
             WHERE id = ?'
        );
        return $stmt->execute([
            trim((string)$data['title']),
            trim((string)$data['context_name']),
            (string)$data['dialplan_content'],
            !empty($data['include_in_internal']) ? 1 : 0,
            isset($data['description']) && trim((string)$data['description']) !== '' ? trim((string)$data['description']) : null,
            isset($data['sort_order']) ? (int)$data['sort_order'] : 10,
            isset($data['is_active']) ? ((int)$data['is_active'] ? 1 : 0) : 1,
            $id,
        ]);
    }

    public static function delete(int $id): bool
    {
        $stmt = static::db()->prepare('DELETE FROM pbx_custom_dialplans WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public static function toggleActive(int $id): bool
    {
        $stmt = static::db()->prepare(
            'UPDATE pbx_custom_dialplans SET is_active = IF(is_active = 1, 0, 1), updated_at = NOW() WHERE id = ?'
        );
        return $stmt->execute([$id]);
    }
}
