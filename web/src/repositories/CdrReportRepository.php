<?php
require_once __DIR__ . '/../services/CdrCallAnalyzer.php';

class CdrReportRepository extends BaseRepository
{
    protected static string $table = 'cdrs';

    /** Records per page. */
    public const SAYFA_BOYUTU = 50;

    public static function agentsForFilter(): array
    {
        return static::db()->query("SELECT extension, full_name FROM sys_users WHERE extension IS NOT NULL AND extension != '' ORDER BY extension ASC")->fetchAll();
    }

    /**
     * Loads the user and DID maps into memory (for fast O(1) lookups).
     */
    protected static function getLookupMaps(): array
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
     * Builds the filter conditions and parameters (for raw mode).
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
     * Branches the search method by view mode.
     */
    public static function search(bool $canViewAll, string $userExt, ?string $startTs, ?string $endTs, string $statusFilter, string $agentFilter, string $searchQuery, int $sayfa = 1, int $boyut = self::SAYFA_BOYUTU, string $deviceFilter = '', string $viewMode = 'grouped', string $directionFilter = '', string $trunkFilter = ''): array
    {
        if ($viewMode === 'raw') {
            return static::searchRaw($canViewAll, $userExt, $startTs, $endTs, $statusFilter, $agentFilter, $searchQuery, $sayfa, $boyut, $deviceFilter);
        }
        return static::searchGrouped($canViewAll, $userExt, $startTs, $endTs, $statusFilter, $agentFilter, $searchQuery, $sayfa, $boyut, $deviceFilter, $directionFilter, $trunkFilter);
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
            // The call-center table has no channel data: only caller and agent are known.
            $r['flow'] = null;
        }
        return $rows;
    }

    /** Trunk names and titles (pbx_trunks) for the filter and for labelling channels. */
    public static function trunkTitles(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            try {
                foreach (static::db()->query("SELECT trunk_name, title FROM pbx_trunks ORDER BY title")->fetchAll(PDO::FETCH_ASSOC) as $t) {
                    $map[$t['trunk_name']] = $t['title'] !== '' ? $t['title'] : $t['trunk_name'];
                }
            } catch (\Throwable $e) {
                // no trunk table: channel names are shown as they are
            }
        }
        return $map;
    }

    /** Queue names as configured (pbx_queues.title) for the route column. */
    protected static function queueTitles(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            try {
                foreach (static::db()->query("SELECT queue_name, title FROM pbx_queues")->fetchAll(PDO::FETCH_ASSOC) as $q) {
                    if ($q['title'] !== '') {
                        $map[$q['queue_name']] = $q['title'];
                    }
                }
            } catch (\Throwable $e) {
                // keep technical names
            }
        }
        return $map;
    }

    public static function analyzer(): CdrCallAnalyzer
    {
        [$userMap] = static::getLookupMaps();
        return new CdrCallAnalyzer(static::trunkTitles(), $userMap);
    }

    /**
     * One row per call (legs grouped by linkedid), with what the direction
     * and trunk filters need. Used by both the list and the summary so they
     * always count the same calls.
     *
     * @return array{0: string, 1: array}
     */
    protected static function groupedCallsSql(bool $canViewAll, string $userExt, ?string $startTs, ?string $endTs, string $statusFilter, string $agentFilter, string $searchQuery, string $deviceFilter, string $directionFilter, string $trunkFilter): array
    {
        $where = " WHERE 1=1";
        $params = [];

        if (!$canViewAll) {
            $where .= " AND (c.src = ? OR c.dst = ? OR c.accountcode = ? OR c.dstchannel LIKE ? OR c.channel LIKE ?)";
            array_push($params, $userExt, $userExt, $userExt, "PJSIP/{$userExt}-%", "PJSIP/{$userExt}-%");
        }
        if ($startTs && $endTs) {
            $where .= " AND c.calldate >= ? AND c.calldate <= ?";
            array_push($params, $startTs, $endTs);
        }
        if (!empty($agentFilter)) {
            $where .= " AND (c.dst = ? OR c.src = ? OR c.accountcode = ? OR c.dstchannel LIKE ? OR c.channel LIKE ? OR c.dstchannel LIKE ?)";
            array_push($params, $agentFilter, $agentFilter, $agentFilter, "PJSIP/{$agentFilter}-%", "PJSIP/{$agentFilter}-%", "Local/{$agentFilter}@%");
        }
        if (!empty($deviceFilter)) {
            $where .= " AND (c.dstchannel LIKE ? OR c.channel LIKE ?)";
            array_push($params, "%-{$deviceFilter}%", "%-{$deviceFilter}%");
        }
        if (!empty($searchQuery)) {
            $where .= " AND (c.src LIKE ? OR c.dst LIKE ? OR c.did LIKE ? OR c.clid LIKE ? OR c.uniqueid LIKE ? OR c.linkedid LIKE ? OR n.customer_name LIKE ? OR n.phone LIKE ? OR n.notes LIKE ?)";
            for ($i = 0; $i < 9; $i++) {
                $params[] = "%$searchQuery%";
            }
        }

        // Extension vs trunk channels: see CdrCallAnalyzer::endpoint().
        $ext = "'" . CdrCallAnalyzer::SQL_EXT_CHANNEL . "'";
        $isTrunk = fn(string $col) => "({$col} LIKE 'PJSIP/%' AND {$col} NOT REGEXP {$ext})";
        $answeredBilled = "COALESCE(
                MAX(CASE WHEN c.disposition = 'ANSWERED' AND c.lastapp = 'Queue' THEN c.billsec END),
                MAX(CASE WHEN c.disposition = 'ANSWERED' THEN c.billsec END),
                0)";

        $inner = "SELECT
            coalesce(nullif(c.linkedid, ''), c.uniqueid) AS linkedid,
            MIN(c.calldate) AS start_time,
            GREATEST(0, TIMESTAMPDIFF(SECOND, MIN(c.calldate), MAX(c.calldate + INTERVAL greatest(c.duration, c.billsec) SECOND))) AS duration,
            COUNT(*) AS total_legs,
            COALESCE(
                MAX(CASE WHEN c.userfield IS NOT NULL AND c.userfield != '' THEN c.id END),
                MAX(CASE WHEN c.disposition = 'ANSWERED' THEN c.id END),
                MAX(c.id)
            ) AS id,
            SUBSTRING_INDEX(GROUP_CONCAT(c.src ORDER BY c.calldate, (c.channel LIKE 'Local/%'), c.id SEPARATOR '\\n'), '\\n', 1) AS caller_num,
            SUBSTRING_INDEX(GROUP_CONCAT(c.channel ORDER BY c.calldate, (c.channel LIKE 'Local/%'), c.id SEPARATOR '\\n'), '\\n', 1) AS first_channel,
            GROUP_CONCAT(c.dstchannel ORDER BY c.id SEPARATOR '\\n') AS dst_channels,
            MAX(c.lastapp IN ('Dial', 'Queue') AND " . $isTrunk('c.dstchannel') . ") AS out_trunk,
            MAX(c.disposition = 'ANSWERED' AND c.lastapp IN ('Dial', 'Queue')
                AND (c.dstchannel REGEXP {$ext} OR c.dstchannel REGEXP '^Local/[0-9]+@')) AS ext_answered,
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
            {$answeredBilled} AS billsec,
            MAX(CASE WHEN c.userfield IS NOT NULL AND c.userfield != '' THEN c.userfield END) AS recording_path,
            MAX(n.customer_name) AS note_customer_name,
            MAX(n.phone) AS note_phone,
            MAX(n.disposition) AS note_disposition,
            MAX(n.notes) AS note_text
        FROM asteriskcdr c
        LEFT JOIN callcenter_notes n ON (n.call_id = c.uniqueid OR n.call_id = c.linkedid)"
        . $where
        . " GROUP BY coalesce(nullif(c.linkedid, ''), c.uniqueid)";

        $inTrunk = $isTrunk('g.first_channel');
        $direction = "CASE
                WHEN {$inTrunk} AND g.out_trunk AND NOT g.ext_answered THEN '" . CdrCallAnalyzer::TRANSIT . "'
                WHEN {$inTrunk} THEN '" . CdrCallAnalyzer::INBOUND . "'
                WHEN g.out_trunk THEN '" . CdrCallAnalyzer::OUTBOUND . "'
                ELSE '" . CdrCallAnalyzer::INTERNAL . "'
            END";

        $filters = [];
        $outerParams = [];
        $statusSql = [
            'ANSWERED' => "g.status = 'ANSWERED'",
            'NO ANSWER' => "g.status = 'NO ANSWER'",
            'BUSY' => "g.status = 'BUSY'",
            'ABANDON' => "g.status = 'ABANDON'",
            'FAILED' => "g.status NOT IN ('ANSWERED', 'NO ANSWER', 'BUSY', 'ABANDON')",
        ];
        if (isset($statusSql[$statusFilter])) {
            $filters[] = $statusSql[$statusFilter];
        }
        if (in_array($directionFilter, [CdrCallAnalyzer::INBOUND, CdrCallAnalyzer::OUTBOUND, CdrCallAnalyzer::INTERNAL, CdrCallAnalyzer::TRANSIT], true)) {
            $filters[] = "{$direction} = ?";
            $outerParams[] = $directionFilter;
        }
        if ($trunkFilter !== '' && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $trunkFilter)) {
            $like = 'PJSIP/' . addcslashes($trunkFilter, '%_\\') . '-%';
            $filters[] = "(g.first_channel LIKE ? OR g.dst_channels LIKE ?)";
            array_push($outerParams, $like, '%' . $like);
        }

        $sql = "SELECT g.*, {$direction} AS direction,
                GREATEST(0, g.duration - g.billsec) AS ring_sec
            FROM ({$inner}) g"
            . ($filters ? ' WHERE ' . implode(' AND ', $filters) : '');

        return [$sql, array_merge($params, $outerParams)];
    }

    /**
     * Grouped call list (by linkedid).
     * 1 customer call = 1 row.
     */
    public static function searchGrouped(bool $canViewAll, string $userExt, ?string $startTs, ?string $endTs, string $statusFilter, string $agentFilter, string $searchQuery, int $sayfa = 1, int $boyut = self::SAYFA_BOYUTU, string $deviceFilter = '', string $directionFilter = '', string $trunkFilter = ''): array
    {
        [$userMap, $didMap] = static::getLookupMaps();
        [$sql, $params] = static::groupedCallsSql($canViewAll, $userExt, $startTs, $endTs, $statusFilter, $agentFilter, $searchQuery, $deviceFilter, $directionFilter, $trunkFilter);

        $sayfa = max(1, $sayfa);
        $boyut = max(1, $boyut);
        $offset = ($sayfa - 1) * $boyut;

        static::db()->exec('SET SESSION group_concat_max_len = 65535');
        $stmt = static::db()->prepare($sql . " ORDER BY g.start_time DESC LIMIT " . $boyut . " OFFSET " . $offset);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [];
        }

        $linkedIds = array_unique(array_filter(array_column($rows, 'linkedid')));
        $legsByLinkedId = static::fetchLegsForLinkedIds($linkedIds);
        $analyzer = static::analyzer();

        foreach ($rows as &$r) {
            $lid = $r['linkedid'];
            $r['call_id'] = $lid;
            $r['duration'] = max(0, (int)($r['duration'] ?? 0));
            $r['billsec'] = max(0, (int)($r['billsec'] ?? 0));
            $r['ring_sec'] = max(0, $r['duration'] - $r['billsec']);
            $r['answer_time'] = date('Y-m-d H:i:s', strtotime($r['start_time']) + $r['ring_sec']);

            $r['legs'] = $legsByLinkedId[$lid] ?? [];
            if (empty($r['total_legs']) || count($r['legs']) > (int)$r['total_legs']) {
                $r['total_legs'] = count($r['legs']);
            }

            $flow = $r['legs'] ? $analyzer->analyze($r['legs']) : null;
            $r['flow'] = $flow;
            if ($flow) {
                // Only an AiPBX extension; a number behind an outgoing trunk is not one.
                $r['agent_extension'] = $flow['answered_ext'];
            }
            $ext = $r['agent_extension'] ?? '';
            $r['agent_name'] = !empty($ext) && isset($userMap[$ext]) ? $userMap[$ext] : '';
            $r['device_type'] = $flow['answered_device'] ?? '';

            // Route: the queue, or the DID's title, of what was dialed.
            $did = $flow['dialed_number'] ?? ($r['did'] ?? '');
            $q = $r['queue_name'] ?? '';
            if ($q !== '') {
                $r['route'] = static::queueTitles()[$q] ?? $q;
            } elseif ($did !== '' && isset($didMap[$did])) {
                $r['route'] = $didMap[$did];
            } else {
                $r['route'] = '';
            }
            $r['queue_name'] = $r['route'];
        }
        unset($r);

        return $rows;
    }

    /**
     * Fetches all sub-legs of the given linkedid list and enriches them with
     * human-friendly descriptions.
     */
    public static function fetchLegsForLinkedIds(array $linkedIds): array
    {
        if (empty($linkedIds)) {
            return [];
        }

        [$userMap, $didMap] = static::getLookupMaps();

        $in = implode(',', array_fill(0, count($linkedIds), '?'));
        $sql = "SELECT c.id, c.uniqueid, coalesce(nullif(c.linkedid, ''), c.uniqueid) AS linkedid,
                       c.calldate, c.clid, c.src, c.dst, c.did, c.dcontext, c.channel, c.dstchannel, c.accountcode,
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
                ORDER BY c.calldate ASC, (c.channel LIKE 'Local/%') ASC, c.id ASC";

        $stmt = static::db()->prepare($sql);
        $stmt->execute(array_merge($linkedIds, $linkedIds));
        $allLegs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $analyzer = static::analyzer();
        $grouped = [];
        foreach ($allLegs as $leg) {
            $lid = $leg['linkedid'];
            $dst = $leg['dst'] ?? '';
            $leg['agent_name'] = !empty($dst) && isset($userMap[$dst]) ? $userMap[$dst] : '';
            $leg['leg_info'] = static::formatLegDescription($leg, $analyzer, $userMap);
            $grouped[$lid][] = $leg;
        }

        return $grouped;
    }

    /**
     * One line describing a leg in the call journey: who was reached, over
     * which trunk, and how it ended.
     *
     * @param array<string, string> $userMap extension => name
     */
    public static function formatLegDescription(array $leg, ?CdrCallAnalyzer $analyzer = null, array $userMap = []): array
    {
        $analyzer ??= new CdrCallAnalyzer();
        $app = $leg['lastapp'] ?? '';
        $disp = $leg['disposition'] ?? '';
        $dur = (int)($leg['duration'] ?? 0);
        $bill = (int)($leg['billsec'] ?? 0);
        $lastdata = (string)($leg['lastdata'] ?? '');

        $badge = 'badge-secondary';
        $badgeText = $disp;
        if ($disp === 'ANSWERED') {
            $badge = 'badge-success';
            $badgeText = t('cdr_reports.status_answered_label');
        } elseif (in_array($disp, ['NO ANSWER', 'CANCEL', 'NOANSWER'], true)) {
            $badge = 'badge-warning';
            $badgeText = t('cdr_reports.status_no_answer_label');
        } elseif ($disp === 'BUSY') {
            $badge = 'badge-info';
            $badgeText = t('cdr_reports.status_busy_label');
        } elseif ($disp === 'ABANDON' || $disp === 'FAILED') {
            $badge = 'badge-danger';
            $badgeText = ($disp === 'ABANDON') ? t('cdr_reports.status_abandon_label') : t('cdr_reports.status_failed_label');
        }

        $target = $analyzer->endpoint($leg['dstchannel'] ?? '');
        if ($target['kind'] === 'trunk') {
            $number = preg_match('#^(?:PJSIP|SIP|IAX2)/([^@/,&]+)@#', $lastdata, $d) ? $d[1] : (string)($leg['dst'] ?? '');
            $targetLabel = $analyzer->trunkTitle($target['id']) . ' → ' . $number;
        } elseif ($target['kind'] === 'ext') {
            $name = $userMap[$target['id']] ?? '';
            $targetLabel = $target['id'] . ($name !== '' ? " ({$name})" : '') . ($target['device'] !== '' ? ' [' . strtoupper($target['device']) . ']' : '');
        } else {
            $targetLabel = (string)($target['id'] !== '' ? $target['id'] : ($leg['dst'] ?? ''));
        }

        $icon = 'fa-phone';
        if ($app === 'Queue') {
            $icon = 'fa-users';
            $qName = explode(',', $lastdata)[0] ?: t('cdr_reports.queue');
            if ($disp === 'ANSWERED') {
                $title = sprintf(t('cdr_reports.leg_queue_answered'), $qName, $targetLabel);
                $detail = sprintf(t('cdr_reports.leg_talk_detail'), $bill);
            } else {
                $title = sprintf(t('cdr_reports.leg_queue_wait'), $qName, $targetLabel);
                $detail = sprintf(t('cdr_reports.leg_wait_detail'), $dur);
            }
        } elseif ($app === 'Dial') {
            $icon = $target['kind'] === 'trunk' ? 'fa-arrow-right-from-bracket' : 'fa-phone-volume';
            if ($disp === 'ANSWERED') {
                $title = sprintf(t($target['kind'] === 'trunk' ? 'cdr_reports.leg_trunk_answered' : 'cdr_reports.leg_answered'), $targetLabel);
                $detail = $bill > 0 ? sprintf(t('cdr_reports.leg_talk_detail'), $bill) : t('cdr_reports.leg_connected');
            } elseif (in_array($disp, ['NO ANSWER', 'CANCEL', 'NOANSWER'], true)) {
                if ($dur > 0) {
                    $title = sprintf(t($target['kind'] === 'trunk' ? 'cdr_reports.leg_trunk_tried' : 'cdr_reports.leg_rang'), $targetLabel);
                    $detail = sprintf(t('cdr_reports.leg_rang_detail'), $dur);
                } else {
                    $title = sprintf(t('cdr_reports.leg_parallel'), $targetLabel);
                    $detail = t('cdr_reports.leg_parallel_detail');
                }
            } else {
                $title = sprintf(t('cdr_reports.leg_try'), $targetLabel);
                $detail = sprintf(t('cdr_reports.leg_result'), $disp);
            }
        } elseif ($app === 'ReceiveFAX' || $app === 'SendFAX') {
            $icon = 'fa-fax';
            $title = t($app === 'ReceiveFAX' ? 'cdr_reports.leg_fax_in' : 'cdr_reports.leg_fax_out');
            $detail = sprintf(t('cdr_reports.leg_result'), $disp);
        } elseif ($app === 'Hangup') {
            $icon = 'fa-phone-slash';
            $title = t('cdr_reports.leg_hangup');
            $detail = t('cdr_reports.leg_hangup_detail');
        } else {
            $title = $app !== '' ? sprintf(t('cdr_reports.leg_app'), $app, (string)($leg['dst'] ?? '')) : t('cdr_reports.leg_step');
            $detail = sprintf(t('cdr_reports.leg_app_detail'), $dur, $bill);
        }

        // A leg started on someone else's behalf (blind/attended transfer):
        // accountcode carries the extension that transferred the call.
        $by = (string)($leg['accountcode'] ?? '');
        if ($by !== '' && $by !== ($leg['src'] ?? '') && isset($userMap[$by])) {
            $detail .= ' ' . sprintf(t('cdr_reports.leg_transferred_by'), "{$by} ({$userMap[$by]})");
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
     * Total record count and summary statistics — over ALL matching records.
     */
    public static function ozet(bool $canViewAll, string $userExt, ?string $startTs, ?string $endTs, string $statusFilter, string $agentFilter, string $searchQuery, string $deviceFilter = '', string $viewMode = 'grouped', string $directionFilter = '', string $trunkFilter = ''): array
    {
        if ($viewMode === 'raw') {
            return static::ozetRaw($canViewAll, $userExt, $startTs, $endTs, $statusFilter, $agentFilter, $searchQuery, $deviceFilter);
        }
        return static::ozetGrouped($canViewAll, $userExt, $startTs, $endTs, $statusFilter, $agentFilter, $searchQuery, $deviceFilter, $directionFilter, $trunkFilter);
    }

    /**
     * Raw (ungrouped) summary statistics.
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
     * Real call statistics, grouped by linkedid.
     */
    public static function ozetGrouped(bool $canViewAll, string $userExt, ?string $startTs, ?string $endTs, string $statusFilter, string $agentFilter, string $searchQuery, string $deviceFilter = '', string $directionFilter = '', string $trunkFilter = ''): array
    {
        [$sql, $params] = static::groupedCallsSql($canViewAll, $userExt, $startTs, $endTs, $statusFilter, $agentFilter, $searchQuery, $deviceFilter, $directionFilter, $trunkFilter);

        static::db()->exec('SET SESSION group_concat_max_len = 65535');
        $stmt = static::db()->prepare("SELECT
            COUNT(*) AS toplam,
            SUM(status = 'ANSWERED') AS cevaplanan,
            SUM(status IN ('NO ANSWER', 'ABANDON')) AS cevapsiz,
            SUM(status = 'BUSY') AS mesgul,
            SUM(status NOT IN ('ANSWERED', 'NO ANSWER', 'ABANDON', 'BUSY')) AS basarisiz,
            COALESCE(SUM(billsec), 0) AS toplam_sure,
            COALESCE(SUM(ring_sec), 0) AS toplam_calma,
            COALESCE(ROUND(AVG(CASE WHEN status = 'ANSWERED' THEN billsec END)), 0) AS ort_konusma,
            COALESCE(ROUND(AVG(ring_sec)), 0) AS ort_calma,
            SUM(recording_path IS NOT NULL) AS kayitli,
            SUM(direction = '" . CdrCallAnalyzer::INBOUND . "') AS gelen,
            SUM(direction = '" . CdrCallAnalyzer::OUTBOUND . "') AS giden,
            SUM(direction = '" . CdrCallAnalyzer::INTERNAL . "') AS dahili,
            SUM(direction = '" . CdrCallAnalyzer::TRANSIT . "') AS transit
        FROM ({$sql}) calls");
        $stmt->execute($params);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['toplam','cevaplanan','cevapsiz','mesgul','basarisiz','toplam_sure','toplam_calma','ort_konusma','ort_calma','kayitli','gelen','giden','dahili','transit'] as $k) {
            $r[$k] = intval($r[$k] ?? 0);
        }
        return $r;
    }
}
