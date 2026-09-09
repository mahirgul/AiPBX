<?php
// Temsilci oturum aksiyonları: login, logout, pause, unpause, get_status, auto_login
if (!defined('CC_DISPATCH_ACTIVE')) { http_response_code(403); exit; }
require_once __DIR__ . '/../../src/queue_helper.php';

if ($action === 'login') {
    if (!empty($user_ext)) {
        if (!QueueHelper::isAssignedMember($user_ext, 'queue_cc')) {
            echo json_encode(['success' => false, 'error' => "Dahili numaranız ($user_ext) Çağrı Merkezi (queue_cc) kuyruğuna tanımlı değildir!"]);
            exit;
        }

        QueueHelper::setMembership($user_ext, 'queue_cc', true);

        // Close any lingering active break logs
        $stmt = $db->prepare("UPDATE cc_pause_logs SET end_time = NOW(), duration = TIMESTAMPDIFF(SECOND, start_time, NOW()), status = 'COMPLETED' WHERE agent_extension = ? AND status = 'PAUSED'");
        $stmt->execute([$user_ext]);
    }
    echo json_encode(['success' => true, 'message' => 'Kuyruğa giriş yapıldı ve aktif duruma geçildi']);
    exit;
}

if ($action === 'logout') {
    if (!empty($user_ext)) {
        QueueHelper::setMembership($user_ext, 'queue_cc', false);

        // Close any active break log
        $stmt = $db->prepare("UPDATE cc_pause_logs SET end_time = NOW(), duration = TIMESTAMPDIFF(SECOND, start_time, NOW()), status = 'COMPLETED' WHERE agent_extension = ? AND status = 'PAUSED'");
        $stmt->execute([$user_ext]);
    }
    echo json_encode(['success' => true, 'message' => 'Kuyruktan çıkış yapıldı']);
    exit;
}

if ($action === 'pause') {
    $reason = trim($_POST['reason'] ?? 'Mola');
    if (empty($reason)) $reason = 'Mola';

    if (!empty($user_ext)) {
        // Asterisk CLI "reason" argümanı boşluk içerdiğinde ("Yemek Molası"
        // gibi) tırnaksız gönderilirse CLI parser'ı bunu birden fazla
        // argüman sanıp komutu reddediyor (Usage: hatası) — pause hiç
        // uygulanmıyordu ama DB'ye "PAUSED" yazıldığı için arayüzde mola
        // görünüyor, gerçekte Asterisk hâlâ üye üzerinden çağrı dağıtıyordu.
        $reason_cli = str_replace('"', '', $reason);
        // Pause in Asterisk queue
        @exec("asterisk -rx " . escapeshellarg("queue pause member Local/$user_ext@from-internal-pbx/n queue queue_cc reason \"$reason_cli\""), $out);
        @exec("asterisk -rx " . escapeshellarg("queue pause member PJSIP/$user_ext queue queue_cc reason \"$reason_cli\""), $out);

        withAgentPauseLock($db, $user_ext, function() use ($db, $user_ext, $user_name, $reason) {
            try {
                $db->beginTransaction();
                // Complete any active break log
                $stmt = $db->prepare("UPDATE cc_pause_logs SET end_time = NOW(), duration = TIMESTAMPDIFF(SECOND, start_time, NOW()), status = 'COMPLETED' WHERE agent_extension = ? AND status = 'PAUSED'");
                $stmt->execute([$user_ext]);

                // Insert new break log
                $stmt = $db->prepare("INSERT INTO cc_pause_logs (agent_extension, agent_name, pause_reason, start_time, status) VALUES (?, ?, ?, NOW(), 'PAUSED')");
                $stmt->execute([$user_ext, $user_name, $reason]);
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
            }
        });
    }
    echo json_encode(['success' => true, 'message' => "Mola başlatıldı ($reason)", 'reason' => $reason]);
    exit;
}

if ($action === 'unpause') {
    if (!empty($user_ext)) {
        // Unpause in Asterisk queue
        @exec("asterisk -rx " . escapeshellarg("queue unpause member Local/$user_ext@from-internal-pbx/n queue queue_cc"), $out);
        @exec("asterisk -rx " . escapeshellarg("queue unpause member PJSIP/$user_ext queue queue_cc"), $out);

        // Complete active break log
        withAgentPauseLock($db, $user_ext, function() use ($db, $user_ext) {
            $stmt = $db->prepare("UPDATE cc_pause_logs SET end_time = NOW(), duration = TIMESTAMPDIFF(SECOND, start_time, NOW()), status = 'COMPLETED' WHERE agent_extension = ? AND status = 'PAUSED'");
            $stmt->execute([$user_ext]);
        });
    }
    echo json_encode(['success' => true, 'message' => 'Moladan dönüldü, kuyrukta aktifsiniz']);
    exit;
}

