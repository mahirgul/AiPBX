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
     * cc_queue_logs'u filtreler, ham satırları arayüz için ayrıştırır (agent
     * extension çıkarımı, tarih biçimi) ve istatistikleri (giren/bağlanan/terk
     * edilen/cevapsız sayıları, ort. bekleme/görüşme süresi) tek geçişte
     * hesaplar. Önceden queue_logs.php'nin içindeydi; mantık DEĞİŞTİRİLMEDİ.
     */
    public static function searchAndParse(int $startTs, int $endTs, string $eventFilter, string $agentFilter, string $searchQuery, array $agentMap): array
    {
        $sql = "SELECT time_id, created_at, call_id, queue_name, agent, event, data1, data2, data3, data4 FROM cc_queue_logs WHERE 1=1";
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
            $params[] = "%$searchQuery%";
            $params[] = "%$searchQuery%";
            $params[] = "%$searchQuery%";
            $params[] = "%$searchQuery%";
            $params[] = "%$searchQuery%";
        }

        $sql .= " ORDER BY time_id DESC, id DESC LIMIT 500";

        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $parsed_logs = [];
        $stat_total_enter = 0;
        $stat_connected = 0;
        $stat_abandon = 0;
        $stat_ring_no_answer = 0;
        $total_holdtime = 0;
        $total_talktime = 0;

        foreach ($rows as $row) {
            $ts = intval($row['time_id']);
            $call_id = $row['call_id'];
            $queue_name = $row['queue_name'];
            $agent = $row['agent'];
            $event = $row['event'];
            $data1 = $row['data1'];
            $data2 = $row['data2'];
            $data3 = $row['data3'];
            $data4 = $row['data4'];

            // Extract agent extension from PJSIP/3001
            $agent_ext = '';
            if (preg_match('/PJSIP\/([0-9]+)/i', $agent, $m)) {
                $agent_ext = $m[1];
            } else {
                $agent_ext = preg_replace('/[^0-9]/', '', $agent);
            }

            // Accumulate Stats
            if ($event === 'ENTERQUEUE') $stat_total_enter++;
            if ($event === 'CONNECT') {
                $stat_connected++;
                $total_holdtime += intval($data1);
            }
            if ($event === 'ABANDON') $stat_abandon++;
            if ($event === 'RINGNOANSWER') $stat_ring_no_answer++;
            if ($event === 'COMPLETECALLER' || $event === 'COMPLETEAGENT') {
                $total_talktime += intval($data2);
            }

            $parsed_logs[] = [
                'timestamp' => $ts,
                'datetime' => date('d.m.Y H:i:s', $ts),
                'call_id' => $call_id,
                'queue_name' => $queue_name,
                'agent' => $agent,
                'agent_ext' => $agent_ext,
                'agent_name' => $agentMap[$agent_ext] ?? ($agent !== 'NONE' ? $agent : '-'),
                'event' => $event,
                'data1' => $data1,
                'data2' => $data2,
                'data3' => $data3,
                'data4' => $data4,
            ];
        }

        $avg_holdtime = $stat_connected > 0 ? round($total_holdtime / $stat_connected, 1) : 0;
        $avg_talktime = $stat_connected > 0 ? round($total_talktime / $stat_connected, 1) : 0;

        return [
            'logs' => $parsed_logs,
            'stat_total_enter' => $stat_total_enter,
            'stat_connected' => $stat_connected,
            'stat_abandon' => $stat_abandon,
            'stat_ring_no_answer' => $stat_ring_no_answer,
            'avg_holdtime' => $avg_holdtime,
            'avg_talktime' => $avg_talktime,
        ];
    }
}
