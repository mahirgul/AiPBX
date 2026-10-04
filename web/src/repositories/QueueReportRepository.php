<?php
require_once __DIR__ . '/../services/QueueStats.php';

/**
 * Data for the Queue Report Centre. Everything comes from Asterisk's
 * queue_log (copied into cc_queue_logs by bin/sync_queue_logs.php), the
 * pause log, call notes and, for call-backs, the CDR.
 */
class QueueReportRepository extends BaseRepository
{
    protected static string $table = 'cc_queue_logs';

    /** Queues that appear in the log or are configured: queue_name => title. */
    public static function queues(): array
    {
        $map = [];
        try {
            foreach (static::db()->query("SELECT queue_name, title FROM pbx_queues ORDER BY title")->fetchAll(PDO::FETCH_ASSOC) as $q) {
                $map[$q['queue_name']] = $q['title'] !== '' ? $q['title'] : $q['queue_name'];
            }
        } catch (\Throwable $e) {
        }
        foreach (static::db()->query("SELECT DISTINCT queue_name FROM cc_queue_logs WHERE event = 'ENTERQUEUE' AND queue_name NOT IN ('', 'NONE')")->fetchAll(PDO::FETCH_COLUMN) as $q) {
            $map[$q] ??= $q;
        }
        return $map;
    }

