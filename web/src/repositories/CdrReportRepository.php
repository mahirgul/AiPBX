<?php

class CdrReportRepository extends BaseRepository
{
    protected static string $table = 'cdrs';

    /** Sayfa basina kayit. */
    public const SAYFA_BOYUTU = 50;

    public static function agentsForFilter(): array
    {
        return static::db()->query("SELECT extension, full_name FROM sys_users WHERE extension IS NOT NULL AND extension != '' ORDER BY extension ASC")->fetchAll();
    }

    /**
     * Filtre kosullarini ve parametrelerini uretir.
     *
     * Hem listeleme hem sayim/istatistik ayni kosullari kullanmali; ayri ayri
     * yazilirsa biri degisip digeri unutulur ve rapor tutarsiz olur.
     */
    private static function kosullar(bool $canViewAll, string $userExt, ?string $startTs, ?string $endTs, string $statusFilter, string $agentFilter, string $searchQuery, string $deviceFilter = ''): array
    {
        $sql = " FROM cdrs c LEFT JOIN callcenter_notes n ON n.call_id = c.call_id WHERE 1=1";
        $params = [];

        if (!$canViewAll) {
            $sql .= " AND (c.agent_extension = ? OR c.caller_num = ?)";
            $params[] = $userExt;
            $params[] = $userExt;
        }
        if ($startTs && $endTs) {
            $sql .= " AND c.start_time >= ? AND c.start_time <= ?";
            $params[] = $startTs;
            $params[] = $endTs;
        }
        if (!empty($statusFilter)) {
            $sql .= " AND c.status = ?";
            $params[] = $statusFilter;
        }
        if (!empty($agentFilter)) {
            $sql .= " AND c.agent_extension = ?";
            $params[] = $agentFilter;
        }
        if (!empty($deviceFilter)) {
            $sql .= " AND c.device_type = ?";
            $params[] = $deviceFilter;
        }
        if (!empty($searchQuery)) {
            $sql .= " AND (c.caller_num LIKE ? OR c.agent_extension LIKE ? OR c.agent_name LIKE ? OR c.call_id LIKE ? OR n.customer_name LIKE ? OR n.phone LIKE ? OR n.disposition LIKE ? OR n.notes LIKE ?)";
            for ($i = 0; $i < 8; $i++) {
                $params[] = "%$searchQuery%";
            }
        }
        return [$sql, $params];
    }

    /**
     * Bir sayfalik kayit dondurur.
     *
     * Onceki surumde sabit `LIMIT 300` vardi ve sayfalama yoktu: 300'uncu
     * kayittan sonrasi hic gorunmuyordu ve istatistikler de yalnizca o 300
     * satir uzerinden hesaplaniyordu (2026-09-01'de rapor sayfasi yavas
     * diye bakilirken bulundu).
     */
    public static function search(bool $canViewAll, string $userExt, ?string $startTs, ?string $endTs, string $statusFilter, string $agentFilter, string $searchQuery, int $sayfa = 1, int $boyut = self::SAYFA_BOYUTU, string $deviceFilter = ''): array
    {
        [$where, $params] = static::kosullar($canViewAll, $userExt, $startTs, $endTs, $statusFilter, $agentFilter, $searchQuery, $deviceFilter);

        $sayfa = max(1, $sayfa);
        $boyut = max(1, $boyut);
        $offset = ($sayfa - 1) * $boyut;

        $sql = "SELECT c.id, c.call_id, c.caller_num, c.queue_name, c.agent_extension, c.agent_name, c.start_time, c.answer_time, c.end_time, c.duration, c.billsec, c.ring_sec, c.status, c.device_type, c.channel, c.dstchannel, c.recording_path,
                       n.customer_name AS note_customer_name, n.phone AS note_phone, n.disposition AS note_disposition, n.notes AS note_text"
             . $where
             . " ORDER BY c.start_time DESC LIMIT " . $boyut . " OFFSET " . $offset;

        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Toplam kayit sayisi ve ozet istatistikler — TUM eslesen kayitlar uzerinden.
     *
     * Istatistikler PHP'de satir satir donulerek hesaplaniyordu; sayfalamayla
     * birlikte bu yalnizca goruntulenen sayfayi kapsardi ve ozet yanlis olurdu.
     * Toplamlar bu yuzden veritabaninda hesaplaniyor.
     */
    public static function ozet(bool $canViewAll, string $userExt, ?string $startTs, ?string $endTs, string $statusFilter, string $agentFilter, string $searchQuery, string $deviceFilter = ''): array
    {
        [$where, $params] = static::kosullar($canViewAll, $userExt, $startTs, $endTs, $statusFilter, $agentFilter, $searchQuery, $deviceFilter);

        $sql = "SELECT COUNT(*) AS toplam,
                       SUM(c.status = 'ANSWERED') AS cevaplanan,
                       SUM(c.status IN ('NO ANSWER','ABANDON')) AS cevapsiz,
                       SUM(c.status = 'BUSY') AS mesgul,
                       SUM(c.status NOT IN ('ANSWERED','NO ANSWER','ABANDON','BUSY')) AS basarisiz,
                       COALESCE(SUM(c.billsec), 0) AS toplam_sure,
                       COALESCE(SUM(c.ring_sec), 0) AS toplam_calma,
                       COALESCE(ROUND(AVG(CASE WHEN c.status = 'ANSWERED' THEN c.billsec END)), 0) AS ort_konusma,
                       COALESCE(ROUND(AVG(c.ring_sec)), 0) AS ort_calma,
                       SUM(c.recording_path IS NOT NULL AND c.recording_path != '') AS kayitli"
             . $where;

        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['toplam','cevaplanan','cevapsiz','mesgul','basarisiz','toplam_sure','toplam_calma','ort_konusma','ort_calma','kayitli'] as $k) {
            $r[$k] = intval($r[$k] ?? 0);
        }
        return $r;
    }
}
