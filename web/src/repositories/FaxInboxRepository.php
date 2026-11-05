<?php

/**
 * Database access for the Fax inbox.
 * Tables: fax_received, sys_did_mappings.
 * Used by FaxInboxController, FaxInboxService.
 */
class FaxInboxRepository extends BaseRepository
{
    protected static string $table = 'fax_received';

    public static function getAllowedDIDs(int $userId, string $userExt): array
    {
        $dids = [];
        $ext = trim($userExt);
        if ($ext !== '') {
            $dids[] = $ext;
        }
        if ($userId > 0) {
            $stmt = static::db()->prepare("SELECT m.did_extension FROM sys_did_mappings m JOIN pbx_fax_number_users f ON f.did_mapping_id = m.id WHERE f.user_id = ? AND m.is_active = 1");
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $d) {
                $d = trim((string)$d);
                if ($d !== '') $dids[] = $d;
            }
        }
        return array_values(array_unique($dids));
    }

    /**
     * The numbers a user can send from: those they are on, and a fax user's own. A SIP extension is
     * not a fax number, so a person with a phone and a fax number sends from the fax number.
     *
     * @return list<string>
     */
    public static function sendableNumbers(int $userId): array
    {
        $own = static::db()->prepare("SELECT extension, extension_type FROM sys_users WHERE id = ?");
        $own->execute([$userId]);
        $user = $own->fetch(PDO::FETCH_ASSOC) ?: [];
        return static::getAllowedDIDs($userId, ($user['extension_type'] ?? '') === 'fax' ? (string) ($user['extension'] ?? '') : '');
    }

    public static function search(string $userRole, string $userExt, string $search, string $dateFrom, string $dateTo, int $perPage, int $offset, int $userId = 0): array
    {
        $where = " WHERE 1=1";
        $params = [];

        if ($userRole !== 'admin') {
            $allowedDids = static::getAllowedDIDs($userId, $userExt);
            if (empty($allowedDids)) {
                $where .= " AND 1=0";
            } else {
                $placeholders = implode(',', array_fill(0, count($allowedDids), '?'));
                $where .= " AND did_extension IN ($placeholders)";
                $params = array_merge($params, $allowedDids);
            }
        }

        if ($search !== '') {
            $where .= " AND (caller_id LIKE ? OR did_extension LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        if ($dateFrom !== '') {
            $where .= " AND received_at >= ?";
            $params[] = $dateFrom . " 00:00:00";
        }

        if ($dateTo !== '') {
            $where .= " AND received_at <= ?";
            $params[] = $dateTo . " 23:59:59";
        }

        $count_stmt = static::db()->prepare("SELECT COUNT(*) FROM fax_received{$where}");
        $count_stmt->execute($params);
        $total_count = (int)$count_stmt->fetchColumn();
        $total_pages = max(1, (int)ceil($total_count / $perPage));

        $sql = "SELECT id, did_extension, caller_id, pages, tif_path, pdf_path, file_size, status, error_message, is_read, email_sent, received_at FROM fax_received{$where} ORDER BY received_at DESC LIMIT $perPage OFFSET $offset";
        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);

        return [
            'faxes' => $stmt->fetchAll(),
            'total_count' => $total_count,
            'total_pages' => $total_pages,
        ];
    }
}
