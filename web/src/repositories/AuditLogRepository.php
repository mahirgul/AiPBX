<?php
/**
 * Kalıcı denetim kaydı (sys_audit_log) okuma sorguları — yazma tarafı
 * src/asterisk_sync.php::writeAuditLog() içinde (markPendingSync()/
 * applyPendingSync() tarafından çağrılıyor).
 */
class AuditLogRepository extends BaseRepository
{
    protected static string $table = 'sys_audit_log';

    public static function userMap(): array
    {
        return static::db()->query("SELECT id, full_name FROM sys_users ORDER BY full_name ASC")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Filtrelenmiş denetim kaydı listesi (en yeni üstte, en fazla 500 satır —
     * QueueLogRepository::searchAndParse() ile aynı basit LIMIT deseni,
     * ayrı bir pager kurulmadı).
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
     * Giriş denemeleri (sys_login_logs) — bu tablo aslında zaten vardı
     * (brute-force kilitleme mantığı checkBruteForceLockout()'ta kullanıyor)
     * ama hiçbir sayfada GÖRÜNTÜLENMİYORDU (2026-08-24'e kadar). Ayrı bir
     * şema (ip_address/username STRING, user_id FK değil — henüz hiç
     * kaydolmamış/yanlış yazılmış kullanıcı adlarını da kaydedebilmek için
     * bilerek böyle) olduğu için sys_audit_log ile BİRLEŞTİRİLMEDİ, /audit-log
     * sayfasında ayrı bir bölüm olarak gösteriliyor.
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
