<?php
/**
 * Calling Permission Groups & Rules Service
 */

require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/asterisk_sync.php';

class DialPermissionService {
    public static function getGroups() {
        $db = getDB();
        $stmt = $db->query("SELECT g.*, 
            (SELECT COUNT(*) FROM pbx_permission_rules r WHERE r.group_id = g.id) AS rules_count,
            (SELECT COUNT(*) FROM sys_users u WHERE u.permission_group_id = g.id) AS users_count
            FROM pbx_permission_groups g ORDER BY g.id ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getGroup($id) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM pbx_permission_groups WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function saveGroup($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data) {
            $id = intval($data['id'] ?? 0);
            $group_name = trim($data['group_name'] ?? '');
            $description = trim($data['description'] ?? '');
            $default_action = ($data['default_action'] ?? 'allow') === 'deny' ? 'deny' : 'allow';
            $is_active = isset($data['is_active']) ? intval($data['is_active']) : 1;

            if (empty($group_name)) {
                throw new \Exception("Yetki grubu adı zorunludur!");
            }

            $db = getDB();
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE pbx_permission_groups SET group_name = ?, description = ?, default_action = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$group_name, $description, $default_action, $is_active, $id]);
            } else {
                $stmt = $db->prepare("INSERT INTO pbx_permission_groups (group_name, description, default_action, is_active) VALUES (?, ?, ?, ?)");
                $stmt->execute([$group_name, $description, $default_action, $is_active]);
                $id = (int)$db->lastInsertId();
            }

            markPendingSync('permissions', 'group', $id, $group_name, $id > 0 ? 'update' : 'create', $_SESSION['user_id'] ?? null);
            return "Arama yetki grubu başarıyla kaydedildi.";
        });
    }

    public static function deleteGroup($id, $csrf_token = '') {
        return PBXHelper::handleAction($csrf_token, function() use ($id) {
            $id = intval($id);
            if ($id <= 1) {
                throw new \Exception("Varsayılan grup silinemez!");
            }

            $db = getDB();
            // Reassign extensions using this group back to group 1
            $upd = $db->prepare("UPDATE sys_users SET permission_group_id = 1 WHERE permission_group_id = ?");
            $upd->execute([$id]);

            $del = $db->prepare("DELETE FROM pbx_permission_groups WHERE id = ?");
            $del->execute([$id]);

            markPendingSync('permissions', 'group', $id, "Grup #{$id}", 'delete', $_SESSION['user_id'] ?? null);
            return "Arama yetki grubu silindi.";
        });
    }

    public static function getRules($groupId) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM pbx_permission_rules WHERE group_id = ? ORDER BY priority ASC, id ASC");
        $stmt->execute([$groupId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function saveRule($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data) {
            $id = intval($data['id'] ?? 0);
            $group_id = intval($data['group_id'] ?? 0);
            $pattern = trim($data['pattern'] ?? '');
            $pattern_type = ($data['pattern_type'] ?? 'prefix') === 'exact' ? 'exact' : 'prefix';
            $action = ($data['action'] ?? 'deny') === 'allow' ? 'allow' : 'deny';
            $priority = intval($data['priority'] ?? 10);
            $description = trim($data['description'] ?? '');

            if ($group_id <= 0 || empty($pattern)) {
                throw new \Exception("Grup ve Numara Kalıbı zorunludur!");
            }

            $db = getDB();
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE pbx_permission_rules SET pattern = ?, pattern_type = ?, action = ?, priority = ?, description = ? WHERE id = ? AND group_id = ?");
                $stmt->execute([$pattern, $pattern_type, $action, $priority, $description, $id, $group_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO pbx_permission_rules (group_id, pattern, pattern_type, action, priority, description) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$group_id, $pattern, $pattern_type, $action, $priority, $description]);
                $id = (int)$db->lastInsertId();
            }

            markPendingSync('permissions', 'rule', $id, "Kural {$pattern}", $id > 0 ? 'update' : 'create', $_SESSION['user_id'] ?? null);
            return "Kural başarıyla kaydedildi.";
        });
    }

    public static function deleteRule($ruleId, $csrf_token = '') {
        return PBXHelper::handleAction($csrf_token, function() use ($ruleId) {
            $ruleId = intval($ruleId);
            $db = getDB();
            $del = $db->prepare("DELETE FROM pbx_permission_rules WHERE id = ?");
            $del->execute([$ruleId]);

            markPendingSync('permissions', 'rule', $ruleId, "Kural #{$ruleId}", 'delete', $_SESSION['user_id'] ?? null);
            return "Kural silindi.";
        });
    }
}
