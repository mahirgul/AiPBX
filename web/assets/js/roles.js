/**
 * Role & Permission Matrix UI Logic
 */
// 'roles'/'system_users'/'firewall'/'fail2ban'/'mail_settings' auth.php'de admin dışı
// hiçbir role asla açılmıyor (bkz. auth.php hasModulePermission() circuit-breaker) — bu
// modüllerin checkbox'ları burada da admin-olmayan bir rol düzenlenirken kilitlenip
// işaretsiz bırakılır, ki arayüz yanıltıcı bir "izinli" görünümü göstermesin.
const ADMIN_ONLY_LOCKED_MODULES = ['roles', 'system_users', 'firewall', 'fail2ban', 'mail_settings'];
const ADMIN_ONLY_LOCKED_EDIT_MODULES = ['push_settings'];

function applyAdminOnlyModuleLock(roleKey) {
    const isAdmin = (roleKey === 'admin');
    ADMIN_ONLY_LOCKED_MODULES.forEach(modKey => {
        ['view', 'access', 'edit', 'delete'].forEach(action => {
            const el = document.getElementById(`p_${modKey}_${action}`);
            if (!el) return;
            if (!isAdmin) {
                el.checked = false;
                el.disabled = true;
                el.title = 'Bu modüle yalnızca "admin" rolü erişebilir (sistem tarafından kilitli)';
            } else {
                el.disabled = false;
                el.title = '';
            }
        });
    });
    ADMIN_ONLY_LOCKED_EDIT_MODULES.forEach(modKey => {
        ['edit', 'delete'].forEach(action => {
            const el = document.getElementById(`p_${modKey}_${action}`);
            if (!el) return;
            if (!isAdmin) {
                el.checked = false;
                el.disabled = true;
                el.title = 'Bu işlem yalnızca "admin" rolüne açıktır (sistem tarafından kilitli)';
            } else {
                el.disabled = false;
                el.title = '';
            }
        });
    });
}

function openCreateRoleModal() {
    document.getElementById('roleModalTitle').innerHTML = '<i class="fas fa-plus-circle" style="color: var(--primary);"></i> Yeni Kullanıcı Rolü Oluştur';
    document.getElementById('modal_role_id').value = '';
    document.getElementById('modal_role_key').value = '';
    document.getElementById('modal_role_key').readOnly = false;
    document.getElementById('modal_role_name').value = '';
    document.getElementById('modal_description').value = '';
    document.getElementById('modal_system_role_hint').style.display = 'none';

    // Clear all checkboxes
    toggleAllPerms(false);
    applyAdminOnlyModuleLock('');

    document.getElementById('roleModal').style.display = 'flex';
}

function openEditRoleModal(role) {
    document.getElementById('roleModalTitle').innerHTML = '<i class="fas fa-user-shield" style="color: var(--warning);"></i> Rol İzin Matrisi: ' + escapeHtml(role.role_name || '');
    document.getElementById('modal_role_id').value = role.id;
    document.getElementById('modal_role_key').value = role.role_key;
    document.getElementById('modal_role_key').readOnly = (role.is_system == 1);
    document.getElementById('modal_role_name').value = role.role_name;
    document.getElementById('modal_description').value = role.description || '';
    document.getElementById('modal_system_role_hint').style.display = (role.is_system == 1) ? 'block' : 'none';

    // Load permission checkboxes for this role
    const allPerms = window.ALL_PERMISSIONS || {};
    const rolePerms = allPerms[role.role_key] || {};
    
    document.querySelectorAll('.perm-cb').forEach(cb => {
        cb.checked = false;
    });

    for (const [modKey, actions] of Object.entries(rolePerms)) {
        if (actions.view) {
            const el = document.getElementById(`p_${modKey}_view`);
            if (el) el.checked = true;
        }
        if (actions.access) {
            const el = document.getElementById(`p_${modKey}_access`);
            if (el) el.checked = true;
        }
        if (actions.edit) {
            const el = document.getElementById(`p_${modKey}_edit`);
            if (el) el.checked = true;
        }
        if (actions.delete) {
            const el = document.getElementById(`p_${modKey}_delete`);
            if (el) el.checked = true;
        }
    }

    applyAdminOnlyModuleLock(role.role_key);

    document.getElementById('roleModal').style.display = 'flex';
}

function closeRoleModal() {
    UIHelper.closeOverlayModal('roleModal');
}

function toggleAllPerms(state) {
    document.querySelectorAll('.perm-cb').forEach(cb => {
        if (cb.disabled) return;
        cb.checked = state;
    });
}
