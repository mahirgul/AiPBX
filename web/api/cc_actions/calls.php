<?php
// Call actions: originate, hangup, transfer, hold, pickup_call
if (!defined('CC_DISPATCH_ACTIVE')) { http_response_code(403); exit; }

if ($action === 'originate') {
    $to = preg_replace('/[^0-9+]/', '', $_POST['to'] ?? '');
    if (empty($to) || empty($user_ext)) {
        echo json_encode(['success' => false, 'error' => t('api_cc.err_target')]);
        exit;
    }

    // Dual endpoint: there is no endpoint named PJSIP/<ext>; the call goes through
    // the dialplan via a Local channel (from-internal-pbx → PJSIP_DIAL_CONTACTS → all devices)
    // Outbound route group: if the user is assigned to a group other than 1, that group's context is used
    $outbound_grp = max(1, intval($user['outbound_group'] ?? 1));
    $outbound_context = ($outbound_grp > 1) ? "from-internal-g{$outbound_grp}" : 'cc-internal';

    $cmd = "Action: Originate\r\n" .
           "Channel: Local/$user_ext@cc-internal\r\n" .
           "Context: $outbound_context\r\n" .
           "Exten: $to\r\n" .
           "Priority: 1\r\n" .
           "CallerID: Agent $user_ext <$user_ext>\r\n" .
           "Variable: __SAVED_DST=$to\r\n\r\n";

    $res = sendAMICommand($cmd);
    if (!$res || strpos($res, 'Response: Success') === false) {
        echo json_encode([
            'success' => false,
            'error' => sprintf(t('api_cc.err_not_registered'), $user_ext)
        ]);
        exit;
    }

    echo json_encode(['success' => true, 'message' => sprintf(t('api_cc.calling'), $user_ext, $to)]);
    exit;
}

if ($action === 'hangup') {
    // The real hangup is already done in the browser with JsSIP session.terminate();
    // this is the AMI fallback that kicks in if the WebSocket/signalling fails.
    $channels = findAgentChannels($user_ext);
    if (empty($channels)) {
        // The client-side termination may already have succeeded; do not count it as an error.
        echo json_encode(['success' => true, 'message' => t('api_cc.no_channel')]);
        exit;
    }
    $ok = false;
    foreach ($channels as $ch) {
        $res = sendAMICommand("Action: Hangup\r\nChannel: $ch\r\n\r\n");
        if ($res && strpos($res, 'Response: Success') !== false) $ok = true;
    }
    echo json_encode(['success' => $ok, 'message' => $ok ? t('api_cc.hung_up') : t('api_cc.err_hangup')]);
    exit;
}

if ($action === 'transfer') {
    $to = preg_replace('/[^0-9+]/', '', $_POST['to'] ?? '');
    if (empty($to) || empty($user_ext)) {
        echo json_encode(['success' => false, 'error' => t('api_cc.err_number')]);
        exit;
    }
    // The real transfer is already done in the browser with JsSIP session.refer();
    // this is the AMI fallback that kicks in if signalling fails.
    //
    // THE CHANNEL TO REDIRECT IS THE CALLER'S, NOT the agent's.
    // ALL agent channels found by findAgentChannels() (the PJSIP device leg
    // + both halves of the Local pair) used to be Redirected one by one. A
    // single-channel Redirect pulls that channel out of the bridge; the
    // caller is left alone, drops out of Queue() and hangs up in the `h`
    // extension — in the 2026-09-15 live log the caller got Hangup in the
    // same second the agent went to 8915.
    $caller_channel = findCallerChannelForAgent($user_ext);
    if (empty($caller_channel)) {
        echo json_encode(['success' => false, 'error' => t('api_cc.err_no_call')]);
        exit;
    }
    $res = sendAMICommand("Action: Redirect\r\nChannel: $caller_channel\r\nContext: cc-internal\r\nExten: $to\r\nPriority: 1\r\n\r\n");
    $ok = ($res && strpos($res, 'Response: Success') !== false);
    echo json_encode(['success' => $ok, 'message' => $ok ? sprintf(t('api_cc.transferring'), $to) : t('api_cc.err_transfer')]);
    exit;
}

