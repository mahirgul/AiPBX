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
            return ['error' => t('common.invalid_csrf')];
        }

        $db = getDB();
        $role_id = intval($data['role_id'] ?? 0);
        $role_key = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower(trim($data['role_key'] ?? '')));
        $role_name = trim($data['role_name'] ?? '');
        $description = trim($data['description'] ?? '');

        // When an existing role is edited (role_id > 0), the permission matrix
        // is saved against the REAL role_key of that role_id in the DB, NOT the
        // role_key from the POST — otherwise, with role_id belonging to one
        // role, writing another role's key into the role_key field (with a
        // request outside the form) could silently overwrite that OTHER role's
        // whole permission matrix (found in the 2026-08-21 audit — a data
        // integrity hole).
        $error = null;
        if ($role_id > 0) {
            $actual_role_key = $db->prepare("SELECT role_key FROM sys_roles WHERE id = ?");
            $actual_role_key->execute([$role_id]);
            $actual_role_key = $actual_role_key->fetchColumn();
            if ($actual_role_key === false) {
                $error = t('srv_role.err_not_found');
                $role_key = '';
            } else {
                $role_key = $actual_role_key;
            }
        }

        if (empty($role_key) || empty($role_name)) {
            return ['error' => $error ?: t('srv_role.err_required')];
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
            // The matrix definition says which boxes exist for each module
            // (RoleRepository::modulesDefinition()); anything else is stored as 0.
            // Admin-only modules are never granted to another role — auth.php
            // refuses them anyway, the matrix must not suggest otherwise.
            foreach ($modulesDefinition as $mod_key => $mod_info) {
                $actions = $mod_info['actions'] ?? RoleRepository::ALL_ACTIONS;
                $isAdmin = ($role_key === 'admin');
                $grant = [];
                foreach (RoleRepository::ALL_ACTIONS as $action) {
                    if (!in_array($action, $actions, true)) {
                        $grant[$action] = 0;
                    } elseif (!empty($mod_info['admin_only'])) {
                        $grant[$action] = $isAdmin ? 1 : 0;
                    } elseif (!empty($mod_info['admin_only_edit']) && !$isAdmin && in_array($action, ['edit', 'delete'], true)) {
                        $grant[$action] = 0;
                    } else {
                        $grant[$action] = isset($perms_post[$mod_key][$action]) ? 1 : 0;
                    }
                }
                [$can_view, $can_access, $can_edit, $can_delete] = [$grant['view'], $grant['access'], $grant['edit'], $grant['delete']];

                $stmt = $db->prepare("INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_access = VALUES(can_access), can_edit = VALUES(can_edit), can_delete = VALUES(can_delete)");
                $stmt->execute([$role_key, $mod_key, $can_view, $can_access, $can_edit, $can_delete]);
            }

            // This does not affect the Asterisk config at all (RBAC is read
            // from the DB on every request) — so writeAuditLog() directly, NOT
            // markPendingSync() (domain=NULL, not a PENDING_SYNC_DOMAIN_MAP domain).
            writeAuditLog(null, 'role', $role_key, "Role: {$role_name} (permission matrix updated)", $role_id > 0 ? 'update' : 'create', $_SESSION['user_id'] ?? null);

            notify(sprintf(t('srv_role.saved'), $role_name), "success");
            return ['redirect' => '/roles'];
        } catch (\Exception $e) {
            return ['error' => sprintf(t('common.error_detail'), $e->getMessage())];
        }
    }

    /**
     * @return array{redirect?:string, error?:string}
     */
    public static function deleteRole(array $data): array
    {
        if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
            return ['error' => t('common.invalid_csrf')];
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
            return ['error' => 'Sistem temel rolleri (admin, read_only_admin, cc_agent, fax_user, user) silinemez!'];
        }

        $count_stmt = $db->prepare("SELECT COUNT(*) FROM sys_users WHERE role = ?");
        $count_stmt->execute([$role_key]);
        $in_use = (int)$count_stmt->fetchColumn();
        if ($in_use > 0) {
            return ['error' => sprintf(t('srv_role.err_in_use'), $in_use)];
        }

        $db->prepare("DELETE FROM sys_roles WHERE id = ?")->execute([$role_id]);
        $db->prepare("DELETE FROM sys_role_permissions WHERE role_key = ?")->execute([$role_key]);
        writeAuditLog(null, 'role', $role_key, "Role: {$role_key} (deleted)", 'delete', $_SESSION['user_id'] ?? null);
        notify(sprintf(t('srv_role.deleted'), $role_key), "warning");
        return ['redirect' => '/roles'];
    }
}