    public static function agentNames(): array
    {
        return static::db()->query("SELECT extension, full_name FROM sys_users WHERE extension IS NOT NULL AND extension != ''")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * One row per call and queue that entered between $from and $to.
     * Events after $to still count (a call entering at 23:59 ends the next day).
     *
     * @param string[] $queues empty = all
     * @return list<array<string, mixed>>
     */
    public static function calls(int $from, int $to, array $queues = []): array
    {
        $params = [$from, $to + 86400];
        $qSql = '';
        if ($queues) {
            $qSql = ' AND queue_name IN (' . implode(',', array_fill(0, count($queues), '?')) . ')';
            $params = array_merge($params, array_values($queues));
        }
        $num = fn(string $col) => "CAST(NULLIF({$col}, '') AS UNSIGNED)";
        $sql = "SELECT call_id, queue_name,
                MIN(CASE WHEN event = 'ENTERQUEUE' THEN time_id END) AS enter_ts,
                MAX(CASE WHEN event = 'ENTERQUEUE' THEN data2 END) AS caller,
                MAX(CASE WHEN event = 'CONNECT' THEN agent END) AS agent,
                MAX(event = 'CONNECT') AS answered,
                CASE
                    WHEN MAX(event = 'CONNECT') THEN ''
                    WHEN MAX(event = 'ABANDON') THEN 'abandon'
                    WHEN MAX(event IN ('EXITWITHTIMEOUT', 'EXITEMPTY', 'EXITWITHKEY')) THEN 'timeout'
                    ELSE ''
                END AS lost,
                COALESCE(
                    MAX(CASE WHEN event = 'CONNECT' THEN {$num('data1')} END),
                    MAX(CASE WHEN event IN ('ABANDON', 'EXITWITHTIMEOUT', 'EXITEMPTY') THEN {$num('data3')}
                             WHEN event = 'EXITWITHKEY' THEN {$num('data4')} END),
                    0) AS wait,
                COALESCE(MAX(CASE WHEN event = 'CONNECT' THEN {$num('data3')} END), 0) AS ring,
                COALESCE(MAX(CASE WHEN event IN ('COMPLETECALLER', 'COMPLETEAGENT') THEN {$num('data2')}
                                  WHEN event IN ('BLINDTRANSFER', 'ATTENDEDTRANSFER') THEN {$num('data4')} END), 0) AS talk,
                MAX(event = 'COMPLETEAGENT') AS agent_hangup,
                MAX(event IN ('BLINDTRANSFER', 'ATTENDEDTRANSFER')) AS transferred,
                MAX(CASE WHEN event = 'ENTERQUEUE' THEN {$num('data3')} END) AS position
            FROM cc_queue_logs
            WHERE time_id >= ? AND time_id <= ? AND call_id NOT IN ('', 'NONE') AND queue_name NOT IN ('', 'NONE'){$qSql}
            GROUP BY call_id, queue_name
            HAVING enter_ts IS NOT NULL AND enter_ts <= ?
            ORDER BY enter_ts";
        $params[] = $to;
        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            foreach (['enter_ts', 'answered', 'wait', 'ring', 'talk', 'agent_hangup', 'transferred', 'position'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            $r['caller'] = (string) $r['caller'];
            $r['agent'] = (string) $r['agent'];
        }
        return $rows;
    }

    /** RINGNOANSWER per agent extension: the queue rang them and they did not pick up. */
    public static function ringMisses(int $from, int $to, array $queues = []): array
    {
        [$qSql, $qParams] = static::queueFilter($queues);
        $stmt = static::db()->prepare("SELECT agent, COUNT(*) AS n, COALESCE(SUM(CAST(NULLIF(data1, '') AS UNSIGNED)), 0) AS ms
            FROM cc_queue_logs WHERE event = 'RINGNOANSWER' AND time_id >= ? AND time_id <= ?{$qSql} GROUP BY agent");
        $stmt->execute(array_merge([$from, $to], $qParams));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ext = QueueStats::agentExt((string) $r['agent']);
            if ($ext === '') {
                continue;
            }
            $out[$ext]['count'] = ($out[$ext]['count'] ?? 0) + (int) $r['n'];
            $out[$ext]['ring_ms'] = ($out[$ext]['ring_ms'] ?? 0) + (int) $r['ms'];
        }
        return $out;
    }

    /** Pause count and seconds per extension, clipped to the period. */
    public static function pauses(int $from, int $to): array
    {
        $out = [];
        try {
            $f = date('Y-m-d H:i:s', $from);
            $t = date('Y-m-d H:i:s', $to);
            $stmt = static::db()->prepare("SELECT agent_extension, COUNT(*) AS n,
                    COALESCE(SUM(GREATEST(0, TIMESTAMPDIFF(SECOND, GREATEST(start_time, ?), LEAST(IFNULL(end_time, NOW()), ?)))), 0) AS secs
                FROM cc_pause_logs
                WHERE start_time <= ? AND IFNULL(end_time, NOW()) >= ? AND agent_extension != ''
                GROUP BY agent_extension");
            $stmt->execute([$f, $t, $t, $f]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['agent_extension']] = ['count' => (int) $r['n'], 'seconds' => (int) $r['secs']];
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /**
     * Call notes (dispositions) saved in the period.
     * @return array{per_agent: array<string, int>, per_disposition: array<string, array<string, int>>}
     */
    public static function notes(int $from, int $to): array
    {
        $perAgent = [];
        $perDisp = [];
        try {
            $stmt = static::db()->prepare("SELECT agent_extension, COALESCE(NULLIF(disposition, ''), '-') AS disposition, COUNT(*) AS n
                FROM callcenter_notes WHERE created_at >= ? AND created_at <= ? GROUP BY agent_extension, disposition");
            $stmt->execute([date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $to)]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ext = (string) $r['agent_extension'];
                $perAgent[$ext] = ($perAgent[$ext] ?? 0) + (int) $r['n'];
                $perDisp[$r['disposition']][$ext] = (int) $r['n'];
            }
        } catch (\Throwable $e) {
        }
        uasort($perDisp, fn($a, $b) => array_sum($b) <=> array_sum($a));
        return ['per_agent' => $perAgent, 'per_disposition' => $perDisp];
    }

    /**
     * Outgoing calls made after the first lost call, by normalized number,
     * to tell which lost callers were called back.
     * @param string[] $numbers callers of lost calls
     * @return array<string, list<int>>
     */
    public static function outgoingTo(array $numbers, int $from): array
    {
        $want = [];
        foreach ($numbers as $n) {
            $k = QueueStats::normalizeNumber($n);
            if (strlen($k) >= 7) {
                $want[$k] = true;
            }
        }
        if (!$want) {
            return [];
        }
        $out = [];
        // Calls placed by an extension (channel PJSIP/<digits>…) to an external number.
        $stmt = static::db()->prepare("SELECT dst, UNIX_TIMESTAMP(calldate) AS ts FROM asteriskcdr
            WHERE calldate >= ? AND dst REGEXP '^[+0-9]{7,}$' AND channel REGEXP '^PJSIP/[0-9]+(-[a-z]+)*-[0-9a-f]+$'");
        $stmt->execute([date('Y-m-d H:i:s', $from)]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $k = QueueStats::normalizeNumber((string) $r['dst']);
            if (isset($want[$k])) {
                $out[$k][] = (int) $r['ts'];
            }
        }
        return $out;
    }

    /**
     * CDR id of the recording of each queue call (queue_log callid is the
     * caller channel's uniqueid; the recording is on one of its legs).
     * @param string[] $callIds
     * @return array<string, int> call_id => asteriskcdr.id
     */
    public static function recordings(array $callIds): array
    {
        $callIds = array_values(array_unique(array_filter($callIds)));
        if (!$callIds) {
            return [];
        }
        $out = [];
        foreach (array_chunk($callIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = static::db()->prepare("SELECT id, uniqueid, linkedid FROM asteriskcdr
                WHERE (uniqueid IN ($in) OR linkedid IN ($in)) AND userfield IS NOT NULL AND userfield != ''
                ORDER BY id");
            $stmt->execute(array_merge($chunk, $chunk));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                foreach ([$r['uniqueid'], $r['linkedid']] as $k) {
                    if (in_array($k, $chunk, true)) {
                        $out[$k] ??= (int) $r['id'];
                    }
                }
            }
        }
        return $out;
    }

    /** @return array{0: string, 1: array} */
    protected static function queueFilter(array $queues): array
    {
        if (!$queues) {
            return ['', []];
        }
        return [' AND queue_name IN (' . implode(',', array_fill(0, count($queues), '?')) . ')', array_values($queues)];
    }
}
