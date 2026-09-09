/**
 * Feature Code Yönetimi Client Script
 */
function openEditFeatureCodeModal(item) {
    if (!item) return;

    const title = document.getElementById('featureCodeModalTitle');
    if (title) title.innerHTML = '<i class="fas fa-edit" style="color: var(--primary);"></i> Feature Code Düzenle: ' + escapeHtml(item.title || '');

    document.getElementById('modal_feature_id').value = item.id || '';
    document.getElementById('modal_title').value = item.title || '';
    document.getElementById('modal_code').value = item.code || '';
    document.getElementById('modal_allowed_roles').value = item.allowed_roles || '';
    document.getElementById('modal_is_active').checked = String(item.is_active) === '1';

    const modal = document.getElementById('featureCodeModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function closeFeatureCodeModal() {
    UIHelper.closeOverlayModal('featureCodeModal');
}
