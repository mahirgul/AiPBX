<?php
require_once __DIR__ . '/../db_helper.php';
require_once __DIR__ . '/../asterisk_sync.php';

/**
 * Role & Permission Matrix Service
 */
class RoleService {
    /**
     * @return array{redirect?:string, error?:string}
     */
    public static function saveRole(array $data, array $modulesDefinition): array
    {
        if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
            return ['error' => 'Geçersiz CSRF güvenlik doğrulama kodu!'];
        }

        $db = getDB();
        $role_id = intval($data['role_id'] ?? 0);
        $role_key = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower(trim($data['role_key'] ?? '')));
        $role_name = trim($data['role_name'] ?? '');
        $description = trim($data['description'] ?? '');

        // Mevcut bir rol düzenleniyorsa (role_id > 0), izin matrisini kaydederken
        // POST'tan gelen role_key'e DEĞİL, o role_id'nin DB'deki GERÇEK role_key'ine
        // güvenilir — aksi halde role_id bir role ait iken role_key alanına (form
        // dışı bir istekle) başka bir rolün anahtarı yazılırsa, o FARKLI rolün tüm
        // izin matrisi üzerine sessizce yazılabiliyordu (2026-08-21 denetiminde
        // bulundu — veri bütünlüğü açığı).
        $error = null;
        if ($role_id > 0) {
            $actual_role_key = $db->prepare("SELECT role_key FROM sys_roles WHERE id = ?");
            $actual_role_key->execute([$role_id]);
            $actual_role_key = $actual_role_key->fetchColumn();
            if ($actual_role_key === false) {
                $error = 'Düzenlenmek istenen rol bulunamadı!';
                $role_key = '';
            } else {
                $role_key = $actual_role_key;
            }
        }

        if (empty($role_key) || empty($role_name)) {
            return ['error' => $error ?: 'Rol anahtarı ve rol adı zorunludur!'];
        }

        try {
            if ($role_id > 0) {
                $stmt = $db->prepare("UPDATE sys_roles SET role_name = ?, description = ? WHERE id = ?");
                $stmt->execute([$role_name, $description, $role_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO sys_roles (role_key, role_name, description, is_system) VALUES (?, ?, ?, 0)");
                $stmt->execute([$role_key, $role_name, $description]);
            }

            // Update Permissions Matrix
            $perms_post = $data['perms'] ?? [];
            // 'roles'/'system_users' auth.php'de admin dışı hiçbir role ASLA
            // açılmıyor (bkz. hasModulePermission() circuit-breaker) — burada da
            // aynı kısıtlama uygulanır, ki izin matrisi ekranı admin-olmayan bir
            // role bu iki modül için yanıltıcı bir "izinli" görünümü göstermesin.
            $locked_admin_only_modules = ['roles', 'system_users'];
            $locked_admin_only_edit_modules = ['firewall', 'fail2ban', 'push_settings'];
            foreach ($modulesDefinition as $mod_key => $mod_info) {
                if (in_array($mod_key, $locked_admin_only_modules, true) && $role_key !== 'admin') {
                    $can_view = $can_access = $can_edit = $can_delete = 0;
                } elseif (in_array($mod_key, $locked_admin_only_edit_modules, true) && $role_key !== 'admin') {
                    $can_view = isset($perms_post[$mod_key]['view']) ? 1 : 0;
                    $can_access = isset($perms_post[$mod_key]['access']) ? 1 : 0;
                    $can_edit = $can_delete = 0; // Sudo / API sırrı içeren modüller admin dışına açılamaz
                } else {
                    $can_view = isset($perms_post[$mod_key]['view']) ? 1 : 0;
                    $can_access = isset($perms_post[$mod_key]['access']) ? 1 : 0;
                    $can_edit = isset($perms_post[$mod_key]['edit']) ? 1 : 0;
                    $can_delete = isset($perms_post[$mod_key]['delete']) ? 1 : 0;
                }

                $stmt = $db->prepare("INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_access = VALUES(can_access), can_edit = VALUES(can_edit), can_delete = VALUES(can_delete)");
                $stmt->execute([$role_key, $mod_key, $can_view, $can_access, $can_edit, $can_delete]);
            }

            // Bu, Asterisk config'ini hiç etkilemiyor (RBAC her istekte DB'den
            // anlık okunuyor) — markPendingSync() DEĞİL, doğrudan writeAuditLog()
            // (domain=NULL, PENDING_SYNC_DOMAIN_MAP'e ait bir domain değil).
            writeAuditLog(null, 'role', $role_key, "Rol: {$role_name} (izin matrisi güncellendi)", $role_id > 0 ? 'update' : 'create', $_SESSION['user_id'] ?? null);

            notify("Kullanıcı Rolü '$role_name' ve modül izin matrisi başarıyla kaydedildi!", "success");
            return ['redirect' => '/roles'];
        } catch (\Exception $e) {
            return ['error' => 'Hata oluştu: ' . $e->getMessage()];
        }
    }

    /**
     * @return array{redirect?:string, error?:string}
     */
    public static function deleteRole(array $data): array
    {
        if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
            return ['error' => 'Geçersiz CSRF güvenlik doğrulama kodu!'];
        }

        $role_id = intval($data['role_id'] ?? 0);
        $role_key = trim($data['role_key'] ?? '');

        if ($role_id <= 0 || empty($role_key)) {
            return [];
        }

        $db = getDB();
        // Check system role status
        $stmt = $db->prepare("SELECT is_system FROM sys_roles WHERE id = ?");
        $stmt->execute([$role_id]);
        $is_sys = $stmt->fetchColumn();

        if ($is_sys) {
            return ['error' => 'Sistem temel rolleri (admin, read_only_admin, cc_agent, fax_user) silinemez!'];
        }

        $count_stmt = $db->prepare("SELECT COUNT(*) FROM sys_users WHERE role = ?");
        $count_stmt->execute([$role_key]);
        $in_use = (int)$count_stmt->fetchColumn();
        if ($in_use > 0) {
            return ['error' => "Bu rol {$in_use} kullanıcıya atanmış durumda, önce onları başka bir role taşımadan silinemez!"];
        }

        $db->prepare("DELETE FROM sys_roles WHERE id = ?")->execute([$role_id]);
        $db->prepare("DELETE FROM sys_role_permissions WHERE role_key = ?")->execute([$role_key]);
        writeAuditLog(null, 'role', $role_key, "Rol: {$role_key} (silindi)", 'delete', $_SESSION['user_id'] ?? null);
        notify("Kullanıcı rolü '$role_key' silindi.", "warning");
        return ['redirect' => '/roles'];
    }
}
