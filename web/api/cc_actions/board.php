<?php
// Çağrı Merkezi Panosu (wallboard) istatistik aksiyonu: get_board_stats
if (!defined('CC_DISPATCH_ACTIVE')) { http_response_code(403); exit; }

if ($action === 'get_board_stats') {
    $queue_filter = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['queue'] ?? 'ALL');
    $range = $_GET['range'] ?? 'today';

    switch ($range) {
        case 'week':
            $since = date('Y-m-d 00:00:00', strtotime('monday this week'));
            break;
        case 'month':
            $since = date('Y-m-01 00:00:00');
            break;
        default:
            $range = 'today';
            $since = date('Y-m-d 00:00:00');
    }

    // SLA eşiği (saniye) — panelden ayarlanabilir, tanımsızsa 20sn
    $sla_threshold = (int) getSystemSetting('cc_sla_threshold_seconds', 20);
    if ($sla_threshold <= 0) $sla_threshold = 20;

    $where_queue = '';
    $params = [$since];
    if ($queue_filter !== '' && $queue_filter !== 'ALL') {
        $where_queue = ' AND queue_name = ?';
        $params[] = $queue_filter;
    }

    // Toplam çağrı (kuyruğa giren) — bkz. Asterisk queue_log: ENTERQUEUE
    $stmt = $db->prepare("SELECT COUNT(*) FROM cc_queue_logs WHERE event = 'ENTERQUEUE' AND created_at >= ?$where_queue");
    $stmt->execute($params);
    $total_calls = (int)$stmt->fetchColumn();

    // Cevaplanan çağrılar: CONNECT event'i, data1 = bekleme süresi (saniye)
    $stmt = $db->prepare(
        "SELECT COUNT(*), AVG(CAST(data1 AS UNSIGNED)), " .
        "SUM(CASE WHEN CAST(data1 AS UNSIGNED) <= ? THEN 1 ELSE 0 END), MAX(CAST(data1 AS UNSIGNED)) " .
        "FROM cc_queue_logs WHERE event = 'CONNECT' AND created_at >= ?$where_queue"
    );
    $stmt->execute(array_merge([$sla_threshold], $params));
    [$answered_calls, $avg_wait, $sla_answered, $max_wait_connect] = $stmt->fetch(PDO::FETCH_NUM);
    $answered_calls = (int)$answered_calls;
    $avg_wait = $avg_wait !== null ? (int)round((float)$avg_wait) : 0;
    $sla_answered = (int)$sla_answered;
    $max_wait_connect = (int)$max_wait_connect;

    // Terk edilen çağrılar: ABANDON event'i, data3 = terk edilene kadar geçen bekleme süresi
    // (data1/data2 kuyruk pozisyonu — gerçek üretim verisiyle doğrulandı, bkz. commit notu)
    $stmt = $db->prepare("SELECT COUNT(*), MAX(CAST(data3 AS UNSIGNED)) FROM cc_queue_logs WHERE event = 'ABANDON' AND created_at >= ?$where_queue");
    $stmt->execute($params);
    [$abandoned_calls, $max_wait_abandon] = $stmt->fetch(PDO::FETCH_NUM);
    $abandoned_calls = (int)$abandoned_calls;
    $max_wait_abandon = (int)$max_wait_abandon;

    // Cevapsız (temsilcinin telefonu çaldı ama açılmadı): RINGNOANSWER — aynı çağrı
    // aynı/farklı temsilciye tekrar tekrar çalabildiği için call_id bazında DISTINCT sayılır.
    $stmt = $db->prepare("SELECT COUNT(DISTINCT call_id) FROM cc_queue_logs WHERE event = 'RINGNOANSWER' AND created_at >= ?$where_queue");
    $stmt->execute($params);
    $missed_calls = (int)$stmt->fetchColumn();

    // Ortalama görüşme süresi: COMPLETEAGENT/COMPLETECALLER data2 = konuşma süresi (saniye)
    $stmt = $db->prepare("SELECT AVG(CAST(data2 AS UNSIGNED)) FROM cc_queue_logs WHERE event IN ('COMPLETEAGENT','COMPLETECALLER') AND created_at >= ?$where_queue");
    $stmt->execute($params);
    $avg_talk_raw = $stmt->fetchColumn();
    $avg_talk = $avg_talk_raw !== null ? (int)round((float)$avg_talk_raw) : 0;

    $max_wait = max($max_wait_connect, $max_wait_abandon);
    $sla_pct = $total_calls > 0 ? round(($sla_answered / $total_calls) * 100, 1) : 100.0;
    $answered_rate = $total_calls > 0 ? round(($answered_calls / $total_calls) * 100) : 0;
    $missed_rate = $total_calls > 0 ? round(($missed_calls / $total_calls) * 100) : 0;
    $abandon_rate = $total_calls > 0 ? round(($abandoned_calls / $total_calls) * 100) : 0;

    // --- Canlı durum (şu anki kuyruk/temsilci durumu, tarih aralığından bağımsız) ---
    @exec("asterisk -rx " . escapeshellarg("queue show"), $raw_q_output);
    $parsed = parseAsteriskQueuesOutput($raw_q_output);

    $db_queues_stmt = $db->query("SELECT queue_name, members_json FROM pbx_queues WHERE is_active = 1");
    $db_queues = $db_queues_stmt->fetchAll(PDO::FETCH_ASSOC);

    $logged_in = 0;
    $agents_total = 0;
    $available = 0;
    $active_calls_live = 0;
    foreach ($db_queues as $q) {
        if ($queue_filter !== '' && $queue_filter !== 'ALL' && $q['queue_name'] !== $queue_filter) continue;
        $members = json_decode($q['members_json'] ?? '[]', true) ?: [];
        $agents_total += count($members);
        $qmembers = $parsed[$q['queue_name']]['members'] ?? [];
        foreach ($qmembers as $m) {
            // "queue show" kuyruğa EKLENMİŞ (dynamic member) her uzantıyı listeler —
            // cihazı tamamen çevrimdışı (Unavailable) olsa, hatta saatler önce eklenip
            // hiç çıkış yapılmamış olsa bile. "Giriş Yapan Temsilci" panoda kullanıcının
            // beklediği anlam gerçekten ULAŞILABİLİR temsilci sayısı, o yüzden
            // is_unavailable burada da (available sayımındaki gibi) dışlanıyor.
            if (!empty($m['in_queue']) && empty($m['is_unavailable'])) {
                $logged_in++;
                if (empty($m['is_paused']) && empty($m['is_busy'])) $available++;
                if (!empty($m['is_busy'])) $active_calls_live++;
            }
        }
    }

    $waiting_now = 0;
    if (is_array($raw_q_output)) {
        foreach ($raw_q_output as $line) {
            $clean = preg_replace("/\x1b\[[0-9;]*[a-zA-Z]/", "", $line);
            if (preg_match('/^([a-zA-Z0-9_-]+)\s+has\s+(\d+)\s+calls/i', trim($clean), $m)) {
                if ($queue_filter === '' || $queue_filter === 'ALL' || $m[1] === $queue_filter) {
                    $waiting_now += (int)$m[2];
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'range' => $range,
        'sla_threshold' => $sla_threshold,
        'waiting_calls' => $waiting_now,
        'avg_wait' => $avg_wait,
        'avg_talk' => $avg_talk,
        'max_wait' => $max_wait,
        'missed_calls' => $missed_calls,
        'abandoned_calls' => $abandoned_calls,
        'answered_calls' => $answered_calls,
        'total_calls' => $total_calls,
        'sla_pct' => $sla_pct,
        'agents_logged_in' => $logged_in,
        'agents_total' => $agents_total,
        'agents_available' => $available,
        'active_calls' => $active_calls_live,
        'answered_rate' => $answered_rate,
        'missed_rate' => $missed_rate,
        'abandon_rate' => $abandon_rate,
    ]);
    exit;
}
