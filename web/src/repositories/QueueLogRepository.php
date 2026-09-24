<?php

class QueueLogRepository extends BaseRepository
{
    protected static string $table = 'cc_queue_logs';

    public static function agentMap(): array
    {
        return static::db()->query("SELECT extension, full_name FROM sys_users WHERE extension IS NOT NULL AND extension != ''")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function syncLatest(): void
    {
        @exec(escapeshellarg(SYNC_QUEUE_LOGS_SCRIPT) . ' >/dev/null 2>&1');
    }

    /**
     * Arama metodunu görünüm moduna göre dallandırır:
     * - 'grouped': Çağrı bacaklarını call_id'ye göre birleştirip çağrı yolculuğu (journey) oluşturur.
     * - 'raw': Ham Asterisk event loglarını satır satır listeler.
     */
    public static function searchAndParse(int $startTs, int $endTs, string $eventFilter, string $agentFilter, string $searchQuery, array $agentMap, string $viewMode = 'grouped'): array
    {
        if ($viewMode === 'raw') {
            return static::searchRaw($startTs, $endTs, $eventFilter, $agentFilter, $searchQuery, $agentMap);
        }
        return static::searchGrouped($startTs, $endTs, $eventFilter, $agentFilter, $searchQuery, $agentMap);
    }

    /**
     * Birleştirilmiş (Grouped by call_id) Çağrı Listesi.
     * 1 Kuyruk Çağrısı = 1 Satır (Açılabilir Çağrı Yolculuğu / Timeline ile).
     */
    public static function searchGrouped(int $startTs, int $endTs, string $eventFilter, string $agentFilter, string $searchQuery, array $agentMap): array
    {
        $db = static::db();

        $where = " WHERE call_id != 'NONE' AND call_id != ''";
        $params = [];

        if ($startTs > 0) {
            $where .= " AND time_id >= ?";
            $params[] = $startTs;
        }
        if ($endTs > 0) {
            $where .= " AND time_id <= ?";
            $params[] = $endTs;
        }
        if (!empty($eventFilter)) {
            $where .= " AND event = ?";
            $params[] = $eventFilter;
        }
        if (!empty($agentFilter)) {
            $where .= " AND (agent = ? OR agent LIKE ?)";
            $params[] = $agentFilter;
            $params[] = "%$agentFilter%";
        }
        if (!empty($searchQuery)) {
            $where .= " AND (call_id LIKE ? OR queue_name LIKE ? OR agent LIKE ? OR data1 LIKE ? OR data2 LIKE ?)";
            for ($i = 0; $i < 5; $i++) {
                $params[] = "%$searchQuery%";
            }
        }

        // Filtrelere uyan en güncel çağrıları (call_id) belirleyelim
        $sqlCalls = "SELECT call_id, MIN(time_id) AS first_ts, MAX(time_id) AS last_ts, MAX(queue_name) AS q_name 
                     FROM cc_queue_logs"
                  . $where
                  . " GROUP BY call_id ORDER BY first_ts DESC LIMIT 200";

        $stmtCalls = $db->prepare($sqlCalls);
        $stmtCalls->execute($params);
        $callRows = $stmtCalls->fetchAll(PDO::FETCH_ASSOC);

        // İstatistikler (Filtrelenen aralıktaki tüm satırlar üzerinden)
        $stats = static::calculateStats($startTs, $endTs, $eventFilter, $agentFilter, $searchQuery);

        if (empty($callRows)) {
            return array_merge(['view_mode' => 'grouped', 'logs' => []], $stats);
        }

        $callIds = array_column($callRows, 'call_id');
        $placeholders = implode(',', array_fill(0, count($callIds), '?'));

        // Seçilen çağrılara ait TÜM olayları çekelim (kronolojik sıra)
        $sqlEvents = "SELECT id, time_id, created_at, call_id, queue_name, agent, event, data1, data2, data3, data4, data5 
                      FROM cc_queue_logs 
                      WHERE call_id IN ($placeholders) 
                      ORDER BY time_id ASC, id ASC";
        $stmtEvents = $db->prepare($sqlEvents);
        $stmtEvents->execute($callIds);
        $allEvents = $stmtEvents->fetchAll(PDO::FETCH_ASSOC);

        $eventsByCall = [];
        foreach ($allEvents as $ev) {
            $eventsByCall[$ev['call_id']][] = $ev;
        }

        // Asterisk CDR tablosundan ses kaydı (userfield) eşleştirmesi
        $recordingsMap = [];
        try {
            $sqlCdr = "SELECT uniqueid, linkedid, userfield, id FROM asteriskcdr 
                       WHERE (uniqueid IN ($placeholders) OR linkedid IN ($placeholders)) 
                         AND userfield IS NOT NULL AND userfield != ''";
            $stmtCdr = $db->prepare($sqlCdr);
            $stmtCdr->execute(array_merge($callIds, $callIds));
            $cdrRows = $stmtCdr->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cdrRows as $cr) {
                if (!empty($cr['uniqueid'])) $recordingsMap[$cr['uniqueid']] = $cr;
                if (!empty($cr['linkedid'])) $recordingsMap[$cr['linkedid']] = $cr;
            }
        } catch (\Throwable $e) {
            // fallback
        }

        $groupedCalls = [];
        foreach ($callRows as $cRow) {
            $cid = $cRow['call_id'];
            $evList = $eventsByCall[$cid] ?? [];
            if (empty($evList)) continue;

            $queueName = $cRow['q_name'] ?? '';
            $callerNum = '';
            $status = 'INQUEUE';
            $answeringAgent = '';
            $answeringAgentExt = '';
            $answeringAgentName = '';
            $holdTime = 0;
            $talkTime = 0;
            $ringNoAnswerCount = 0;

            $journeySteps = [];
            foreach ($evList as $ev) {
                $evName = $ev['event'];
                $evTs = (int)$ev['time_id'];
                $evAgent = $ev['agent'] ?? '';
                if ((empty($queueName) || $queueName === 'NONE') && !empty($ev['queue_name']) && $ev['queue_name'] !== 'NONE') {
                    $queueName = $ev['queue_name'];
                }

                // Temsilci dahilisini ayıkla (PJSIP/1001 veya 1001)
                $agentExt = '';
                if (preg_match('/PJSIP\/([0-9]+)/i', $evAgent, $m)) {
                    $agentExt = $m[1];
                } else {
                    $agentExt = preg_replace('/[^0-9]/', '', $evAgent);
                }
                $agentName = $agentMap[$agentExt] ?? ($evAgent !== 'NONE' ? $evAgent : '');

                // Arayan numara tespiti
                if ($evName === 'ENTERQUEUE') {
                    if (!empty($ev['data2'])) $callerNum = $ev['data2'];
                }

                if ($evName === 'RINGNOANSWER') {
                    $ringNoAnswerCount++;
                }

                if ($evName === 'CONNECT') {
                    $status = 'CONNECTED';
                    $holdTime = (int)($ev['data1'] ?? 0);
                    $answeringAgent = $evAgent;
                    $answeringAgentExt = $agentExt;
                    $answeringAgentName = $agentName;
                }

                if ($evName === 'COMPLETECALLER' || $evName === 'COMPLETEAGENT') {
                    $status = 'CONNECTED';
                    $talkTime = (int)($ev['data2'] ?? 0);
                    if ($holdTime <= 0 && !empty($ev['data1'])) {
                        $holdTime = (int)$ev['data1'];
                    }
                    if (empty($answeringAgent)) {
                        $answeringAgent = $evAgent;
                        $answeringAgentExt = $agentExt;
                        $answeringAgentName = $agentName;
                    }
                }

                if ($evName === 'ABANDON') {
                    if ($status !== 'CONNECTED') {
                        $status = 'ABANDON';
                        $holdTime = (int)($ev['data3'] ?? 0);
                    }
                }

                if ($evName === 'EXITWITHTIMEOUT') {
                    if ($status !== 'CONNECTED') {
                        $status = 'TIMEOUT';
                        $holdTime = (int)($ev['data3'] ?? 0);
                    }
                }

                if ($evName === 'EXITEMPTY') {
                    if ($status !== 'CONNECTED') $status = 'EXITEMPTY';
                }

                if ($evName === 'EXITWITHKEY') {
                    if ($status !== 'CONNECTED') $status = 'KEY';
                }

                // Adım detayını biçimlendir
                $stepInfo = static::formatStepInfo($ev, $agentExt, $agentName);
                $journeySteps[] = [
                    'id' => $ev['id'],
                    'timestamp' => $evTs,
                    'time' => date('H:i:s', $evTs),
                    'datetime' => date('d.m.Y H:i:s', $evTs),
                    'event' => $evName,
                    'agent' => $evAgent,
                    'agent_ext' => $agentExt,
                    'agent_name' => $agentName,
                    'icon' => $stepInfo['icon'],
                    'badge' => $stepInfo['badge'],
                    'node_color' => $stepInfo['node_color'],
                    'title' => $stepInfo['title'],
                    'detail' => $stepInfo['detail'],
                    'data1' => $ev['data1'],
                    'data2' => $ev['data2'],
                    'data3' => $ev['data3'],
                    'data4' => $ev['data4'],
                ];
            }

            // Durum etiketi ve rozeti
            $statusBadge = static::getStatusBadge($status);

            // Ses kaydı kontrolü
            $rec = $recordingsMap[$cid] ?? null;
            $recordingPath = '';
            $cdrId = 0;
            if ($rec && !empty($rec['userfield'])) {
                $recordingPath = $rec['userfield'];
                $cdrId = (int)$rec['id'];
            }

            $groupedCalls[] = [
                'call_id' => $cid,
                'start_ts' => (int)$cRow['first_ts'],
                'datetime' => date('d.m.Y H:i:s', (int)$cRow['first_ts']),
                'queue_name' => $queueName ?: '-',
                'caller_num' => $callerNum ?: '-',
                'status' => $status,
                'status_label' => $statusBadge['label'],
                'status_badge' => $statusBadge['class'],
                'agent_ext' => $answeringAgentExt,
                'agent_name' => $answeringAgentName,
                'agent' => $answeringAgent,
                'hold_sec' => $holdTime,
                'talk_sec' => $talkTime,
                'total_sec' => $holdTime + $talkTime,
                'ring_no_answer_count' => $ringNoAnswerCount,
                'steps_count' => count($journeySteps),
                'steps' => $journeySteps,
                'recording_path' => $recordingPath,
                'has_recording' => (!empty($recordingPath) && file_exists($recordingPath)),
                'cdr_id' => $cdrId,
            ];
        }

        return array_merge([
            'view_mode' => 'grouped',
            'logs' => $groupedCalls,
        ], $stats);
    }

