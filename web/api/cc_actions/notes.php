<?php
// Call notes: get_call_note, get_pending_note, save_call_note (callcenter_notes table)
//
// So the agent can enter a note while the call is still going on (before a CDR/call_id
// exists), with an empty call_id the note is kept as a "pending" row (call_id IS NULL)
// tied to the agent's own extension (agent_extension). When the call ends and reaches the
// CDR, cdrs.php (my_cdrs) links this pending note to the call_id of the latest CDR
// automatically (claimPendingNote()). The Asterisk channel uniqueid is not used directly,
// because for calls coming through a queue the agent's own channel uniqueid does not match
// the uniqueid written to the CDR (which belongs to the caller side).
if (!defined('CC_DISPATCH_ACTIVE')) { http_response_code(403); exit; }

if ($action === 'get_call_note') {
    $call_id = trim($_GET['call_id'] ?? '');
    if ($call_id === '') {
        echo json_encode(['success' => false, 'error' => 'call_id zorunludur']);
        exit;
    }

    // Ownership check: the same pattern as in cdrs.php (apart from admin /
    // can_view_all_cdrs, everyone sees only the notes of their own calls) —
    // otherwise another agent's customer/note data could be read by guessing call_id.
    $can_view_all = ($user['role'] === 'admin' || !empty($user['can_view_all_cdrs']));
    if ($can_view_all) {
        $stmt = $db->prepare('SELECT id, call_id, customer_name, phone, disposition, notes, created_at FROM callcenter_notes WHERE call_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$call_id]);
    } else {
        $stmt = $db->prepare('SELECT id, call_id, customer_name, phone, disposition, notes, created_at FROM callcenter_notes WHERE call_id = ? AND agent_extension = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$call_id, $user_ext]);
    }
    $note = $stmt->fetch();

    echo json_encode(['success' => true, 'note' => $note ?: null]);
    exit;
}

if ($action === 'get_pending_note') {
    // When the modal is reopened during an active call (e.g. a page reload), brings back
    // the pending note entered earlier. The agent identity is always decided by the server
    // (the session's $user_ext); an extension number from the client is not trusted.
    if ($user_ext === '') {
        echo json_encode(['success' => false, 'error' => t('api_cc.err_ext')]);
        exit;
    }

    $stmt = $db->prepare('SELECT id, customer_name, phone, disposition, notes, created_at FROM callcenter_notes WHERE agent_extension = ? AND call_id IS NULL ORDER BY id DESC LIMIT 1');
    $stmt->execute([$user_ext]);
    $note = $stmt->fetch();

    echo json_encode(['success' => true, 'note' => $note ?: null]);
    exit;
}

if ($action === 'save_call_note') {
    $call_id = trim($_POST['call_id'] ?? '');
    $customer_name = trim($_POST['customer_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $disposition = trim($_POST['disposition'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($call_id !== '') {
        // A note for a finished call (from the CDR list): upsert by call_id.
        // Ownership check: apart from admin/can_view_all_cdrs, a user can only
        // update a note they WROTE THEMSELVES — otherwise another agent's note
        // could be overwritten by guessing call_id. Without an own note (e.g.
        // noting this call for the first time) a new row is added normally.
        $can_edit_all = ($user['role'] === 'admin' || !empty($user['can_view_all_cdrs']));
        if ($can_edit_all) {
            $stmt = $db->prepare('SELECT id FROM callcenter_notes WHERE call_id = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$call_id]);
        } else {
            $stmt = $db->prepare('SELECT id FROM callcenter_notes WHERE call_id = ? AND agent_extension = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$call_id, $user_ext]);
        }
        $existing_id = $stmt->fetchColumn();

        if ($existing_id) {
            $stmt = $db->prepare('UPDATE callcenter_notes SET customer_name = ?, phone = ?, disposition = ?, notes = ? WHERE id = ?');
            $stmt->execute([$customer_name, $phone, $disposition, $notes, $existing_id]);
        } else {
            $stmt = $db->prepare('INSERT INTO callcenter_notes (call_id, agent_extension, customer_name, phone, disposition, notes) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$call_id, ($user_ext !== '' ? $user_ext : null), $customer_name, $phone, $disposition, $notes]);
        }
    } else {
        // Active call: no call_id yet, keep it as a "pending" note tied to the agent
        if ($user_ext === '') {
            echo json_encode(['success' => false, 'error' => t('api_cc.err_note_ext')]);
            exit;
        }

        $stmt = $db->prepare('SELECT id FROM callcenter_notes WHERE agent_extension = ? AND call_id IS NULL ORDER BY id DESC LIMIT 1');
        $stmt->execute([$user_ext]);
        $existing_id = $stmt->fetchColumn();

        if ($existing_id) {
            $stmt = $db->prepare('UPDATE callcenter_notes SET customer_name = ?, phone = ?, disposition = ?, notes = ? WHERE id = ?');
            $stmt->execute([$customer_name, $phone, $disposition, $notes, $existing_id]);
        } else {
            $stmt = $db->prepare('INSERT INTO callcenter_notes (call_id, agent_extension, customer_name, phone, disposition, notes) VALUES (NULL, ?, ?, ?, ?, ?)');
            $stmt->execute([$user_ext, $customer_name, $phone, $disposition, $notes]);
        }
    }

    echo json_encode(['success' => true, 'message' => t('api_cc.note_saved')]);
    exit;
}
