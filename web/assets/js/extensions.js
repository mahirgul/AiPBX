/**
 * Dahili Abone (Extension) Yönetim İstemci Betiği
 */

function showExtensionModal() {
    const modal = document.getElementById('extensionModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function closeExtensionModal() {
    UIHelper.closeOverlayModal('extensionModal');
}

function toggleExtensionTypeFields() {
    const typeEl = document.getElementById('modal_extension_type');
    const authGroup = document.getElementById('sip_auth_digest_group');
    const authEl = document.getElementById('modal_sip_auth_digest');
    const pwGroup = document.getElementById('sip_password_group');
    const pwEl = document.getElementById('modal_sip_password');
    if (!typeEl || !pwGroup || !pwEl) return;

    const isFax = typeEl.value === 'fax';
    if (authGroup) authGroup.style.display = isFax ? 'none' : '';

    if (isFax) {
        pwGroup.style.display = 'none';
        pwEl.required = false;
    } else {
        const isAuthOff = authEl && authEl.value === '0';
        pwGroup.style.display = '';
        pwEl.required = !isAuthOff;
    }
}

function toggleAuthDigestFields() {
    toggleExtensionTypeFields();
}

function openCreateExtensionModal() {
    const title = document.getElementById('extensionModalTitle');
    if (title) title.innerHTML = '<i class="fas fa-plus-circle" style="color: var(--primary);"></i> Yeni Dahili Abone Tanımla';

    document.getElementById('modal_user_id').value = '';
    document.getElementById('modal_full_name').value = '';
    document.getElementById('modal_extension').value = '';
    document.getElementById('modal_extension_type').value = 'sip';
    const authEl = document.getElementById('modal_sip_auth_digest');
    if (authEl) authEl.value = '1';
    const obEl = document.getElementById('modal_outbound_group');
    if (obEl) obEl.value = '1';
    document.getElementById('modal_cid_internal').value = '';
    document.getElementById('modal_cid_external').value = '';
    const actEl = document.getElementById('modal_is_active');
    if (actEl) actEl.checked = true;

    const permEl = document.getElementById('modal_permission_group_id');
    if (permEl) permEl.value = '1';
    const bsgEl = document.getElementById('modal_boss_secretary_group_id');
    if (bsgEl) bsgEl.value = '';

    const vmEn = document.getElementById('modal_voicemail_enabled');
    if (vmEn) vmEn.checked = true;
    const vmPin = document.getElementById('modal_voicemail_pin');
    if (vmPin) vmPin.value = '';
    const vmEmail = document.getElementById('modal_voicemail_email');
    if (vmEmail) vmEmail.value = '';
    const vmNa = document.getElementById('modal_vm_on_noanswer');
    if (vmNa) vmNa.checked = false;
    const vmBusy = document.getElementById('modal_vm_on_busy');
    if (vmBusy) vmBusy.checked = false;
    const vmUnavail = document.getElementById('modal_vm_on_unavail');
    if (vmUnavail) vmUnavail.checked = false;
    const vmAlways = document.getElementById('modal_vm_always');
    if (vmAlways) vmAlways.checked = false;

    const pwEl = document.getElementById('modal_sip_password');
    pwEl.value = generateSipPassword();
    pwEl.type = 'text';

    toggleExtensionTypeFields();
    showExtensionModal();
}

function openEditExtensionModal(item) {
    if (!item) return;

    const title = document.getElementById('extensionModalTitle');
    if (title) title.innerHTML = '<i class="fas fa-edit" style="color: var(--primary);"></i> Dahili Düzenle: ' + escapeHtml(item.extension || '');

    document.getElementById('modal_user_id').value = item.id || '';
    document.getElementById('modal_full_name').value = item.full_name || '';
    document.getElementById('modal_extension').value = item.extension || '';
    document.getElementById('modal_extension_type').value = item.extension_type === 'fax' ? 'fax' : 'sip';
    const authEl = document.getElementById('modal_sip_auth_digest');
    if (authEl) {
        authEl.value = (item.sip_auth_digest !== undefined && parseInt(item.sip_auth_digest, 10) === 0) ? '0' : '1';
    }
    const obEl = document.getElementById('modal_outbound_group');
    if (obEl) obEl.value = item.outbound_group || 1;
    document.getElementById('modal_cid_internal').value = item.cid_internal || '';
    document.getElementById('modal_cid_external').value = item.cid_external || '';
    const actEl = document.getElementById('modal_is_active');
    if (actEl) actEl.checked = (item.is_active == 1);

    const permEl = document.getElementById('modal_permission_group_id');
    if (permEl) permEl.value = item.permission_group_id || '1';
    const bsgEl = document.getElementById('modal_boss_secretary_group_id');
    if (bsgEl) bsgEl.value = item.boss_secretary_group_id || '';

    const vmEn = document.getElementById('modal_voicemail_enabled');
    if (vmEn) vmEn.checked = (item.voicemail_enabled == 1 || item.voicemail_enabled === undefined);
    const vmPin = document.getElementById('modal_voicemail_pin');
    if (vmPin) vmPin.value = item.voicemail_pin || '';
    const vmEmail = document.getElementById('modal_voicemail_email');
    if (vmEmail) vmEmail.value = item.voicemail_email || '';
    const vmNa = document.getElementById('modal_vm_on_noanswer');
    if (vmNa) vmNa.checked = (item.vm_on_noanswer == 1);
    const vmBusy = document.getElementById('modal_vm_on_busy');
    if (vmBusy) vmBusy.checked = (item.vm_on_busy == 1);
    const vmUnavail = document.getElementById('modal_vm_on_unavail');
    if (vmUnavail) vmUnavail.checked = (item.vm_on_unavail == 1);
    const vmAlways = document.getElementById('modal_vm_always');
    if (vmAlways) vmAlways.checked = (item.vm_always == 1);

    const pwEl = document.getElementById('modal_sip_password');
    pwEl.value = item.sip_password || '';
    pwEl.type = 'password';

    toggleExtensionTypeFields();
    showExtensionModal();
}

// generateSipPassword() artık ortak yardımcı olarak ui_helper.js içindedir
// (her sayfada yüklenir; system_users.php ve extensions.php birlikte kullanır).

function toggleSipPasswordField() {
    const el = document.getElementById('modal_sip_password');
    if (el) el.type = (el.type === 'password') ? 'text' : 'password';
}

function toggleRowPassword(btn) {
    const code = btn.parentElement ? btn.parentElement.querySelector('code') : null;
    if (!code) return;

    if (code.dataset.revealed === '1') {
        code.textContent = '••••••';
        code.dataset.revealed = '0';
        btn.innerHTML = '<i class="fas fa-eye"></i>';
    } else {
        code.textContent = code.dataset.pw || '';
        code.dataset.revealed = '1';
        btn.innerHTML = '<i class="fas fa-eye-slash"></i>';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('extensionModal');
    if (modal) {
        // Arka plana tıklayınca kapat
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeExtensionModal();
        });
    }

    // ESC ile kapat
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeExtensionModal();
    });
});