    /**
     * Ham (un-grouped) Asterisk Queue Log listesi.
     */
    public static function searchRaw(int $startTs, int $endTs, string $eventFilter, string $agentFilter, string $searchQuery, array $agentMap): array
    {
        $sql = "SELECT id, time_id, created_at, call_id, queue_name, agent, event, data1, data2, data3, data4 FROM cc_queue_logs WHERE 1=1";
        $params = [];

        if ($startTs > 0) {
            $sql .= " AND time_id >= ?";
            $params[] = $startTs;
        }
        if ($endTs > 0) {
            $sql .= " AND time_id <= ?";
            $params[] = $endTs;
        }
        if (!empty($eventFilter)) {
            $sql .= " AND event = ?";
            $params[] = $eventFilter;
        }
        if (!empty($agentFilter)) {
            $sql .= " AND (agent = ? OR agent LIKE ?)";
            $params[] = $agentFilter;
            $params[] = "%$agentFilter%";
        }
        if (!empty($searchQuery)) {
            $sql .= " AND (call_id LIKE ? OR queue_name LIKE ? OR agent LIKE ? OR data1 LIKE ? OR data2 LIKE ?)";
            for ($i = 0; $i < 5; $i++) {
                $params[] = "%$searchQuery%";
            }
        }

        $sql .= " ORDER BY time_id DESC, id DESC LIMIT 500";

        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $parsed_logs = [];
        foreach ($rows as $row) {
            $ts = intval($row['time_id']);
            $agent = $row['agent'] ?? '';

            $agent_ext = '';
            if (preg_match('/PJSIP\/([0-9]+)/i', $agent, $m)) {
                $agent_ext = $m[1];
            } else {
                $agent_ext = preg_replace('/[^0-9]/', '', $agent);
            }

            $parsed_logs[] = [
                'id' => $row['id'],
                'timestamp' => $ts,
                'datetime' => date('d.m.Y H:i:s', $ts),
                'call_id' => $row['call_id'] ?? '',
                'queue_name' => $row['queue_name'] ?? '',
                'agent' => $agent,
                'agent_ext' => $agent_ext,
                'agent_name' => $agentMap[$agent_ext] ?? ($agent !== 'NONE' ? $agent : '-'),
                'event' => $row['event'] ?? '',
                'data1' => $row['data1'] ?? '',
                'data2' => $row['data2'] ?? '',
                'data3' => $row['data3'] ?? '',
                'data4' => $row['data4'] ?? '',
            ];
        }

        $stats = static::calculateStats($startTs, $endTs, $eventFilter, $agentFilter, $searchQuery);

        return array_merge([
            'view_mode' => 'raw',
            'logs' => $parsed_logs,
        ], $stats);
    }

