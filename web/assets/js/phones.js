/* Page script of templates/views/phones/index.php */

function openCreatePhoneModal() {
    document.getElementById('phoneModalTitle').innerHTML = '<i class="fas fa-plus-circle u-primary"></i> ' + escapeHtml(__('js.phones.add'));
    document.getElementById('phone_id').value = '0';
    document.getElementById('phone_mac').value = '';
    document.getElementById('phone_user').value = '0';
    document.getElementById('phone_notes').value = '';
    UIHelper.openOverlayModal('phoneModal');
}

function openEditPhoneModal(p) {
    document.getElementById('phoneModalTitle').innerHTML = '<i class="fas fa-edit u-primary"></i> ' + escapeHtml(__('js.phones.edit') + ' ' + (p.mac || ''));
    document.getElementById('phone_id').value = p.id || '0';
    document.getElementById('phone_mac').value = p.mac || '';
    document.getElementById('phone_model').value = p.model || '';
    document.getElementById('phone_user').value = String(p.user_id || 0);
    document.getElementById('phone_notes').value = p.notes || '';
    UIHelper.openOverlayModal('phoneModal');
}

function copyProvisionUrl(btn) {
    UIHelper.copyToClipboard(btn.getAttribute('data-url') || '', __('js.phones.url_copied'));
}

function showPhoneAdminPassword(phoneId) {
    const form = new FormData();
    form.append('csrf_token', window.CSRF_TOKEN);
    form.append('action', 'admin_password');
    form.append('phone_id', String(phoneId));
    fetch('/phones', { method: 'POST', body: form })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert(data.message || __('js.phones.failed'));
                return;
            }
            window.prompt(__('js.phones.admin_password'), data.password);
        })
        .catch(() => alert(__('js.phones.failed')));
}
