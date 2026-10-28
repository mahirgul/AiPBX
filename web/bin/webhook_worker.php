#!/usr/bin/env php
<?php
/**
 * CLI Worker for draining `sys_webhook_queue` (outbound HTTP webhooks and
 * plugin event subscriptions) and sweeping new CDR/Fax completions into events.
 *
 * Usage:
 *   php web/bin/webhook_worker.php           (process one batch up to 50 jobs)
 *   php web/bin/webhook_worker.php --daemon  (poll every 2 seconds)
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/WebhookService.php';
require_once dirname(__DIR__) . '/src/services/CallQualityService.php';
if (is_file(dirname(__DIR__) . '/src/core/PluginManager.php')) {
    require_once dirname(__DIR__) . '/src/core/PluginManager.php';
}

function sweepCdrAndFaxEvents(): void
{
    try {
        $db = getDB();
        $upsertSetting = $db->prepare(
            'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );

        // 1. Sweep newly completed CDR rows (`asteriskcdr`) using composite (calldate, uniqueid) cursor
        $lastCdrCursor = getSystemSetting('webhook_last_cdr_time', '');
        if ($lastCdrCursor === '') {
            $upsertSetting->execute(['webhook_last_cdr_time', date('Y-m-d H:i:s') . '|']);
        } else {
            $cursorParts = explode('|', $lastCdrCursor, 2);
            $lastCdrTime = $cursorParts[0];
            $lastCdrUid  = $cursorParts[1] ?? '';
            $stmt = $db->prepare(
                'SELECT calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, duration, billsec, disposition, uniqueid, userfield
                 FROM asteriskcdr
                 WHERE calldate > ? OR (calldate = ? AND uniqueid > ?)
                 ORDER BY calldate ASC, uniqueid ASC
                 LIMIT 100'
            );
            $stmt->execute([$lastCdrTime, $lastCdrTime, $lastCdrUid]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $lastCdrTime = (string)$r['calldate'];
                $lastCdrUid  = (string)$r['uniqueid'];
                $payload = [
                    'calldate'    => $r['calldate'],
                    'clid'        => $r['clid'],
                    'src'         => $r['src'],
                    'dst'         => $r['dst'],
                    'dcontext'    => $r['dcontext'],
                    'duration'    => (int)$r['duration'],
                    'billsec'     => (int)$r['billsec'],
                    'disposition' => $r['disposition'],
                    'uniqueid'    => $r['uniqueid'],
                    'recording'   => $r['userfield'] ?? '',
                ];
                WebhookService::emit('call.ended', $payload);
                if (in_array(strtoupper((string)$r['disposition']), ['NO ANSWER', 'BUSY', 'FAILED'], true)) {
                    WebhookService::emit('call.missed', $payload);
                }
            }
            if (!empty($rows)) {
                $upsertSetting->execute(['webhook_last_cdr_time', $lastCdrTime . '|' . $lastCdrUid]);
            }
        }

        // 2. Sweep newly received faxes (`fax_received`)
        $lastRxId = (int)getSystemSetting('webhook_last_fax_rx_id', '0');
        if ($lastRxId === 0) {
            $maxRx = (int)$db->query('SELECT IFNULL(MAX(id), 0) FROM fax_received')->fetchColumn();
            $upsertSetting->execute(['webhook_last_fax_rx_id', (string)$maxRx]);
        } else {
            // did_extension: the column was never called did_number, so this query
            // failed on every poll and no fax.received was ever sent. Failed faxes
            // (no pages, recorded since 1.50.2) are not "received".
            $stmt = $db->prepare('SELECT id, caller_id, did_extension AS did_number, pages, status, received_at FROM fax_received WHERE id > ? ORDER BY id ASC LIMIT 50');
            $stmt->execute([$lastRxId]);
            $rxRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rxRows as $rx) {
                $lastRxId = (int)$rx['id'];
                if ($rx['status'] !== 'SUCCESS') {
                    continue;
                }
                WebhookService::emit('fax.received', [
                    'fax_id'      => $lastRxId,
                    'caller_id'   => $rx['caller_id'],
                    'did_number'  => $rx['did_number'],
                    'pages'       => (int)$rx['pages'],
                    'received_at' => $rx['received_at'],
                ]);
            }
            if (!empty($rxRows)) {
                $upsertSetting->execute(['webhook_last_fax_rx_id', (string)$lastRxId]);
            }
        }

        // 3. Sweep newly completed outgoing faxes (`fax_sent`) using composite (completed_at, id) cursor
        $lastTxCursor = getSystemSetting('webhook_last_fax_tx_time', '');
        if ($lastTxCursor === '') {
            $upsertSetting->execute(['webhook_last_fax_tx_time', date('Y-m-d H:i:s') . '|0']);
        } else {
            $txParts    = explode('|', $lastTxCursor, 2);
            $lastTxTime = $txParts[0];
            $lastTxId   = (int)($txParts[1] ?? 0);
            $stmt = $db->prepare(
                "SELECT id, sender_extension, destination_number, status, pages, error_message, completed_at
                 FROM fax_sent
                 WHERE status IN ('SUCCESS', 'FAILED')
                   AND (completed_at > ? OR (completed_at = ? AND id > ?))
                 ORDER BY completed_at ASC, id ASC
                 LIMIT 50"
            );
            $stmt->execute([$lastTxTime, $lastTxTime, $lastTxId]);
            $txRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($txRows as $tx) {
                $lastTxTime = (string)$tx['completed_at'];
                $lastTxId   = (int)$tx['id'];
                $evName = ($tx['status'] === 'SUCCESS') ? 'fax.sent' : 'fax.failed';
                WebhookService::emit($evName, [
                    'fax_id'             => $lastTxId,
                    'sender_extension'   => $tx['sender_extension'],
                    'destination_number' => $tx['destination_number'],
                    'status'             => $tx['status'],
                    'pages'              => (int)$tx['pages'],
                    'error_message'      => $tx['error_message'] ?? '',
                    'completed_at'       => $tx['completed_at'],
                ]);
            }
            if (!empty($txRows)) {
                $upsertSetting->execute(['webhook_last_fax_tx_time', $lastTxTime . '|' . $lastTxId]);
            }
        }
    } catch (\Throwable $e) {
        error_log('[WebhookWorker] sweep error: ' . $e->getMessage());
    }
}

$isDaemon = in_array('--daemon', $argv ?? [], true);

do {
    if (class_exists('PluginManager')) {
        // The daemon runs for weeks and PluginManager caches the plugin list:
        // a plugin enabled or uploaded in the portal got no events and no
        // worker ticks until the service restarted, and a disabled one kept both.
        PluginManager::resetCache();
    }
    sweepCdrAndFaxEvents();
    if (class_exists('PluginManager')) {
        PluginManager::tickAllEnabledWorkers();
    }
    // Call quality from the captured RTCP reports: one pass a minute, only while capture is on.
    try {
        CallQualityService::tick();
    } catch (\Throwable $e) {
        error_log('call quality: ' . $e->getMessage());
    }
    $stats = WebhookService::processPendingBatch(50);
    if (!$isDaemon) {
        if ($stats['processed'] > 0) {
            echo sprintf(
                "[%s] Processed=%d Delivered=%d Failed=%d\n",
                date('Y-m-d H:i:s'),
                $stats['processed'],
                $stats['delivered'],
                $stats['failed']
            );
        }
        break;
    }
    usleep($stats['processed'] > 0 ? 200000 : 2000000);
} while (true);