    /**
     * İstatistik sayaçlarını hızlı tek bir SQL sorgusuyla hesaplar.
     */
    private static function calculateStats(int $startTs, int $endTs, string $eventFilter, string $agentFilter, string $searchQuery): array
    {
        $sql = "SELECT 
            SUM(event = 'ENTERQUEUE') AS total_enter,
            SUM(event = 'CONNECT') AS connected,
            SUM(event = 'ABANDON') AS abandon,
            SUM(event = 'RINGNOANSWER') AS ring_no_answer,
            SUM(CASE WHEN event = 'CONNECT' THEN CAST(data1 AS UNSIGNED) ELSE 0 END) AS total_holdtime,
            SUM(CASE WHEN event IN ('COMPLETECALLER', 'COMPLETEAGENT') THEN CAST(data2 AS UNSIGNED) ELSE 0 END) AS total_talktime
            FROM cc_queue_logs WHERE 1=1";

        $params = [];
        if ($startTs > 0) {
            $sql .= " AND time_id >= ?";
            $params[] = $startTs;
        }
        if ($endTs > 0) {
            $sql .= " AND time_id <= ?";
            $params[] = $endTs;
        }
        if (!empty($eventFilter)) {
            $sql .= " AND event = ?";
            $params[] = $eventFilter;
        }
        if (!empty($agentFilter)) {
            $sql .= " AND (agent = ? OR agent LIKE ?)";
            $params[] = $agentFilter;
            $params[] = "%$agentFilter%";
        }
        if (!empty($searchQuery)) {
            $sql .= " AND (call_id LIKE ? OR queue_name LIKE ? OR agent LIKE ? OR data1 LIKE ? OR data2 LIKE ?)";
            for ($i = 0; $i < 5; $i++) {
                $params[] = "%$searchQuery%";
            }
        }

        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $totalEnter = (int)($row['total_enter'] ?? 0);
        $connected = (int)($row['connected'] ?? 0);
        $abandon = (int)($row['abandon'] ?? 0);
        $ringNoAnswer = (int)($row['ring_no_answer'] ?? 0);
        $totalHold = (int)($row['total_holdtime'] ?? 0);
        $totalTalk = (int)($row['total_talktime'] ?? 0);

        $avgHold = $connected > 0 ? round($totalHold / $connected, 1) : 0;
        $avgTalk = $connected > 0 ? round($totalTalk / $connected, 1) : 0;

        return [
            'stat_total_enter' => $totalEnter,
            'stat_connected' => $connected,
            'stat_abandon' => $abandon,
            'stat_ring_no_answer' => $ringNoAnswer,
            'avg_holdtime' => $avgHold,
            'avg_talktime' => $avgTalk,
        ];
    }

