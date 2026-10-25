<?php
/**
 * Ring groups service
 */

require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/internal_numbers.php';
require_once dirname(__DIR__) . '/asterisk_sync.php';

/**
 * PBX → Ring groups: save/delete; marks ring_groups and internal_numbers for Apply
 * (SyncRingGroups writes extensions_ringgroups.conf).
 */
class RingGroupService {
    public static function getRingGroups() {
        $db = getDB();
        $stmt = $db->query("SELECT * FROM pbx_ring_groups ORDER BY id ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getRingGroup($id) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM pbx_ring_groups WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function saveRingGroup($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data) {
            $id = intval($data['id'] ?? 0);
            $group_number = internalNumberSanitize($data['group_number'] ?? '');
            $name = trim($data['name'] ?? '');
            $numbers_list = trim($data['numbers_list'] ?? '');
            $ring_strategy = in_array($data['ring_strategy'] ?? '', ['ringall', 'sequential', 'random'], true) ? $data['ring_strategy'] : 'ringall';
            $ring_timeout = max(5, min(300, intval($data['ring_timeout'] ?? 30)));
            $cid_prefix = trim($data['cid_prefix'] ?? '');
            $fallback_dest_type = sanitizeDestType($data['fallback_dest_type'] ?? 'hangup');
            $fallback_dest_id = trim($data['fallback_dest_id'] ?? 'busy');
            $record_call = isset($data['record_call']) ? intval($data['record_call']) : 1;
            $is_active = isset($data['is_active']) ? intval($data['is_active']) : 1;

            if (empty($group_number) || empty($name)) {
                throw new \Exception(t('srv_rg.err_required'));
            }

            if (empty($numbers_list)) {
                throw new \Exception(t('srv_rg.err_members'));
            }

            // Conflict check (with extensions, feature codes, IVRs, queues etc.)
            internalNumberValidate($group_number, 'ring_group', $id);

            $db = getDB();
            $is_new = ($id <= 0);
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE pbx_ring_groups SET group_number = ?, internal_number = ?, name = ?, numbers_list = ?, ring_strategy = ?, ring_timeout = ?, cid_prefix = ?, fallback_dest_type = ?, fallback_dest_id = ?, record_call = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$group_number, $group_number, $name, $numbers_list, $ring_strategy, $ring_timeout, $cid_prefix, $fallback_dest_type, $fallback_dest_id, $record_call, $is_active, $id]);
            } else {
                $stmt = $db->prepare("INSERT INTO pbx_ring_groups (group_number, internal_number, name, numbers_list, ring_strategy, ring_timeout, cid_prefix, fallback_dest_type, fallback_dest_id, record_call, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$group_number, $group_number, $name, $numbers_list, $ring_strategy, $ring_timeout, $cid_prefix, $fallback_dest_type, $fallback_dest_id, $record_call, $is_active]);
                $id = (int)$db->lastInsertId();
            }

            markPendingSync('ring_groups', 'ring_group', $id, "{$name} ({$group_number})", $is_new ? 'create' : 'update', $_SESSION['user_id'] ?? null);
            markPendingSync('internal_numbers', 'ring_group', $id, "{$name} ({$group_number})", $is_new ? 'create' : 'update', $_SESSION['user_id'] ?? null);
            return t('srv_rg.saved');
        });
    }

    public static function deleteRingGroup($id, $csrf_token = '') {
        return PBXHelper::handleAction($csrf_token, function() use ($id) {
            $id = intval($id);
            $db = getDB();

            // Reference check: the polymorphic dest_type/dest_id fields cannot
            // be a DB-level FK (same approach as IVRService::deleteIVR() and
            // QueueService::deleteQueue()), so every table that can point at
            // a ring group is checked here. The old DID-only check orphaned a
            // live Goto(app-ringgroup-N) from IVRs, time conditions,
            // announcements and fallbacks.
            $refs = [];
            $dids = $db->prepare("SELECT did_number FROM pbx_dids WHERE dest_type = 'ring_group' AND dest_id = ?");
            $dids->execute([$id]);
            if ($d = $dids->fetchAll(PDO::FETCH_COLUMN)) {
                $refs[] = "Inbound routes (" . implode(', ', $d) . ")";
            }
            $sid = (string) $id;
            $count = DBHelper::fetchColumn(
                "SELECT COUNT(*) FROM (
                    SELECT id FROM pbx_ivr_entries WHERE dest_type = 'ring_group' AND dest_id = ?
                    UNION ALL
                    SELECT id FROM pbx_ivrs WHERE (timeout_dest_type = 'ring_group' AND timeout_dest_id = ?) OR (invalid_dest_type = 'ring_group' AND invalid_dest_id = ?)
                    UNION ALL
                    SELECT id FROM pbx_time_conditions WHERE (match_dest_type = 'ring_group' AND match_dest_id = ?) OR (nomatch_dest_type = 'ring_group' AND nomatch_dest_id = ?) OR rules_json LIKE ?
                    UNION ALL
                    SELECT id FROM pbx_announcements WHERE post_dest_type = 'ring_group' AND post_dest_id = ?
                    UNION ALL
                    SELECT id FROM pbx_boss_secretary_groups WHERE fallback_dest_type = 'ring_group' AND fallback_dest_id = ?
                    UNION ALL
                    SELECT id FROM pbx_ring_groups WHERE id != ? AND fallback_dest_type = 'ring_group' AND fallback_dest_id = ?
                    UNION ALL
                    SELECT id FROM pbx_web_widgets WHERE dest_type = 'ring_group' AND dest_id = ?
                ) refs",
                [$sid, $sid, $sid, $sid, $sid, '%"match_dest_type":"ring_group","match_dest_id":"' . $sid . '"%', $sid, $sid, $id, $sid, $sid]
            );
            if ($count > 0) {
                $refs[] = "IVR / time condition / announcement / fallback destinations ({$count})";
            }

            if (!empty($refs)) {
                throw new \Exception(sprintf(t('srv_rg.err_in_use'), implode(', ', $refs)));
            }

            $rg = self::getRingGroup($id);
            $label = $rg ? "{$rg['name']} ({$rg['group_number']})" : "Group #{$id}";

            $del = $db->prepare("DELETE FROM pbx_ring_groups WHERE id = ?");
            $del->execute([$id]);

            markPendingSync('ring_groups', 'ring_group', $id, $label, 'delete', $_SESSION['user_id'] ?? null);
            markPendingSync('internal_numbers', 'ring_group', $id, $label, 'delete', $_SESSION['user_id'] ?? null);
            return t('srv_rg.deleted');
        });
    }
}
