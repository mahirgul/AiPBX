#!/usr/bin/env php
<?php
/**
 * Synchronize Asterisk queue_log (ODBC → asteriskqueue) into the portal's cc_queue_logs table.
 *
 * Kaynak: `asteriskqueue` — Asterisk tarafından CANLI yazılır (extconfig: queue_log => odbc,asterisk,asteriskqueue).
 * Yerel kolonlar: time (datetime string), callid, queuename, agent, event, data1..data5.
 * Hedef: `cc_queue_logs` — portal raporlarının okuduğu tablo: time_id (epoch int), call_id, queue_name, ...
 */
require_once '/var/www/html/config.php';

try {
    $db = getDB();

    // Son senkronize edilen epoch (portal tablosundan)
    $max_time = intval($db->query("SELECT IFNULL(MAX(time_id), 0) FROM cc_queue_logs")->fetchColumn());

    // Asterisk'in canlı queue_log tablosundan yeni satırlar (60 sn tolerans penceresi)
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
            continue; // zaten senkronize
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
        $inserted++;
    }

    if ($inserted > 0) {
        file_put_contents('/var/log/asterisk/sync_queue_logs.log', date('[Y-m-d H:i:s] ') . "Synced {$inserted} queue_log rows\n", FILE_APPEND);
    }
} catch (Exception $e) {
    file_put_contents('/var/log/asterisk/sync_queue_logs_error.log', date('[Y-m-d H:i:s] ') . $e->getMessage() . "\n", FILE_APPEND);
    exit(1);
}
