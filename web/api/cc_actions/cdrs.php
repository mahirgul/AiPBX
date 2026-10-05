<?php
// The agent's call history: my_cdrs
if (!defined('CC_DISPATCH_ACTIVE')) { http_response_code(403); exit; }

if ($action === 'my_cdrs') {
    $can_listen = ($user['role'] === 'admin' || !empty($user['can_listen_recordings']));
    $can_view_all = ($user['role'] === 'admin' || !empty($user['can_view_all_cdrs']));

    // Link the "pending" note entered during the active call (by agent_extension, call_id IS NULL)
    // to this agent's most recently finished call (its CDR). A pending note entered in the last
    // 2 hours is looked for; anything older (a forgotten draft) is not linked automatically.
    if (!empty($user_ext)) {
        // agent_extension can now also show the CALLED side on calls not
        // routed through a queue (see the cdrs view update) — calls this agent
        // started show up in caller_num, so both are checked.
        $stmt_last_cdr = $db->prepare('SELECT call_id FROM cdrs WHERE (agent_extension = ? OR caller_num = ?) AND call_id IS NOT NULL ORDER BY start_time DESC LIMIT 1');
        $stmt_last_cdr->execute([$user_ext, $user_ext]);
        $last_call_id = $stmt_last_cdr->fetchColumn();

        if ($last_call_id) {
            $stmt_claim = $db->prepare("UPDATE callcenter_notes SET call_id = ? WHERE agent_extension = ? AND call_id IS NULL AND created_at >= (NOW() - INTERVAL 2 HOUR) ORDER BY id DESC LIMIT 1");
            $stmt_claim->execute([$last_call_id, $user_ext]);
        }
    }

    // The see-everything permission (admin / can_view_all_cdrs) used to be
    // computed and never used; and everyone without an extension saw ALL records.
    if ($can_view_all) {
        $stmt = $db->prepare('SELECT id, call_id, start_time, caller_num, agent_extension, agent_name, duration, billsec, status, recording_path FROM cdrs ORDER BY start_time DESC LIMIT 30');
        $stmt->execute();
    } elseif (empty($user_ext)) {
        echo json_encode(['success' => true, 'cdrs' => [], 'can_listen_recordings' => $can_listen, 'can_view_all_cdrs' => false]);
        exit;
    } else {
        $stmt = $db->prepare('SELECT id, call_id, start_time, caller_num, agent_extension, agent_name, duration, billsec, status, recording_path FROM cdrs WHERE (agent_extension = ? OR caller_num = ?) ORDER BY start_time DESC LIMIT 30');
        $stmt->execute([$user_ext, $user_ext]);
    }
    $cdrs = $stmt->fetchAll();

    // Add audio playback URL and check file existence
    foreach ($cdrs as &$c) {
        $has_rec = (!empty($c['recording_path']) && file_exists($c['recording_path']));
        $c['has_recording'] = $has_rec;
        $c['can_listen'] = $can_listen || ($user_ext !== '' && ($c['agent_extension'] === $user_ext || $c['caller_num'] === $user_ext));
        $c['audio_url'] = $has_rec ? '/api/cc_audio.php?id=' . $c['id'] : null;
    }
    unset($c);

    echo json_encode(['success' => true, 'cdrs' => $cdrs, 'can_listen_recordings' => $can_listen, 'can_view_all_cdrs' => $can_view_all]);
    exit;
}
