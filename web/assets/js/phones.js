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

// ---- The file a phone gets (passwords hidden until asked for) ----
const phoneFile = { id: 0, mac: '', files: [], current: 0, revealed: false };

function showPhoneFile(phoneId, mac, reveal, index) {
    const form = new FormData();
    form.append('csrf_token', window.CSRF_TOKEN);
    form.append('action', 'preview');
    form.append('phone_id', String(phoneId));
    if (reveal) form.append('reveal', '1');
    fetch('/phones', { method: 'POST', body: form })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert(data.message || __('js.phones.failed'));
                return;
            }
            Object.assign(phoneFile, { id: phoneId, mac: mac, files: data.files || [], current: index || 0, revealed: !!data.revealed });
            renderPhoneFile();
            UIHelper.openOverlayModal('phoneFileModal');
        })
        .catch(() => alert(__('js.phones.failed')));
}

function renderPhoneFile() {
    const f = phoneFile.files[phoneFile.current] || { name: '', content: '' };
    document.getElementById('phoneFileTitle').textContent = phoneFile.mac + ' · ' + f.name;
    document.getElementById('phoneFileContent').textContent = f.content;
    const tabs = document.getElementById('phoneFileTabs');
    tabs.innerHTML = '';
    if (phoneFile.files.length > 1) {
        phoneFile.files.forEach((file, i) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn-sm ' + (i === phoneFile.current ? 'btn-primary' : 'btn-secondary');
            b.textContent = file.name;
            b.onclick = () => { phoneFile.current = i; renderPhoneFile(); };
            tabs.appendChild(b);
        });
    }
    document.getElementById('phoneFileNote').textContent = phoneFile.revealed ? '' : __('js.phones.passwords_hidden');
    const rb = document.getElementById('phoneFileReveal');
    if (rb) {
        rb.querySelector('i').className = phoneFile.revealed ? 'fas fa-eye-slash' : 'fas fa-eye';
        rb.querySelector('span').textContent = phoneFile.revealed ? __('js.phones.hide_passwords') : __('js.phones.show_passwords');
    }
}

function togglePhoneFilePasswords() {
    showPhoneFile(phoneFile.id, phoneFile.mac, !phoneFile.revealed, phoneFile.current);
}

function copyPhoneFile() {
    const f = phoneFile.files[phoneFile.current];
    if (f) UIHelper.copyToClipboard(f.content, __('js.phones.file_copied'));
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
