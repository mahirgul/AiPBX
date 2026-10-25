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
                throw new \Exception(t('srv_dialperm.err_name'));
            }

            $db = getDB();
            $is_new = ($id <= 0);
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE pbx_permission_groups SET group_name = ?, description = ?, default_action = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$group_name, $description, $default_action, $is_active, $id]);
            } else {
                $stmt = $db->prepare("INSERT INTO pbx_permission_groups (group_name, description, default_action, is_active) VALUES (?, ?, ?, ?)");
                $stmt->execute([$group_name, $description, $default_action, $is_active]);
                $id = (int)$db->lastInsertId();
            }

            markPendingSync('permissions', 'group', $id, $group_name, $is_new ? 'create' : 'update', $_SESSION['user_id'] ?? null);
            return t('srv_dialperm.saved');
        });
    }

    public static function deleteGroup($id, $csrf_token = '') {
        return PBXHelper::handleAction($csrf_token, function() use ($id) {
            $id = intval($id);
            if ($id <= 1) {
                throw new \Exception(t('srv_dialperm.err_default'));
            }

            $db = getDB();
            // Reassign extensions using this group back to group 1
            $upd = $db->prepare("UPDATE sys_users SET permission_group_id = 1 WHERE permission_group_id = ?");
            $upd->execute([$id]);

            $del = $db->prepare("DELETE FROM pbx_permission_groups WHERE id = ?");
            $del->execute([$id]);

            markPendingSync('permissions', 'group', $id, "Group #{$id}", 'delete', $_SESSION['user_id'] ?? null);
            return t('srv_dialperm.deleted');
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
                throw new \Exception(t('srv_dialperm.err_rule'));
            }

            $db = getDB();
            $is_new = ($id <= 0);
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE pbx_permission_rules SET pattern = ?, pattern_type = ?, action = ?, priority = ?, description = ? WHERE id = ? AND group_id = ?");
                $stmt->execute([$pattern, $pattern_type, $action, $priority, $description, $id, $group_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO pbx_permission_rules (group_id, pattern, pattern_type, action, priority, description) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$group_id, $pattern, $pattern_type, $action, $priority, $description]);
                $id = (int)$db->lastInsertId();
            }

            markPendingSync('permissions', 'rule', $id, "Rule {$pattern}", $is_new ? 'create' : 'update', $_SESSION['user_id'] ?? null);
            return t('srv_dialperm.rule_saved');
        });
    }

    public static function deleteRule($ruleId, $groupId, $csrf_token = '') {
        return PBXHelper::handleAction($csrf_token, function() use ($ruleId, $groupId) {
            $ruleId = intval($ruleId);
            $groupId = intval($groupId);
            $db = getDB();
            // Scoped like the update in saveRule(): a rule id from another
            // group must not be deletable by naming it.
            $del = $db->prepare("DELETE FROM pbx_permission_rules WHERE id = ? AND group_id = ?");
            $del->execute([$ruleId, $groupId]);
            if ($del->rowCount() === 0) {
                throw new \Exception(t('common.not_found'));
            }

            markPendingSync('permissions', 'rule', $ruleId, "Rule #{$ruleId}", 'delete', $_SESSION['user_id'] ?? null);
            return t('srv_dialperm.rule_deleted');
        });
    }

    /**
     * Evaluates whether a destination number is permitted for a given dial permission group.
     */
    public static function isDestinationAllowed(int $groupId, string $target): bool
    {
        if ($groupId <= 1 || $target === '') {
            return true;
        }
        $group = self::getGroup($groupId);
        if (!$group || empty($group['is_active'])) {
            return true;
        }
        require_once dirname(__DIR__) . '/sync/SyncPermissions.php';
        $rules = self::getRules($groupId);
        foreach ($rules as $r) {
            $pattern = preg_replace('/[^0-9NXZnxz.\[\]_!*#+-]/', '', trim((string)($r['pattern'] ?? '')));
            if ($pattern === '') {
                continue;
            }
            $isExact = (($r['pattern_type'] ?? 'prefix') === 'exact');
            $regex = '/' . permissionPatternToRegex($pattern, $isExact) . '/';
            if (@preg_match($regex, $target) === 1) {
                return ($r['action'] ?? 'deny') === 'allow';
            }
        }
        return ($group['default_action'] ?? 'allow') === 'allow';
    }
}
