<?php

class FaxInboxRepository extends BaseRepository
{
    protected static string $table = 'fax_received';

    public static function getAllowedDIDs(int $userId, string $userExt): array
    {
        $dids = [];
        $ext = trim($userExt);
        if ($ext !== '') {
            $dids[] = $ext;
            if (strlen($ext) === 4) {
                $dids[] = '1' . $ext;
            } elseif (strlen($ext) === 5 && $ext[0] === '1') {
                $dids[] = substr($ext, 1);
            }
        }
        if ($userId > 0) {
            $stmt = static::db()->prepare("SELECT did_extension FROM sys_did_mappings WHERE assigned_user_id = ? AND is_active = 1");
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $d) {
                $d = trim((string)$d);
                if ($d !== '') $dids[] = $d;
            }
        }
        return array_values(array_unique($dids));
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

        $sql = "SELECT id, did_extension, caller_id, pages, tif_path, pdf_path, file_size, status, is_read, email_sent, received_at FROM fax_received{$where} ORDER BY received_at DESC LIMIT $perPage OFFSET $offset";
        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);

        return [
            'faxes' => $stmt->fetchAll(),
            'total_count' => $total_count,
            'total_pages' => $total_pages,
        ];
    }
}
