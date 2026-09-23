<?php
/**
 * Ring Groups (Çalma Grupları) Service
 */

require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/internal_numbers.php';
require_once dirname(__DIR__) . '/asterisk_sync.php';

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
                throw new \Exception("Grup Dahili Numarası ve Grup Adı zorunludur!");
            }

            if (empty($numbers_list)) {
                throw new \Exception("Çalacak en az bir dahili veya harici numara girmelisiniz!");
            }

            // Çakışma kontrolü (dahililer, özellik kodları, IVR, kuyruk vb. ile)
            internalNumberValidate($group_number, 'ring_group', $id);

            $db = getDB();
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE pbx_ring_groups SET group_number = ?, internal_number = ?, name = ?, numbers_list = ?, ring_strategy = ?, ring_timeout = ?, cid_prefix = ?, fallback_dest_type = ?, fallback_dest_id = ?, record_call = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$group_number, $group_number, $name, $numbers_list, $ring_strategy, $ring_timeout, $cid_prefix, $fallback_dest_type, $fallback_dest_id, $record_call, $is_active, $id]);
            } else {
                $stmt = $db->prepare("INSERT INTO pbx_ring_groups (group_number, internal_number, name, numbers_list, ring_strategy, ring_timeout, cid_prefix, fallback_dest_type, fallback_dest_id, record_call, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$group_number, $group_number, $name, $numbers_list, $ring_strategy, $ring_timeout, $cid_prefix, $fallback_dest_type, $fallback_dest_id, $record_call, $is_active]);
                $id = (int)$db->lastInsertId();
            }

            markPendingSync('ring_groups', 'ring_group', $id, "{$name} ({$group_number})", $id > 0 ? 'update' : 'create', $_SESSION['user_id'] ?? null);
            markPendingSync('internal_numbers', 'ring_group', $id, "{$name} ({$group_number})", $id > 0 ? 'update' : 'create', $_SESSION['user_id'] ?? null);
            return "Çalma grubu başarıyla kaydedildi.";
        });
    }

    public static function deleteRingGroup($id, $csrf_token = '') {
        return PBXHelper::handleAction($csrf_token, function() use ($id) {
            $id = intval($id);
            $db = getDB();

            // Referans kontrolü (DID, IVR vb.)
            $refs = [];
            $dids = $db->prepare("SELECT did_number FROM pbx_dids WHERE dest_type = 'ring_group' AND dest_id = ?");
            $dids->execute([$id]);
            if ($d = $dids->fetchAll(PDO::FETCH_COLUMN)) {
                $refs[] = "Gelen Rotalar (" . implode(', ', $d) . ")";
            }

            if (!empty($refs)) {
                throw new \Exception("Bu çalma grubu şu modüllerde hedef olarak kullanıldığı için silinemez: " . implode(', ', $refs));
            }

            $rg = self::getRingGroup($id);
            $label = $rg ? "{$rg['name']} ({$rg['group_number']})" : "Grup #{$id}";

            $del = $db->prepare("DELETE FROM pbx_ring_groups WHERE id = ?");
            $del->execute([$id]);

            markPendingSync('ring_groups', 'ring_group', $id, $label, 'delete', $_SESSION['user_id'] ?? null);
            markPendingSync('internal_numbers', 'ring_group', $id, $label, 'delete', $_SESSION['user_id'] ?? null);
            return "Çalma grubu silindi.";
        });
    }
}