if ($action === 'get_status') {
    @exec("asterisk -rx " . escapeshellarg("queue show"), $raw_q_output);
    $parsed_queues = parseAsteriskQueuesOutput($raw_q_output);

    $in_queue = false;
    $is_paused = false;
    $current_reason = '';

    foreach ($parsed_queues as $q_name => $q_data) {
        if (isset($q_data['members'][$user_ext])) {
            $m = $q_data['members'][$user_ext];
            if ($m['in_queue']) {
                $in_queue = true;
                if ($m['is_paused']) {
                    $is_paused = true;
                }
            }
        }
    }

    // Fetch active break log from DB
    $stmt = $db->prepare("SELECT id, pause_reason, start_time FROM cc_pause_logs WHERE agent_extension = ? AND status = 'PAUSED' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user_ext]);
    $active_break = $stmt->fetch();

    if ($active_break) {
        $is_paused = true;
        if (empty($current_reason)) {
            $current_reason = $active_break['pause_reason'];
        }
    }

    // Fetch break reasons configuration & auto-login setting
    $stmt = $db->query("SELECT setting_key, setting_value FROM sys_settings WHERE setting_key IN ('cc_auto_queue_login', 'cc_break_reasons')");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $auto_login = ($settings['cc_auto_queue_login'] ?? '1') === '1';
    $raw_reasons = $settings['cc_break_reasons'] ?? 'Yemek Molası,Kısa Dinlenme,Eğitim / Toplantı,Evrak / İdari İşler,Teknik Problem';
    $reasons = array_filter(array_map('trim', explode(',', $raw_reasons)));

    echo json_encode([
        'success' => true,
        'agent_extension' => $user_ext,
        'in_queue' => $in_queue,
        'is_paused' => $is_paused,
        'pause_reason' => $current_reason ?: ($active_break['pause_reason'] ?? ''),
        'pause_start' => $active_break['start_time'] ?? null,
        'auto_login' => $auto_login,
        'reasons' => array_values($reasons)
    ]);
    exit;
}

if ($action === 'auto_login') {
    $stmt = $db->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'cc_auto_queue_login'");
    $auto = $stmt->fetchColumn() ?: '1';

    if ($auto === '1' && !empty($user_ext)) {
        $last_queues = json_decode($_POST['last_queues'] ?? '[]', true) ?: [];

        // Fetch assigned active queues for this user
        $stmt = $db->query("SELECT queue_name, members_json FROM pbx_queues WHERE is_active = 1");
        $all_queues = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($all_queues as $q) {
            $q_name = $q['queue_name'];
            $members = json_decode($q['members_json'] ?? '[]', true) ?: [];

            // STRICT ASSIGNMENT CHECK: Extension MUST be explicitly in members_json
            $is_assigned = in_array((string)$user_ext, array_map('strval', $members));

            // Only auto-login if explicitly assigned AND (either in last_queues or last_queues is empty)
            if ($is_assigned && (empty($last_queues) || in_array($q_name, $last_queues))) {
                @exec("asterisk -rx " . escapeshellarg("queue add member Local/$user_ext@from-internal-pbx/n to $q_name penalty 0 as \"Temsilci $user_ext\" state_interface hint:$user_ext@from-internal-pbx"), $out);

                // Asterisk restart/reload sonrası dinamik üyelik sıfırlanır ve
                // yeni eklenen üye varsayılan olarak PAUSE'SUZ (aktif) başlar.
                // Ajanın kendi PAUSED kaydı hâlâ varsa bunu Asterisk tarafına
                // da yansıt — yoksa moladaki bir ajana gerçek çağrı gidebilir.
                $stmt_chk = $db->prepare("SELECT pause_reason FROM cc_pause_logs WHERE agent_extension = ? AND status = 'PAUSED' ORDER BY id DESC LIMIT 1");
                $stmt_chk->execute([$user_ext]);
                $active_reason = $stmt_chk->fetchColumn();
                if ($active_reason === false) {
                    @exec("asterisk -rx " . escapeshellarg("queue unpause member Local/$user_ext@from-internal-pbx/n queue $q_name"), $out);
                } else {
                    @exec("asterisk -rx " . escapeshellarg("queue pause member Local/$user_ext@from-internal-pbx/n queue $q_name reason $active_reason"), $out);
                }
            } else {
                // If not assigned to this queue, strictly remove extension from Asterisk queue
                @exec("asterisk -rx " . escapeshellarg("queue remove member Local/$user_ext@from-internal-pbx/n from $q_name"), $out);
                @exec("asterisk -rx " . escapeshellarg("queue remove member PJSIP/$user_ext from $q_name"), $out);
            }
        }
    }
    echo json_encode(['success' => true, 'message' => 'Otomatik kuyruk kontrolü tamamlandı']);
    exit;
}
