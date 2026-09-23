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
     * Kullanıcı ve DID haritalarını belleğe alır (hızlı O(1) arama için).
     */
    private static function getLookupMaps(): array
    {
        static $maps = null;
        if ($maps !== null) {
            return $maps;
        }

        $userMap = [];
        try {
            $users = static::db()->query("SELECT extension, full_name FROM sys_users WHERE extension IS NOT NULL AND extension != ''")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($users as $u) {
                $userMap[$u['extension']] = $u['full_name'];
            }
        } catch (\Throwable $e) {
            // fallback
        }

        $didMap = [];
        try {
            $dids = static::db()->query("SELECT did_number, title FROM pbx_dids WHERE did_number IS NOT NULL AND did_number != ''")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($dids as $d) {
                $didMap[$d['did_number']] = $d['title'];
            }
        } catch (\Throwable $e) {
            // fallback
        }

        $maps = [$userMap, $didMap];
        return $maps;
    }

    /**
     * Filtre koşullarını ve parametrelerini üretir (Ham mod için).
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
     * Arama metodunu görünüm moduna göre dallandırır.
     */
    public static function search(bool $canViewAll, string $userExt, ?string $startTs, ?string $endTs, string $statusFilter, string $agentFilter, string $searchQuery, int $sayfa = 1, int $boyut = self::SAYFA_BOYUTU, string $deviceFilter = '', string $viewMode = 'grouped'): array
    {
        if ($viewMode === 'raw') {
            return static::searchRaw($canViewAll, $userExt, $startTs, $endTs, $statusFilter, $agentFilter, $searchQuery, $sayfa, $boyut, $deviceFilter);
        }
        return static::searchGrouped($canViewAll, $userExt, $startTs, $endTs, $statusFilter, $agentFilter, $searchQuery, $sayfa, $boyut, $deviceFilter);
    }

    /**
     * Ham (un-grouped) CDR listesi.
     */
    public static function searchRaw(bool $canViewAll, string $userExt, ?string $startTs, ?string $endTs, string $statusFilter, string $agentFilter, string $searchQuery, int $sayfa = 1, int $boyut = self::SAYFA_BOYUTU, string $deviceFilter = ''): array
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
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['total_legs'] = 1;
            $r['legs'] = [];
        }
        return $rows;
    }

    /**
     * Birleştirilmiş (Grouped by linkedid) Çağrı Listesi.
     * 1 Müşteri Araması = 1 Satır.
     */
    public static function searchGrouped(bool $canViewAll, string $userExt, ?string $startTs, ?string $endTs, string $statusFilter, string $agentFilter, string $searchQuery, int $sayfa = 1, int $boyut = self::SAYFA_BOYUTU, string $deviceFilter = ''): array
    {
        [$userMap, $didMap] = static::getLookupMaps();

        $where = " WHERE 1=1";
        $params = [];

        if (!$canViewAll) {
            $where .= " AND (c.src = ? OR c.dst = ? OR c.accountcode = ? OR c.dstchannel LIKE ? OR c.channel LIKE ?)";
            $params[] = $userExt;
            $params[] = $userExt;
            $params[] = $userExt;
            $params[] = "PJSIP/{$userExt}-%";
            $params[] = "PJSIP/{$userExt}-%";
        }

        if ($startTs && $endTs) {
            $where .= " AND c.calldate >= ? AND c.calldate <= ?";
            $params[] = $startTs;
            $params[] = $endTs;
        }

        if (!empty($agentFilter)) {
            $where .= " AND (c.dst = ? OR c.src = ? OR c.accountcode = ? OR c.dstchannel LIKE ? OR c.channel LIKE ? OR c.dstchannel LIKE ?)";
            $params[] = $agentFilter;
            $params[] = $agentFilter;
            $params[] = $agentFilter;
            $params[] = "PJSIP/{$agentFilter}-%";
            $params[] = "PJSIP/{$agentFilter}-%";
            $params[] = "Local/{$agentFilter}@%";
        }

        if (!empty($deviceFilter)) {
            $where .= " AND (c.dstchannel LIKE ? OR c.channel LIKE ?)";
            $params[] = "%-{$deviceFilter}%";
            $params[] = "%-{$deviceFilter}%";
        }

        if (!empty($searchQuery)) {
            $where .= " AND (c.src LIKE ? OR c.dst LIKE ? OR c.uniqueid LIKE ? OR c.linkedid LIKE ? OR n.customer_name LIKE ? OR n.phone LIKE ? OR n.notes LIKE ?)";
            for ($i = 0; $i < 7; $i++) {
                $params[] = "%$searchQuery%";
            }
        }

        $having = "";
        if (!empty($statusFilter)) {
            if ($statusFilter === 'ANSWERED') {
                $having = " HAVING status = 'ANSWERED'";
            } elseif ($statusFilter === 'NO ANSWER') {
                $having = " HAVING status = 'NO ANSWER'";
            } elseif ($statusFilter === 'BUSY') {
                $having = " HAVING status = 'BUSY'";
            } elseif ($statusFilter === 'ABANDON') {
                $having = " HAVING status = 'ABANDON'";
            } elseif ($statusFilter === 'FAILED') {
                $having = " HAVING status NOT IN ('ANSWERED', 'NO ANSWER', 'BUSY', 'ABANDON')";
            }
        }

        $sayfa = max(1, $sayfa);
        $boyut = max(1, $boyut);
        $offset = ($sayfa - 1) * $boyut;

        $sql = "SELECT 
            coalesce(nullif(c.linkedid, ''), c.uniqueid) AS linkedid,
            MIN(c.calldate) AS start_time,
            MAX(c.calldate + INTERVAL greatest(c.duration, c.billsec) SECOND) AS end_time,
            TIMESTAMPDIFF(SECOND, MIN(c.calldate), MAX(c.calldate + INTERVAL greatest(c.duration, c.billsec) SECOND)) AS duration,
            COUNT(*) AS total_legs,
            MAX(c.id) AS max_id,
            COALESCE(
                MAX(CASE WHEN c.userfield IS NOT NULL AND c.userfield != '' THEN c.id END),
                MAX(CASE WHEN c.disposition = 'ANSWERED' THEN c.id END),
                MAX(c.id)
            ) AS id,
            SUBSTRING_INDEX(GROUP_CONCAT(c.src ORDER BY c.id ASC), ',', 1) AS caller_num,
            MAX(CASE WHEN c.lastapp = 'Queue' THEN SUBSTRING_INDEX(c.lastdata, ',', 1) END) AS queue_name,
            MAX(CASE WHEN c.did IS NOT NULL AND c.did != '' THEN c.did END) AS did,
            COALESCE(
                MAX(CASE WHEN c.disposition = 'ANSWERED' AND c.dst REGEXP '^[0-9]{3,5}$' AND c.dst != c.src THEN c.dst END),
                MAX(CASE WHEN c.dstchannel LIKE 'Local/%' AND c.disposition = 'ANSWERED' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(c.dstchannel, '/', -1), '@', 1) END),
                MAX(CASE WHEN c.dst REGEXP '^[0-9]{3,5}$' AND c.dst != c.src THEN c.dst END)
            ) AS agent_extension,
            CASE 
                WHEN SUM(c.disposition = 'ANSWERED') > 0 THEN 'ANSWERED'
                WHEN SUM(c.disposition = 'BUSY') > 0 THEN 'BUSY'
                WHEN MAX(c.lastapp) = 'Queue' THEN 'ABANDON'
                ELSE 'NO ANSWER'
            END AS status,
            COALESCE(
                MAX(CASE WHEN c.disposition = 'ANSWERED' AND c.lastapp = 'Queue' THEN c.billsec END),
                MAX(CASE WHEN c.disposition = 'ANSWERED' THEN c.billsec END),
                0
            ) AS billsec,
            COALESCE(
                MAX(CASE 
                    WHEN c.disposition = 'ANSWERED' AND c.dstchannel LIKE '%-mob-webrtc%' THEN 'mobil'
                    WHEN c.disposition = 'ANSWERED' AND c.dstchannel LIKE '%-webrtc%' THEN 'webrtc'
                    WHEN c.disposition = 'ANSWERED' AND c.dstchannel LIKE '%-sip%' THEN 'sip'
                    WHEN c.disposition = 'ANSWERED' AND c.channel LIKE '%-mob-webrtc%' THEN 'mobil'
                    WHEN c.disposition = 'ANSWERED' AND c.channel LIKE '%-webrtc%' THEN 'webrtc'
                    WHEN c.disposition = 'ANSWERED' AND c.channel LIKE '%-sip%' THEN 'sip'
                END),
                MAX(CASE 
                    WHEN c.dstchannel LIKE '%-mob-webrtc%' THEN 'mobil'
                    WHEN c.dstchannel LIKE '%-webrtc%' THEN 'webrtc'
                    WHEN c.dstchannel LIKE '%-sip%' THEN 'sip'
                END),
                ''
            ) AS device_type,
            MAX(CASE WHEN c.userfield IS NOT NULL AND c.userfield != '' THEN c.userfield END) AS recording_path,
            MAX(n.customer_name) AS note_customer_name,
            MAX(n.phone) AS note_phone,
            MAX(n.disposition) AS note_disposition,
            MAX(n.notes) AS note_text
        FROM asteriskcdr c
        LEFT JOIN callcenter_notes n ON (n.call_id = c.uniqueid OR n.call_id = c.linkedid)"
        . $where
        . " GROUP BY coalesce(nullif(c.linkedid, ''), c.uniqueid)"
        . $having
        . " ORDER BY start_time DESC LIMIT " . $boyut . " OFFSET " . $offset;

        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [];
        }

        $linkedIds = array_unique(array_filter(array_column($rows, 'linkedid')));
        $legsByLinkedId = static::fetchLegsForLinkedIds($linkedIds);

        foreach ($rows as &$r) {
            $lid = $r['linkedid'];
            $r['call_id'] = $lid;
            $r['duration'] = max(0, (int)($r['duration'] ?? 0));
            $r['billsec'] = max(0, (int)($r['billsec'] ?? 0));
            $r['ring_sec'] = max(0, $r['duration'] - $r['billsec']);
            $r['answer_time'] = date('Y-m-d H:i:s', strtotime($r['start_time']) + $r['ring_sec']);

            // Temsilci Adı
            $ext = $r['agent_extension'] ?? '';
            $r['agent_name'] = !empty($ext) && isset($userMap[$ext]) ? $userMap[$ext] : '';

            // Rota Başlığı Formatlama
            $did = $r['did'] ?? '';
            $q = $r['queue_name'] ?? '';
            if (!empty($q)) {
                $r['queue_name'] = !empty($did) ? "{$q} ({$did})" : $q;
            } elseif (!empty($did) && isset($didMap[$did])) {
                $r['queue_name'] = "{$didMap[$did]} ({$did})";
            } elseif (!empty($did)) {
                $r['queue_name'] = "Gelen Hat: {$did}";
            } else {
                $r['queue_name'] = ($r['agent_extension'] && $r['caller_num'] && strlen($r['caller_num']) <= 5) ? 'Dahili Görüşme' : 'Genel Rota';
            }

            // Alt Bacaklar
            $r['legs'] = $legsByLinkedId[$lid] ?? [];
            if (empty($r['total_legs']) || count($r['legs']) > (int)$r['total_legs']) {
                $r['total_legs'] = count($r['legs']);
            }
        }
        unset($r);

        return $rows;
    }

    /**
     * Verilen linkedid listesine ait tüm alt bacakları çeker ve insan dostu
     * açıklamalarla zenginleştirir.
     */
    public static function fetchLegsForLinkedIds(array $linkedIds): array
    {
        if (empty($linkedIds)) {
            return [];
        }

        [$userMap, $didMap] = static::getLookupMaps();

        $in = implode(',', array_fill(0, count($linkedIds), '?'));
        $sql = "SELECT c.id, c.uniqueid, coalesce(nullif(c.linkedid, ''), c.uniqueid) AS linkedid,
                       c.calldate, c.src, c.dst, c.did, c.dcontext, c.channel, c.dstchannel,
                       c.lastapp, c.lastdata, c.duration, c.billsec, c.disposition, c.userfield,
                       CASE
                           WHEN c.dstchannel LIKE '%-mob-webrtc%' THEN 'mobil'
                           WHEN c.dstchannel LIKE '%-webrtc%' THEN 'webrtc'
                           WHEN c.dstchannel LIKE '%-sip%' THEN 'sip'
                           WHEN c.channel LIKE '%-mob-webrtc%' THEN 'mobil'
                           WHEN c.channel LIKE '%-webrtc%' THEN 'webrtc'
                           WHEN c.channel LIKE '%-sip%' THEN 'sip'
                           ELSE ''
                       END AS device_type
                FROM asteriskcdr c
                WHERE c.linkedid IN ($in) OR c.uniqueid IN ($in)
                ORDER BY c.calldate ASC, c.id ASC";

        $stmt = static::db()->prepare($sql);
        $stmt->execute(array_merge($linkedIds, $linkedIds));
        $allLegs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($allLegs as $leg) {
            $lid = $leg['linkedid'];
            $dst = $leg['dst'] ?? '';
            $leg['agent_name'] = !empty($dst) && isset($userMap[$dst]) ? $userMap[$dst] : '';
            $leg['leg_info'] = static::formatLegDescription($leg);
            $grouped[$lid][] = $leg;
        }

        return $grouped;
    }

    /**
     * Bir çağrı bacağını analiz edip Türkçe/İngilizce görsel ve açıklayıcı format üretir.
     */
    public static function formatLegDescription(array $leg): array
    {
        $app = $leg['lastapp'] ?? '';
        $disp = $leg['disposition'] ?? '';
        $dst = $leg['dst'] ?? '';
        $agentName = $leg['agent_name'] ?? '';
        $dev = $leg['device_type'] ?? '';
        $dur = (int)($leg['duration'] ?? 0);
        $bill = (int)($leg['billsec'] ?? 0);
        $lastdata = $leg['lastdata'] ?? '';

        $title = '';
        $detail = '';
        $badge = 'badge-secondary';
        $badgeText = $disp;
        $icon = 'fa-phone';

        if ($disp === 'ANSWERED') {
            $badge = 'badge-success';
            $badgeText = t('cdr_reports.status_answered_label', 'Cevaplandı');
        } elseif (in_array($disp, ['NO ANSWER', 'CANCEL', 'NOANSWER'])) {
            $badge = 'badge-warning';
            $badgeText = t('cdr_reports.status_no_answer_label', 'Cevapsız');
        } elseif ($disp === 'BUSY') {
            $badge = 'badge-info';
            $badgeText = t('cdr_reports.status_busy_label', 'Meşgul');
        } elseif ($disp === 'ABANDON' || $disp === 'FAILED') {
            $badge = 'badge-danger';
            $badgeText = ($disp === 'ABANDON') ? t('cdr_reports.status_abandon_label', 'Terk Edildi') : t('cdr_reports.status_failed_label', 'Başarısız');
        }

        $devStr = !empty($dev) ? ' [' . strtoupper($dev) . ']' : '';
        $agentStr = !empty($agentName) ? "{$dst} ({$agentName}){$devStr}" : "{$dst}{$devStr}";

        if ($app === 'Queue') {
            $icon = 'fa-users';
            $qName = explode(',', $lastdata)[0] ?? 'Kuyruk';
            if ($disp === 'ANSWERED') {
                $title = "Kuyruk Görüşmesi: {$qName}";
                $detail = "Temsilci ile {$bill} sn görüşme sağlandı.";
            } else {
                $title = "Kuyruk Beklemesi: {$qName}";
                $detail = "Arayan kuyrukta {$dur} sn bekledi.";
            }
        } elseif ($app === 'Dial') {
            $icon = 'fa-phone-volume';
            if ($disp === 'ANSWERED') {
                $title = "Temsilci Çağrıyı Yanıtladı: {$agentStr}";
                $detail = ($bill > 0) ? "{$bill} sn net konuşuldu." : "Görüşme sağlandı.";
            } elseif (in_array($disp, ['NO ANSWER', 'CANCEL', 'NOANSWER'])) {
                if ($dur > 0) {
                    $title = "Temsilci Çaldırıldı: {$agentStr}";
                    $detail = "{$dur} sn çaldı, yanıtlanmadı (Zaman aşımı).";
                } else {
                    $title = "Eşzamanlı Cihaz: {$agentStr}";
                    $detail = "Çağrı başka cihazdan yanıtlandığı veya zaman aşımına uğradığı için iptal edildi.";
                }
            } else {
                $title = "Temsilci Arama Denemesi: {$agentStr}";
                $detail = "Sonuç: {$disp}";
            }
        } elseif ($app === 'ReceiveFAX' || $app === 'SendFAX') {
            $icon = 'fa-fax';
            $title = ($app === 'ReceiveFAX') ? 'Gelen Faks İletimi' : 'Giden Faks İletimi';
            $detail = "Sonuç: {$disp}";
        } elseif ($app === 'Hangup') {
            $icon = 'fa-phone-slash';
            $title = "Çağrı Kapatıldı (Hangup)";
            $detail = "Hat sonlandırıldı.";
        } else {
            $title = !empty($app) ? "İşlem: {$app} ({$dst})" : "Çağrı Adımı";
            $detail = "Süre: {$dur} sn • Konuşma: {$bill} sn";
        }

        return [
            'title' => $title,
            'detail' => $detail,
            'badge' => $badge,
            'badge_text' => $badgeText,
            'icon' => $icon,
        ];
    }

    /**
     * Toplam kayıt sayısı ve özet istatistikler — TÜM eşleşen kayıtlar üzerinden.
     */
    public static function ozet(bool $canViewAll, string $userExt, ?string $startTs, ?string $endTs, string $statusFilter, string $agentFilter, string $searchQuery, string $deviceFilter = '', string $viewMode = 'grouped'): array
    {
        if ($viewMode === 'raw') {
            return static::ozetRaw($canViewAll, $userExt, $startTs, $endTs, $statusFilter, $agentFilter, $searchQuery, $deviceFilter);
        }
        return static::ozetGrouped($canViewAll, $userExt, $startTs, $endTs, $statusFilter, $agentFilter, $searchQuery, $deviceFilter);
    }

    /**
     * Ham (un-grouped) özet istatistikleri.
     */
    public static function ozetRaw(bool $canViewAll, string $userExt, ?string $startTs, ?string $endTs, string $statusFilter, string $agentFilter, string $searchQuery, string $deviceFilter = ''): array
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

    /**
     * Birleştirilmiş (Grouped by linkedid) gerçek çağrı istatistikleri.
     */
    public static function ozetGrouped(bool $canViewAll, string $userExt, ?string $startTs, ?string $endTs, string $statusFilter, string $agentFilter, string $searchQuery, string $deviceFilter = ''): array
    {
        $where = " WHERE 1=1";
        $params = [];

        if (!$canViewAll) {
            $where .= " AND (c.src = ? OR c.dst = ? OR c.accountcode = ? OR c.dstchannel LIKE ? OR c.channel LIKE ?)";
            $params[] = $userExt;
            $params[] = $userExt;
            $params[] = $userExt;
            $params[] = "PJSIP/{$userExt}-%";
            $params[] = "PJSIP/{$userExt}-%";
        }

        if ($startTs && $endTs) {
            $where .= " AND c.calldate >= ? AND c.calldate <= ?";
            $params[] = $startTs;
            $params[] = $endTs;
        }

        if (!empty($agentFilter)) {
            $where .= " AND (c.dst = ? OR c.src = ? OR c.accountcode = ? OR c.dstchannel LIKE ? OR c.channel LIKE ? OR c.dstchannel LIKE ?)";
            $params[] = $agentFilter;
            $params[] = $agentFilter;
            $params[] = $agentFilter;
            $params[] = "PJSIP/{$agentFilter}-%";
            $params[] = "PJSIP/{$agentFilter}-%";
            $params[] = "Local/{$agentFilter}@%";
        }

        if (!empty($deviceFilter)) {
            $where .= " AND (c.dstchannel LIKE ? OR c.channel LIKE ?)";
            $params[] = "%-{$deviceFilter}%";
            $params[] = "%-{$deviceFilter}%";
        }

        if (!empty($searchQuery)) {
            $where .= " AND (c.src LIKE ? OR c.dst LIKE ? OR c.uniqueid LIKE ? OR c.linkedid LIKE ? OR n.customer_name LIKE ? OR n.phone LIKE ? OR n.notes LIKE ?)";
            for ($i = 0; $i < 7; $i++) {
                $params[] = "%$searchQuery%";
            }
        }

        $having = "";
        if (!empty($statusFilter)) {
            if ($statusFilter === 'ANSWERED') {
                $having = " HAVING status = 'ANSWERED'";
            } elseif ($statusFilter === 'NO ANSWER') {
                $having = " HAVING status = 'NO ANSWER'";
            } elseif ($statusFilter === 'BUSY') {
                $having = " HAVING status = 'BUSY'";
            } elseif ($statusFilter === 'ABANDON') {
                $having = " HAVING status = 'ABANDON'";
            } elseif ($statusFilter === 'FAILED') {
                $having = " HAVING status NOT IN ('ANSWERED', 'NO ANSWER', 'BUSY', 'ABANDON')";
            }
        }

        $sql = "SELECT 
            COUNT(*) AS toplam,
            SUM(status = 'ANSWERED') AS cevaplanan,
            SUM(status IN ('NO ANSWER', 'ABANDON')) AS cevapsiz,
            SUM(status = 'BUSY') AS mesgul,
            SUM(status NOT IN ('ANSWERED', 'NO ANSWER', 'ABANDON', 'BUSY')) AS basarisiz,
            COALESCE(SUM(billsec), 0) AS toplam_sure,
            COALESCE(SUM(ring_sec), 0) AS toplam_calma,
            COALESCE(ROUND(AVG(CASE WHEN status = 'ANSWERED' THEN billsec END)), 0) AS ort_konusma,
            COALESCE(ROUND(AVG(ring_sec)), 0) AS ort_calma,
            SUM(has_rec) AS kayitli
        FROM (
            SELECT 
                coalesce(nullif(c.linkedid, ''), c.uniqueid) AS linkedid,
                CASE 
                    WHEN SUM(c.disposition = 'ANSWERED') > 0 THEN 'ANSWERED'
                    WHEN SUM(c.disposition = 'BUSY') > 0 THEN 'BUSY'
                    WHEN MAX(c.lastapp) = 'Queue' THEN 'ABANDON'
                    ELSE 'NO ANSWER'
                END AS status,
                COALESCE(
                    MAX(CASE WHEN c.disposition = 'ANSWERED' AND c.lastapp = 'Queue' THEN c.billsec END),
                    MAX(CASE WHEN c.disposition = 'ANSWERED' THEN c.billsec END),
                    0
                ) AS billsec,
                GREATEST(0, TIMESTAMPDIFF(SECOND, MIN(c.calldate), MAX(c.calldate + INTERVAL greatest(c.duration, c.billsec) SECOND)) - COALESCE(
                    MAX(CASE WHEN c.disposition = 'ANSWERED' AND c.lastapp = 'Queue' THEN c.billsec END),
                    MAX(CASE WHEN c.disposition = 'ANSWERED' THEN c.billsec END),
                    0
                )) AS ring_sec,
                MAX(c.userfield IS NOT NULL AND c.userfield != '') AS has_rec
            FROM asteriskcdr c
            LEFT JOIN callcenter_notes n ON (n.call_id = c.uniqueid OR n.call_id = c.linkedid)"
            . $where
            . " GROUP BY coalesce(nullif(c.linkedid, ''), c.uniqueid)"
            . $having
        . ") calls";

        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['toplam','cevaplanan','cevapsiz','mesgul','basarisiz','toplam_sure','toplam_calma','ort_konusma','ort_calma','kayitli'] as $k) {
            $r[$k] = intval($r[$k] ?? 0);
        }
        return $r;
    }
}
