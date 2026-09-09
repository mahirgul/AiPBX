/**
 * Call End Options Client Script
 */
function openCreateHangupModal() {
    const title = document.getElementById('hangupModalTitle');
    if (title) title.innerHTML = '<i class="fas fa-plus-circle" style="color: var(--primary);"></i> Yeni Çağrı Sonlandırma Seçeneği Ekle';

    document.getElementById('modal_hangup_id').value = '';
    const keyEl = document.getElementById('modal_action_key');
    keyEl.value = '';
    keyEl.readOnly = false;

    document.getElementById('modal_title').value = '';
    var inEl = document.getElementById('modal_internal_number');
    if (inEl) inEl.value = '';

    document.getElementById('modal_action_type').value = 'hangup';
    document.getElementById('modal_announcement_id').value = '';

    const modal = document.getElementById('hangupModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function openEditHangupModal(item) {
    if (!item) return;

    const title = document.getElementById('hangupModalTitle');
    if (title) title.innerHTML = '<i class="fas fa-edit" style="color: var(--primary);"></i> Sonlandırma Seçeneği Düzenle: ' + escapeHtml(item.title || '');

    document.getElementById('modal_hangup_id').value = item.id || '';
    const keyEl = document.getElementById('modal_action_key');
    keyEl.value = item.action_key || '';
    keyEl.readOnly = true;

    document.getElementById('modal_title').value = item.title || '';
    var inEl = document.getElementById('modal_internal_number');
    if (inEl) inEl.value = (item.internal_number || '');

    document.getElementById('modal_action_type').value = item.action_type || 'hangup';
    document.getElementById('modal_announcement_id').value = item.announcement_id || '';

    const modal = document.getElementById('hangupModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function closeHangupModal() {
    UIHelper.closeOverlayModal('hangupModal');
}
