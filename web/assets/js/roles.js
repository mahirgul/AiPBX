/**
 * Role & Permission Matrix UI Logic
 */
// Admin-only modules have no checkboxes at all (the matrix shows them locked);
// for "admin only edit" modules the edit/delete boxes are locked for other roles.
// Both lists come from RoleRepository::modulesDefinition().
function applyAdminOnlyModuleLock(roleKey) {
    const isAdmin = (roleKey === 'admin');
    (window.ADMIN_ONLY_EDIT_MODULES || []).forEach(modKey => {
        ['edit', 'delete'].forEach(action => {
            const el = document.getElementById(`p_${modKey}_${action}`);
            if (!el) return;
            if (!isAdmin) {
                el.checked = false;
                el.disabled = true;
                el.title = __('js.roles.admin_only');
            } else {
                el.disabled = false;
                el.title = '';
            }
        });
    });
}

function openCreateRoleModal() {
    document.getElementById('roleModalTitle').innerHTML = '<i class="fas fa-plus-circle" style="color: var(--primary);"></i> ' + __('js.roles.new_title') + '';
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
    document.getElementById('roleModalTitle').innerHTML = '<i class="fas fa-user-shield" style="color: var(--warning);"></i> ' + __('js.roles.edit_title') + '' + escapeHtml(role.role_name || '');
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
