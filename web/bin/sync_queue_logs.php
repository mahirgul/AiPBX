#!/usr/bin/env php
<?php
/**
 * Synchronize Asterisk queue_log (ODBC → asteriskqueue) into the portal's cc_queue_logs table.
 *
 * Source: `asteriskqueue` — written LIVE by Asterisk (extconfig: queue_log => odbc,asterisk,asteriskqueue).
 * Native columns: time (datetime string), callid, queuename, agent, event, data1..data5.
 * Target: `cc_queue_logs` — the table the portal reports read: time_id (epoch int), call_id, queue_name, ...
 */
if (PHP_SAPI !== 'cli') {   // cron/dialplan tool; never over HTTP
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config.php';

try {
    $db = getDB();

    // The last synchronized epoch (from the portal table)
    $max_time = intval($db->query("SELECT IFNULL(MAX(time_id), 0) FROM cc_queue_logs")->fetchColumn());

    // New rows from Asterisk's live queue_log table (60 s tolerance window)
    $rows = $db->query(
        "SELECT time, callid, queuename, agent, event, data1, data2, data3, data4, data5
         FROM asteriskqueue
         WHERE UNIX_TIMESTAMP(LEFT(time, 19)) > " . max(0, $max_time - 60) . "
         ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        exit(0);
    }

    $chk = $db->prepare("SELECT id FROM cc_queue_logs WHERE time_id = ? AND call_id = ? AND event = ? AND agent = ? LIMIT 1");
    $stmt = $db->prepare("INSERT INTO cc_queue_logs
        (time_id, created_at, call_id, queue_name, agent, event, data1, data2, data3, data4, data5)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $inserted = 0;
    foreach ($rows as $r) {
        $ts = intval(strtotime(substr($r['time'], 0, 19)));
        if ($ts <= 0) continue;

        $call_id = trim($r['callid'] ?? '');
        $queue_name = trim($r['queuename'] ?? '');
        $agent = trim($r['agent'] ?? '');
        $event = trim($r['event'] ?? '');

        $chk->execute([$ts, $call_id, $event, $agent]);
        if ($chk->fetchColumn()) {
            continue; // already synced
        }

        $stmt->execute([
            $ts,
            date('Y-m-d H:i:s', $ts),
            $call_id,
            $queue_name,
            $agent,
            $event,
            trim($r['data1'] ?? ''),
            trim($r['data2'] ?? ''),
            trim($r['data3'] ?? ''),
            trim($r['data4'] ?? ''),
            trim($r['data5'] ?? ''),
        ]);
        if ($event === 'ABANDON') {
            try {
                require_once dirname(__DIR__) . '/src/services/WebhookService.php';
                WebhookService::emit('queue.caller_abandoned', [
                    'queue_name'   => $queue_name,
                    'call_id'      => $call_id,
                    'position'     => (int)trim($r['data1'] ?? '0'),
                    'orig_pos'     => (int)trim($r['data2'] ?? '0'),
                    'wait_seconds' => (int)trim($r['data3'] ?? '0'),
                ]);
            } catch (\Throwable $e) {
            }
        }
        $inserted++;
    }

    if ($inserted > 0) {
        file_put_contents('/var/log/asterisk/sync_queue_logs.log', date('[Y-m-d H:i:s] ') . "Synced {$inserted} queue_log rows\n", FILE_APPEND);
    }
} catch (Exception $e) {
    file_put_contents('/var/log/asterisk/sync_queue_logs_error.log', date('[Y-m-d H:i:s] ') . $e->getMessage() . "\n", FILE_APPEND);
    exit(1);
}
