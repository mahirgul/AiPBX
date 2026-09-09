/**
 * Fax Settings & Department Client Module
 */
function openCreateFaxDidModal() {
    const title = document.getElementById('didModalTitle');
    if (title) title.innerHTML = '<i class="fas fa-plus-circle" style="color: var(--primary);"></i> Yeni Faks Birimi Tanımla';

    const idEl = document.getElementById('modal_did_id');
    const deptEl = document.getElementById('modal_department_name');
    const emailEl = document.getElementById('modal_notification_email');
    const didExtEl = document.getElementById('modal_did_extension');

    if (idEl) idEl.value = '0';
    if (deptEl) deptEl.value = '';
    if (emailEl) emailEl.value = '';
    if (didExtEl) didExtEl.value = '';
    const assignedEl = document.getElementById('modal_assigned_user_id');
    if (assignedEl) assignedEl.value = '0';
    const actCb = document.getElementById('modal_is_active');
    if (actCb) actCb.checked = true;

    const modal = document.getElementById('didModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function openEditFaxDidModal(item) {
    if (!item) return;

    const title = document.getElementById('didModalTitle');
    if (title) title.innerHTML = '<i class="fas fa-edit" style="color: var(--primary);"></i> Faks Birimi Düzenle: ' + escapeHtml(item.department_name || '');

    const idEl = document.getElementById('modal_did_id');
    const deptEl = document.getElementById('modal_department_name');
    const emailEl = document.getElementById('modal_notification_email');
    const didExtEl = document.getElementById('modal_did_extension');

    if (idEl) idEl.value = item.id || 0;
    if (deptEl) deptEl.value = item.department_name || '';
    if (emailEl) emailEl.value = item.notification_email || '';
    if (didExtEl) didExtEl.value = item.did_extension || '';
    const assignedEl = document.getElementById('modal_assigned_user_id');
    if (assignedEl) assignedEl.value = item.assigned_user_id || '0';
    const actCb = document.getElementById('modal_is_active');
    if (actCb) actCb.checked = ((item.is_active === undefined) || parseInt(item.is_active) === 1);

    const modal = document.getElementById('didModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function closeFaxDidModal() {
    UIHelper.closeOverlayModal('didModal');
}
