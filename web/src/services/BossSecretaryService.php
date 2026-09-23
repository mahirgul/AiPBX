<?php
/**
 * Boss - Secretary Groups Service
 */

require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/asterisk_sync.php';

class BossSecretaryService {
    public static function getGroups() {
        $db = getDB();
        $stmt = $db->query("SELECT bs.*, u.full_name AS boss_name 
            FROM pbx_boss_secretary_groups bs 
            LEFT JOIN sys_users u ON u.extension = bs.boss_extension 
            ORDER BY bs.group_number ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getGroup($id) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM pbx_boss_secretary_groups WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function getAvailableExtensions() {
        $db = getDB();
        return $db->query("SELECT id, extension, full_name FROM sys_users WHERE extension IS NOT NULL AND extension != '' AND is_active = 1 AND extension_type = 'sip' ORDER BY extension ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function saveGroup($data) {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function() use ($data) {
            $id = intval($data['id'] ?? 0);
            $group_number = intval($data['group_number'] ?? 1);
            $group_name = trim($data['group_name'] ?? '');
            $boss_extension = preg_replace('/[^0-9]/', '', trim($data['boss_extension'] ?? ''));
            $secretaries = is_array($data['secretaries'] ?? null) ? $data['secretaries'] : (array)($data['secretaries'] ?? []);
            $secretaries = array_values(array_filter(array_map(fn($e) => preg_replace('/[^0-9]/', '', trim($e)), $secretaries)));
            $secretaries_json = json_encode($secretaries);
            $ring_strategy = ($data['ring_strategy'] ?? 'ringall') === 'sequential' ? 'sequential' : 'ringall';
            $ring_timeout = max(5, min(120, intval($data['ring_timeout'] ?? 20)));
            $whitelist = trim($data['whitelist_extensions'] ?? '');
            $fallback_dest_type = sanitizeDestType($data['fallback_dest_type'] ?? 'hangup');
            $fallback_dest_id = trim($data['fallback_dest_id'] ?? 'busy');
            $is_active = isset($data['is_active']) ? intval($data['is_active']) : 1;

            if (empty($group_name) || empty($boss_extension)) {
                throw new \Exception("Grup Adı ve Şef Dahilisi zorunludur!");
            }

            $db = getDB();
            // Check group_number uniqueness if new or changed
            $chk = $db->prepare("SELECT id FROM pbx_boss_secretary_groups WHERE group_number = ? AND id != ?");
            $chk->execute([$group_number, $id]);
            if ($chk->fetch()) {
                throw new \Exception("{$group_number} numaralı grup zaten mevcut, lütfen farklı bir grup numarası seçin.");
            }

            if ($id > 0) {
                $stmt = $db->prepare("UPDATE pbx_boss_secretary_groups SET group_number = ?, group_name = ?, boss_extension = ?, secretaries_json = ?, ring_strategy = ?, ring_timeout = ?, whitelist_extensions = ?, fallback_dest_type = ?, fallback_dest_id = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$group_number, $group_name, $boss_extension, $secretaries_json, $ring_strategy, $ring_timeout, $whitelist, $fallback_dest_type, $fallback_dest_id, $is_active, $id]);
            } else {
                $stmt = $db->prepare("INSERT INTO pbx_boss_secretary_groups (group_number, group_name, boss_extension, secretaries_json, ring_strategy, ring_timeout, whitelist_extensions, fallback_dest_type, fallback_dest_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$group_number, $group_name, $boss_extension, $secretaries_json, $ring_strategy, $ring_timeout, $whitelist, $fallback_dest_type, $fallback_dest_id, $is_active]);
                $id = (int)$db->lastInsertId();
            }

            // Sync user group assignment
            $db->prepare("UPDATE sys_users SET boss_secretary_group_id = ?, boss_secretary_role = 'boss' WHERE extension = ?")->execute([$id, $boss_extension]);
            foreach ($secretaries as $s_ext) {
                $db->prepare("UPDATE sys_users SET boss_secretary_group_id = ?, boss_secretary_role = 'secretary' WHERE extension = ?")->execute([$id, $s_ext]);
            }

            markPendingSync('general_dialplan', 'boss_secretary', $id, $group_name, $id > 0 ? 'update' : 'create', $_SESSION['user_id'] ?? null);
            return "Şef - Sekreter grubu başarıyla kaydedildi.";
        });
    }

    public static function deleteGroup($id, $csrf_token = '') {
        return PBXHelper::handleAction($csrf_token, function() use ($id) {
            $id = intval($id);
            $db = getDB();

            // Clear users referencing this group
            $db->prepare("UPDATE sys_users SET boss_secretary_group_id = NULL, boss_secretary_role = 'none' WHERE boss_secretary_group_id = ?")->execute([$id]);

            $del = $db->prepare("DELETE FROM pbx_boss_secretary_groups WHERE id = ?");
            $del->execute([$id]);

            markPendingSync('general_dialplan', 'boss_secretary', $id, "Şef Grubu #{$id}", 'delete', $_SESSION['user_id'] ?? null);
            return "Şef - Sekreter grubu silindi.";
        });
    }
}
