<?php
require_once __DIR__ . '/../internal_numbers.php';
/**
 * Hangup action (call ending) service
 */

class HangupActionService {
    public static function saveHangupAction($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $action_id = intval($data['hangup_id'] ?? 0);
            $action_key = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($data['action_key'] ?? ''));
            $title = trim($data['title'] ?? '');
            $action_type = trim($data['action_type'] ?? 'hangup');
            $announcement_id = !empty($data['announcement_id']) ? intval($data['announcement_id']) : null;

            if (empty($action_key) || empty($title)) {
                throw new \Exception(t('srv_hangup.err_required'));
            }

            $internal_number = internalNumberSanitize($data['internal_number'] ?? '');
            $old_number = (string) (DBHelper::fetchColumn(
                "SELECT internal_number FROM pbx_hangup_actions WHERE id = ?", [$action_id]
            ) ?? '');
            assertInternalNumberAvailable($internal_number, 'hangup', $action_id);
            $number_column = $internal_number !== '' ? $internal_number : null;

            $db = getDB();
            $is_new = ($action_id <= 0);
            if ($action_id > 0) {
                $stmt = $db->prepare("UPDATE pbx_hangup_actions SET title = ?, action_type = ?, announcement_id = ?, internal_number = ? WHERE id = ?");
                $stmt->execute([$title, $action_type, $announcement_id, $number_column, $action_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO pbx_hangup_actions (action_key, title, action_type, announcement_id, internal_number) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$action_key, $title, $action_type, $announcement_id, $number_column]);
                $action_id = $db->lastInsertId();
            }
            // The 'hangup' branch of buildDestinationLines() reads this table
            // when generating the inbound route/IVR/time condition/queue
            // fallback — all three domains are affected AT ONCE, so each gets its
            // own mark (so the bug found in the 2026-08-21 audit does not repeat
            // in the deferred system).
            $uid = $_SESSION['user_id'] ?? null;
            $action = $is_new ? 'create' : 'update';
            markPendingSync('inbound_dialplan', 'hangup_action', $action_id, "Hangup action: {$title}", $action, $uid);
            markPendingSync('ivrs', 'hangup_action', $action_id, "Hangup action: {$title}", $action, $uid);
            markPendingSync('time_conditions', 'hangup_action', $action_id, "Hangup action: {$title}", $action, $uid);
            // When a number was added, changed or removed, the internal targets context must be rebuilt too.
            if ($internal_number !== $old_number) {
                markPendingSync('internal_numbers', 'hangup_action', $action_id,
                    "Internal number: " . ($internal_number !== '' ? $internal_number : 'removed'),
                    'update', $uid);
            }
            return sprintf(t('srv_hangup.saved'), $title);
        });
    }

    public static function deleteHangupAction($hangup_id, $csrf_token) {
        return PBXHelper::handleAction($csrf_token, function () use ($hangup_id) {
            $action_id = intval($hangup_id);
            if ($action_id > 0) {
                $db = getDB();
                $action_key = (string) (DBHelper::fetchColumn("SELECT action_key FROM pbx_hangup_actions WHERE id = ?", [$action_id]) ?? '');
                if ($action_key !== '' && !in_array($action_key, ['hangup', 'busy', 'congestion'], true)) {
                    $refs = (int) DBHelper::fetchColumn(
                        "SELECT COUNT(*) FROM (
                            SELECT id FROM pbx_dids WHERE dest_type = 'hangup' AND dest_id = ?
                            UNION ALL
                            SELECT id FROM pbx_ivr_entries WHERE dest_type = 'hangup' AND dest_id = ?
                            UNION ALL
                            SELECT id FROM pbx_ivrs WHERE (timeout_dest_type = 'hangup' AND timeout_dest_id = ?) OR (invalid_dest_type = 'hangup' AND invalid_dest_id = ?)
                            UNION ALL
                            SELECT id FROM pbx_time_conditions WHERE (match_dest_type = 'hangup' AND match_dest_id = ?) OR (nomatch_dest_type = 'hangup' AND nomatch_dest_id = ?)
                            UNION ALL
                            SELECT id FROM pbx_announcements WHERE post_dest_type = 'hangup' AND post_dest_id = ?
                            UNION ALL
                            SELECT id FROM pbx_boss_secretary_groups WHERE fallback_dest_type = 'hangup' AND fallback_dest_id = ?
                            UNION ALL
                            SELECT id FROM pbx_ring_groups WHERE fallback_dest_type = 'hangup' AND fallback_dest_id = ?
                            UNION ALL
                            SELECT id FROM pbx_ivr_data_steps WHERE (found_dest_type = 'hangup' AND found_dest_id = ?) OR (not_found_dest_type = 'hangup' AND not_found_dest_id = ?) OR (timeout_dest_type = 'hangup' AND timeout_dest_id = ?)
                        ) refs",
                        [$action_key, $action_key, $action_key, $action_key, $action_key, $action_key, $action_key, $action_key, $action_key, $action_key, $action_key, $action_key]
                    );
                    if ($refs > 0) {
                        throw new \Exception(t('srv_tc.err_in_use'));
                    }
                }
                $title = DBHelper::fetchColumn("SELECT title FROM pbx_hangup_actions WHERE id = ?", [$action_id]);
                $removed_number = (string) (DBHelper::fetchColumn("SELECT internal_number FROM pbx_hangup_actions WHERE id = ?", [$action_id]) ?? '');
                $stmt = $db->prepare("DELETE FROM pbx_hangup_actions WHERE id = ? AND action_key NOT IN ('hangup', 'busy', 'congestion')");
                $stmt->execute([$action_id]);
                $uid = $_SESSION['user_id'] ?? null;
                $label = "Hangup action: " . ($title ?: $action_id) . " (deleted)";
                markPendingSync('inbound_dialplan', 'hangup_action', $action_id, $label, 'delete', $uid);
                markPendingSync('ivrs', 'hangup_action', $action_id, $label, 'delete', $uid);
                markPendingSync('time_conditions', 'hangup_action', $action_id, $label, 'delete', $uid);
                if ($removed_number !== '') {
                    markPendingSync('internal_numbers', 'hangup_action', $action_id,
                        "Internal number removed: {$removed_number}", 'delete', $uid);
                }
                return t('srv_hangup.deleted');
            }
            return '';
        });
    }
}
