/**
 * System Users Client Module
 */
function openCreateUserModal() {
    document.getElementById('userModalTitle').innerHTML = '<i class="fas fa-user-plus" style="color: var(--primary);"></i> Yeni Sistem Kullanıcısı Ekle';
    document.getElementById('modal_user_id').value = '0';
    document.getElementById('modal_username').value = '';
    document.getElementById('modal_username').readOnly = false;
    document.getElementById('modal_password_group').style.display = 'block';
    document.getElementById('modal_password').required = true;
    document.getElementById('modal_password').value = '';
    document.getElementById('modal_full_name').value = '';
    document.getElementById('modal_email').value = '';
    document.getElementById('modal_role').value = 'cc_agent';
    document.getElementById('modal_extension').value = '';
    if (document.getElementById('modal_sip_password')) document.getElementById('modal_sip_password').value = '';
    if (document.getElementById('modal_cid_internal')) document.getElementById('modal_cid_internal').value = '';
    if (document.getElementById('modal_cid_external')) document.getElementById('modal_cid_external').value = '';
    if (document.getElementById('modal_pickup_group')) document.getElementById('modal_pickup_group').value = '';
    ['modal_mode_web', 'modal_mode_mobil', 'modal_mode_sip', 'modal_mode_video'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.checked = true;
    });
    document.getElementById('modal_listen_recordings').checked = false;
    document.getElementById('modal_view_all_cdrs').checked = false;
    if (document.getElementById('modal_view_queue_monitor')) document.getElementById('modal_view_queue_monitor').checked = false;
    const actCb = document.getElementById('modal_is_active');
    if (actCb) actCb.checked = true;

    const modal = document.getElementById('userModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function openEditUserModal(u) {
    if (!u) return;

    document.getElementById('userModalTitle').innerHTML = '<i class="fas fa-user-edit" style="color: var(--primary);"></i> Kullanıcı Düzenle: ' + escapeHtml(u.username || '');
    document.getElementById('modal_user_id').value = u.id;
    document.getElementById('modal_username').value = u.username || '';
    // Enable editing username for normal accounts; keep read-only for master admin account
    document.getElementById('modal_username').readOnly = (u.username === 'admin');
    document.getElementById('modal_password_group').style.display = 'none';
    document.getElementById('modal_password').required = false;
    document.getElementById('modal_password').value = '';
    document.getElementById('modal_full_name').value = u.full_name || '';
    document.getElementById('modal_email').value = u.email || '';
    document.getElementById('modal_role').value = u.role || 'cc_agent';
    document.getElementById('modal_extension').value = u.extension || '';
    if (document.getElementById('modal_sip_password')) document.getElementById('modal_sip_password').value = u.sip_password || '';
    if (document.getElementById('modal_cid_internal')) document.getElementById('modal_cid_internal').value = u.cid_internal || '';
    if (document.getElementById('modal_cid_external')) document.getElementById('modal_cid_external').value = u.cid_external || '';
    if (document.getElementById('modal_pickup_group')) document.getElementById('modal_pickup_group').value = u.pickup_group || '';
    
    // Parse allowed_phone_mode into checkboxes
    let modes = ['web', 'mobil', 'sip', 'video'];
    const rawMode = (u.allowed_phone_mode || 'both').trim();
    if (rawMode === 'both') {
        modes = ['web', 'mobil', 'sip', 'video'];
    } else if (rawMode === 'webrtc_only') {
        modes = ['web', 'mobil', 'video'];
    } else if (rawMode === 'sip_only') {
        modes = ['sip'];
    } else {
        modes = rawMode.split(',').map(s => s.trim());
    }
    const modeWebEl = document.getElementById('modal_mode_web');
    if (modeWebEl) modeWebEl.checked = modes.includes('web');
    const modeMobEl = document.getElementById('modal_mode_mobil');
    if (modeMobEl) modeMobEl.checked = modes.includes('mobil');
    const modeSipEl = document.getElementById('modal_mode_sip');
    if (modeSipEl) modeSipEl.checked = modes.includes('sip');
    const modeVidEl = document.getElementById('modal_mode_video');
    if (modeVidEl) modeVidEl.checked = modes.includes('video');

    document.getElementById('modal_listen_recordings').checked = parseInt(u.can_listen_recordings) === 1;
    document.getElementById('modal_view_all_cdrs').checked = parseInt(u.can_view_all_cdrs) === 1;
    if (document.getElementById('modal_view_queue_monitor')) document.getElementById('modal_view_queue_monitor').checked = parseInt(u.can_view_queue_monitor) === 1;
    const actCb = document.getElementById('modal_is_active');
    if (actCb) actCb.checked = (parseInt(u.is_active) === 1);

    const modal = document.getElementById('userModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function closeUserModal() {
    UIHelper.closeOverlayModal('userModal');
}

function openResetUserModal(userId, username) {
    document.getElementById('reset_user_id').value = userId;
    document.getElementById('reset_username_label').innerText = username;
    if (document.getElementById('reset_new_password')) document.getElementById('reset_new_password').value = '';
    if (document.getElementById('reset_new_sip_password')) document.getElementById('reset_new_sip_password').value = '';

    const modal = document.getElementById('resetPasswordModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function closeResetUserModal() {
    UIHelper.closeOverlayModal('resetPasswordModal');
}

function openRolesModal() {
    const modal = document.getElementById('rolesModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function closeRolesModal() {
    UIHelper.closeOverlayModal('rolesModal');
}

function editSystemRole(role) {
    if (!role) return;
    document.getElementById('role_modal_id').value = role.id;
    document.getElementById('role_modal_key').value = role.role_key || '';
    document.getElementById('role_modal_key').readOnly = true;
    document.getElementById('role_modal_name').value = role.role_name || '';
    document.getElementById('role_modal_desc').value = role.description || '';
}

function resetRoleForm() {
    document.getElementById('role_modal_id').value = '0';
    document.getElementById('role_modal_key').value = '';
    document.getElementById('role_modal_key').readOnly = false;
    document.getElementById('role_modal_name').value = '';
    document.getElementById('role_modal_desc').value = '';
}