if ($action === 'hold') {
    // The real hold is done in the browser with JsSIP session.hold() (re-INVITE,
    // sendonly) — this action does nothing extra on the server (the old code that
    // closed the channel BY MISTAKE was removed). It only confirms to the client.
    echo json_encode(['success' => true, 'message' => t('api_cc.on_hold')]);
    exit;
}

if ($action === 'pickup_call') {
    // Asterisk channel names consist only of [A-Za-z0-9/_.@;-] characters
    // (e.g. PJSIP/3001-00000012, Local/3001@cc-internal-00000001;1) — every
    // character outside this whitelist (especially \r\n) is filtered here to
    // prevent AMI command injection (it is embedded raw in the Action:/Channel: lines).
    $target_channel = preg_replace('/[^A-Za-z0-9\/_.@;-]/', '', trim($_POST['channel'] ?? ''));

    if (empty($target_channel) || empty($user_ext)) {
        echo json_encode(['success' => false, 'error' => t('api_cc.err_channel')]);
        exit;
    }

    // Queue membership check: so a regular cc_agent cannot take a call from a
    // queue they do not belong to by seeing it in the list and guessing/copying
    // the channel name, the queue the target channel is really waiting in is
    // found from the "queue show" output and the caller's membership of that
    // queue is checked. Admin/cc_manager (supervisor roles) can take calls from
    // any queue and are exempt from this check.
    if (!in_array($user['role'] ?? '', ['admin', 'cc_manager'], true)) {
        @exec("asterisk -rx " . escapeshellarg("queue show"), $qs_output);
        $found_queue = null;
        $cur_q = '';
        if (is_array($qs_output)) {
            foreach ($qs_output as $qline) {
                $qclean = preg_replace("/\x1b\[[0-9;]*[a-zA-Z]/", "", $qline);
                if (preg_match('/^([a-zA-Z0-9_-]+)\s+has\s+\d+\s+calls/i', trim($qclean), $qm)) {
                    $cur_q = $qm[1];
                }
                // Only waiting-caller lines ("1. PJSIP/trunk-0000002a (wait: …")
                // and the full channel name — a substring match would also fit …-0000001 to …-00000012.
                if ($cur_q !== '' && preg_match('/^\d+\.\s+(\S+)/', trim($qclean), $cm) && $cm[1] === $target_channel) {
                    $found_queue = $cur_q;
                    break;
                }
            }
        }
        // A channel not waiting in any queue (someone else's ongoing call, an
        // extension leg…) cannot be taken — the check used to be skipped in
        // that case and any channel could be redirected to the agent.
        if ($found_queue === null) {
            echo json_encode(['success' => false, 'error' => t('api_cc.err_not_waiting')]);
            exit;
        }
        $mem_stmt = $db->prepare("SELECT members_json FROM pbx_queues WHERE queue_name = ? AND is_active = 1");
        $mem_stmt->execute([$found_queue]);
        $members = json_decode($mem_stmt->fetchColumn() ?: '[]', true) ?: [];
        if (!in_array((string)$user_ext, array_map('strval', $members), true)) {
            echo json_encode(['success' => false, 'error' => t('api_cc.err_not_member')]);
            exit;
        }
    }

    // Redirect waiting queue caller to agent's extension in cc-internal
    $res = sendAMICommand("Action: Redirect\r\nChannel: $target_channel\r\nContext: cc-internal\r\nExten: $user_ext\r\nPriority: 1\r\n\r\n");
    if (!$res || strpos($res, 'Response: Success') === false) {
        echo json_encode(['success' => false, 'error' => t('api_cc.err_taken')]);
        exit;
    }

    echo json_encode(['success' => true, 'message' => sprintf(t('api_cc.picked_up'), $user_ext)]);
    exit;
}
