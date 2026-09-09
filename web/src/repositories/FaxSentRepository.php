<?php

class FaxSentRepository extends BaseRepository
{
    protected static string $table = 'fax_sent';

    public static function listForUser(string $userRole, int $userId): array
    {
        $sql = "SELECT id, user_id, sender_extension, destination_number, pdf_path, pages, status, error_message, created_at, completed_at FROM fax_sent WHERE 1=1";
        $params = [];

        if ($userRole !== 'admin') {
            $sql .= " AND user_id = ?";
            $params[] = $userId;
        }

        $sql .= " ORDER BY created_at DESC LIMIT 200";

        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
