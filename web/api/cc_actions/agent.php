<?php
// Agent session actions: login, logout, pause, unpause, get_status, auto_login
if (!defined('CC_DISPATCH_ACTIVE')) { http_response_code(403); exit; }
require_once __DIR__ . '/../../src/queue_helper.php';

if ($action === 'login') {
    if (!empty($user_ext)) {
        $stmt = $db->query("SELECT queue_name, members_json FROM pbx_queues WHERE is_active = 1");
        $assigned = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $q_row) {
            $mems = json_decode($q_row['members_json'] ?? '[]', true) ?: [];
            if (in_array((string)$user_ext, array_map('strval', $mems))) {
                $assigned[] = $q_row['queue_name'];
            }
        }

        if (empty($assigned)) {
            echo json_encode(['success' => false, 'error' => sprintf(t('api_cc.err_no_queue'), $user_ext)]);
            exit;
        }

        $target_q = trim($_POST['queue_name'] ?? '');
        $queues_to_join = (!empty($target_q) && in_array($target_q, $assigned)) ? [$target_q] : $assigned;

        foreach ($queues_to_join as $qn) {
            QueueHelper::setMembership($user_ext, $qn, true);
        }

        // Close any lingering active break logs
        $stmt = $db->prepare("UPDATE cc_pause_logs SET end_time = NOW(), duration = TIMESTAMPDIFF(SECOND, start_time, NOW()), status = 'COMPLETED' WHERE agent_extension = ? AND status = 'PAUSED'");
        $stmt->execute([$user_ext]);
    }
    echo json_encode(['success' => true, 'message' => t('api_cc.logged_in')]);
    exit;
}

if ($action === 'logout') {
    $static_q = [];
    if (!empty($user_ext)) {
        $stmt = $db->query("SELECT queue_name FROM pbx_queues WHERE is_active = 1");
        $all_q = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $target_q = trim($_POST['queue_name'] ?? '');
        $static_q = QueueHelper::staticQueuesOf($user_ext);
        if (!empty($target_q) && in_array($target_q, $static_q, true)) {
            echo json_encode(['success' => false, 'error' => t('api_cc.err_static')]);
            exit;
        }
        $queues_to_leave = array_diff((!empty($target_q)) ? [$target_q] : $all_q, $static_q);

        foreach ($queues_to_leave as $qn) {
            QueueHelper::setMembership($user_ext, $qn, false);
        }

        // Still a member (and maybe paused) in the queues where the agent is static — the pause record must stay open.
        if (empty($static_q)) {
            $stmt = $db->prepare("UPDATE cc_pause_logs SET end_time = NOW(), duration = TIMESTAMPDIFF(SECOND, start_time, NOW()), status = 'COMPLETED' WHERE agent_extension = ? AND status = 'PAUSED'");
            $stmt->execute([$user_ext]);
        }
    }
    if (!empty($static_q)) {
        echo json_encode(['success' => true, 'message' => t('api_cc.logged_out_dynamic')]);
        exit;
    }
    echo json_encode(['success' => true, 'message' => t('api_cc.logged_out')]);
    exit;
}

if ($action === 'pause') {
    $reason = trim($_POST['reason'] ?? 'Mola');
    if (empty($reason)) $reason = 'Mola';

    if (!empty($user_ext)) {
        // "queue pause member X reason Y" used to be sent here — Asterisk
        // does not accept a reason without a queue name, so the command was
        // rejected with "Usage" and calls kept going to the paused agent.
        QueueHelper::pauseInAsterisk((string)$user_ext, $reason);

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
    echo json_encode(['success' => true, 'message' => sprintf(t('api_cc.break_started'), $reason), 'reason' => $reason]);
    exit;
}

if ($action === 'unpause') {
    if (!empty($user_ext)) {
        // Asterisk: without a specific queue name, the member is unpaused in ALL queues it belongs to
        @exec("asterisk -rx " . escapeshellarg("queue unpause member Local/$user_ext@from-internal-pbx/n"), $out);
        @exec("asterisk -rx " . escapeshellarg("queue unpause member PJSIP/$user_ext"), $out);

        // Complete active break log
        withAgentPauseLock($db, $user_ext, function() use ($db, $user_ext) {
            $stmt = $db->prepare("UPDATE cc_pause_logs SET end_time = NOW(), duration = TIMESTAMPDIFF(SECOND, start_time, NOW()), status = 'COMPLETED' WHERE agent_extension = ? AND status = 'PAUSED'");
            $stmt->execute([$user_ext]);
        });
    }
    echo json_encode(['success' => true, 'message' => t('api_cc.break_ended')]);
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
    $raw_reasons = $settings['cc_break_reasons'] ?? t('api_cc.default_break_reasons');
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
                @exec("asterisk -rx " . escapeshellarg("queue add member Local/$user_ext@from-internal-pbx/n to $q_name penalty 0 as \"Agent $user_ext\" state_interface hint:$user_ext@from-internal-pbx"), $out);

                // After an Asterisk restart/reload the dynamic membership is
                // reset and a newly added member starts UNPAUSED (active) by
                // default. If the agent's own PAUSED record still exists,
                // reflect it on the Asterisk side too — otherwise a real call
                // could go to a paused agent.
                $stmt_chk = $db->prepare("SELECT pause_reason FROM cc_pause_logs WHERE agent_extension = ? AND status = 'PAUSED' ORDER BY id DESC LIMIT 1");
                $stmt_chk->execute([$user_ext]);
                $active_reason = $stmt_chk->fetchColumn();
                if ($active_reason === false) {
                    @exec("asterisk -rx " . escapeshellarg("queue unpause member Local/$user_ext@from-internal-pbx/n queue $q_name"), $out);
                } else {
                    // The reason must be quoted: an unquoted reason with spaces
                    // like "Lunch break" was rejected by the CLI with "Usage"
                    // and the paused agent stayed ACTIVE in the queue.
                    $active_reason_cli = str_replace('"', '', (string)$active_reason);
                    @exec("asterisk -rx " . escapeshellarg("queue pause member Local/$user_ext@from-internal-pbx/n queue $q_name reason \"$active_reason_cli\""), $out);
                }
            } else {
                // If not assigned to this queue, strictly remove extension from Asterisk queue
                @exec("asterisk -rx " . escapeshellarg("queue remove member Local/$user_ext@from-internal-pbx/n from $q_name"), $out);
                @exec("asterisk -rx " . escapeshellarg("queue remove member PJSIP/$user_ext from $q_name"), $out);
            }
        }
    }
    echo json_encode(['success' => true, 'message' => t('api_cc.auto_check_done')]);
    exit;
}