    /**
     * Çağrı yolculuğundaki her adımı açıklayıcı görsel öğelerle biçimlendirir.
     */
    public static function formatStepInfo(array $ev, string $agentExt, string $agentName): array
    {
        $event = $ev['event'] ?? '';
        $d1 = htmlspecialchars($ev['data1'] ?? '');
        $d2 = htmlspecialchars($ev['data2'] ?? '');
        $d3 = htmlspecialchars($ev['data3'] ?? '');
        $d4 = htmlspecialchars($ev['data4'] ?? '');

        $agentDisplay = !empty($agentExt) 
            ? (!empty($agentName) ? "{$agentExt} ({$agentName})" : $agentExt) 
            : (($ev['agent'] ?? '') !== 'NONE' ? htmlspecialchars($ev['agent'] ?? '') : '');

        switch ($event) {
            case 'ENTERQUEUE':
                return [
                    'icon' => 'fa-sign-in-alt',
                    'badge' => 'badge-info',
                    'node_color' => 'var(--primary)',
                    'title' => t('queue_logs.step_enterqueue', 'Kuyruğa Girdi'),
                    'detail' => (!empty($d2) ? t('queue_logs.detail_caller_number', 'Arayan Numara') . ": <strong>{$d2}</strong>" : '')
                             . (!empty($d3) ? " • " . t('queue_logs.detail_queue_pos', 'Sıra') . ": <strong>#{$d3}</strong>" : ''),
                ];
            case 'RINGNOANSWER':
                $ringSec = round((float)$d1 / 1000, 1);
                if ($ringSec < 1 && (int)$d1 > 0) $ringSec = (int)$d1;
                return [
                    'icon' => 'fa-bell-slash',
                    'badge' => 'badge-warning',
                    'node_color' => 'var(--warning)',
                    'title' => t('queue_logs.step_ringnoanswer', 'Temsilciye Çaldırıldı (Cevapsız)'),
                    'detail' => (!empty($agentDisplay) ? t('queue_logs.col_agent', 'Temsilci') . ": <strong>{$agentDisplay}</strong>" : '')
                             . ($ringSec > 0 ? " • " . sprintf(t('queue_logs.detail_ring_sec', '%s sn çaldı, açılmadı'), $ringSec) : ''),
                ];
            case 'CONNECT':
                return [
                    'icon' => 'fa-headset',
                    'badge' => 'badge-success',
                    'node_color' => 'var(--success)',
                    'title' => t('queue_logs.step_connect', 'Temsilci Yanıtladı'),
                    'detail' => (!empty($agentDisplay) ? t('queue_logs.col_agent', 'Temsilci') . ": <strong>{$agentDisplay}</strong>" : '')
                             . (!empty($d1) ? " • " . t('queue_logs.detail_wait_time', 'Bekleme') . ": <strong>{$d1} sn</strong>" : '')
                             . (!empty($d3) ? " • " . t('queue_logs.detail_ring', 'Çaldırma') . ": {$d3} sn" : ''),
                ];
            case 'COMPLETECALLER':
                return [
                    'icon' => 'fa-phone-slash',
                    'badge' => 'badge-success',
                    'node_color' => 'var(--success)',
                    'title' => t('queue_logs.step_completecaller', 'Arayan Kapattı'),
                    'detail' => (!empty($d2) ? t('queue_logs.detail_talk_time', 'Konuşma') . ": <strong>{$d2} sn</strong>" : '')
                             . (!empty($d1) ? " • " . t('queue_logs.detail_wait', 'Bekleme') . ": {$d1} sn" : '')
                             . (!empty($agentDisplay) ? " • " . t('queue_logs.col_agent', 'Temsilci') . ": {$agentDisplay}" : ''),
                ];
            case 'COMPLETEAGENT':
                return [
                    'icon' => 'fa-user-check',
                    'badge' => 'badge-success',
                    'node_color' => 'var(--success)',
                    'title' => t('queue_logs.step_completeagent', 'Temsilci Kapattı'),
                    'detail' => (!empty($d2) ? t('queue_logs.detail_talk_time', 'Konuşma') . ": <strong>{$d2} sn</strong>" : '')
                             . (!empty($d1) ? " • " . t('queue_logs.detail_wait', 'Bekleme') . ": {$d1} sn" : '')
                             . (!empty($agentDisplay) ? " • " . t('queue_logs.col_agent', 'Temsilci') . ": {$agentDisplay}" : ''),
                ];
            case 'ABANDON':
                return [
                    'icon' => 'fa-phone-slash',
                    'badge' => 'badge-danger',
                    'node_color' => 'var(--danger)',
                    'title' => t('queue_logs.step_abandon', 'Arayan Terk Etti'),
                    'detail' => (!empty($d3) ? t('queue_logs.detail_wait_time', 'Bekleme') . ": <strong>{$d3} sn</strong>" : '')
                             . (!empty($d1) ? " • " . t('queue_logs.detail_abandoned_pos', 'Terk Edilen Sıra') . ": #{$d1}" : ''),
                ];
            case 'EXITWITHTIMEOUT':
                return [
                    'icon' => 'fa-hourglass-end',
                    'badge' => 'badge-warning',
                    'node_color' => 'var(--warning)',
                    'title' => t('queue_logs.step_timeout', 'Zaman Aşımı ile Ayrıldı'),
                    'detail' => (!empty($d3) ? t('queue_logs.detail_wait_time', 'Bekleme') . ": <strong>{$d3} sn</strong>" : '')
                             . (!empty($d1) ? " • " . t('queue_logs.detail_queue_pos', 'Sıra') . ": #{$d1}" : ''),
                ];
            case 'EXITEMPTY':
                return [
                    'icon' => 'fa-users-slash',
                    'badge' => 'badge-secondary',
                    'node_color' => 'var(--text-muted)',
                    'title' => t('queue_logs.step_empty', 'Kuyrukta Temsilci Yoktu'),
                    'detail' => t('queue_logs.detail_empty_desc', 'Uygun temsilci bulunamadığı için çağrı kuyruktan ayrıldı.'),
                ];
            case 'EXITWITHKEY':
                return [
                    'icon' => 'fa-keyboard',
                    'badge' => 'badge-info',
                    'node_color' => 'var(--info)',
                    'title' => t('queue_logs.step_key', 'Tuşlama Yaparak Ayrıldı'),
                    'detail' => t('queue_logs.detail_pressed_key', 'Tuşlanan') . ": <strong>{$d1}</strong> • " . t('queue_logs.detail_queue_pos', 'Sıra') . ": #{$d2}",
                ];
            case 'BLINDTRANSFER':
            case 'ATTENDEDTRANSFER':
                return [
                    'icon' => 'fa-random',
                    'badge' => 'badge-info',
                    'node_color' => 'var(--info)',
                    'title' => t('queue_logs.step_transfer', 'Çağrı Transfer Edildi'),
                    'detail' => "{$d1} → {$d2}",
                ];
            default:
                return [
                    'icon' => 'fa-info-circle',
                    'badge' => 'badge-secondary',
                    'node_color' => 'var(--text-muted)',
                    'title' => htmlspecialchars($event),
                    'detail' => trim("{$d1} {$d2} {$d3} {$d4}"),
                ];
        }
    }

    /**
     * Çağrı durum rozetini belirler.
     */
    public static function getStatusBadge(string $status): array
    {
        switch ($status) {
            case 'CONNECTED':
                return ['label' => t('queue_logs.status_connected', 'Bağlandı'), 'class' => 'badge-success'];
            case 'ABANDON':
                return ['label' => t('queue_logs.status_abandon', 'Terk Edildi'), 'class' => 'badge-danger'];
            case 'TIMEOUT':
                return ['label' => t('queue_logs.status_timeout', 'Zaman Aşımı'), 'class' => 'badge-warning'];
            case 'EXITEMPTY':
                return ['label' => t('queue_logs.status_empty', 'Kuyruk Boş'), 'class' => 'badge-secondary'];
            case 'KEY':
                return ['label' => t('queue_logs.status_key', 'Tuşlama ile Çıkış'), 'class' => 'badge-info'];
            default:
                return ['label' => t('queue_logs.status_inqueue', 'Kuyrukta'), 'class' => 'badge-warning'];
        }
    }
}
