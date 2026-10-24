<?php

declare(strict_types=1);

/**
 * Repository for pbx_external_dbs (Roadmap 2.2).
 */
class ExternalDbRepository extends BaseRepository
{
    protected static string $table = 'pbx_external_dbs';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function findActive(): array
    {
        return self::findWhere('is_active = 1', [], 'name ASC');
    }

    public static function updateTestStatus(int $id, string $status, ?string $message = null): void
    {
        $db = self::db();
        $stmt = $db->prepare(
            'UPDATE pbx_external_dbs
             SET last_tested_at = NOW(), last_test_status = ?, last_test_message = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$status, $message, $id]);
    }
}
