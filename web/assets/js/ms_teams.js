/* Page script of templates/views/ms_teams/index.php */

function openTeamsTab(tabName) {
    document.querySelectorAll('.teams-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.teams-tab-pane').forEach(pane => pane.classList.remove('active'));

    const activeBtn = document.querySelector(`.teams-tab-btn[onclick*="${tabName}"]`);
    if (activeBtn) activeBtn.classList.add('active');

    const activePane = document.getElementById(`tab-${tabName}`);
    if (activePane) activePane.classList.add('active');

    const url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    window.history.replaceState({}, '', url);
}

function toggleTeamsHelp() {
    const box = document.getElementById('teamsHelpBox');
    if (box) {
        box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
    }
}

function openMappingModal() {
    document.getElementById('mappingForm').reset();
    document.getElementById('mapId').value = '';
    document.getElementById('mappingModalTitle').innerHTML = '<i class="fas fa-user-plus"></i> ' + window.MS_TEAMS_I18N.modal_new_title;
    document.getElementById('modalAlert').style.display = 'none';
    document.getElementById('mappingModal').style.display = 'block';
}

function editMapping(item) {
    document.getElementById('mapId').value = item.id || '';
    document.getElementById('mapExtension').value = item.extension || '';
    document.getElementById('mapTeamsUpn').value = item.teams_upn || '';
    document.getElementById('mapPhoneNumber').value = item.phone_number || '';
    document.getElementById('mapDirectRouting').value = item.direct_routing_enabled ? '1' : '0';
    document.getElementById('mapNotes').value = item.notes || '';
    document.getElementById('mappingModalTitle').innerHTML = '<i class="fas fa-user-edit"></i> ' + window.MS_TEAMS_I18N.modal_edit_title.replace('%s', item.extension);
    document.getElementById('modalAlert').style.display = 'none';
    document.getElementById('mappingModal').style.display = 'block';
}

function closeMappingModal() {
    document.getElementById('mappingModal').style.display = 'none';
}

function submitMappingForm(e) {
    e.preventDefault();
    const form = document.getElementById('mappingForm');
    const formData = new FormData(form);
    const alertBox = document.getElementById('modalAlert');
    const btn = document.getElementById('btnSaveMapping');

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + window.MS_TEAMS_I18N.saving;

    fetch('/ms-teams?action=save_mapping', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> ' + window.MS_TEAMS_I18N.btn_save;
        if (data.success) {
            window.location.href = '/ms-teams?tab=users';
        } else {
            alertBox.className = 'alert alert-danger';
            alertBox.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.error || data.message || window.MS_TEAMS_I18N.conn_error);
            alertBox.style.display = 'block';
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> ' + window.MS_TEAMS_I18N.btn_save;
        alertBox.className = 'alert alert-danger';
        alertBox.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + window.MS_TEAMS_I18N.conn_error + err;
        alertBox.style.display = 'block';
    });
}

function deleteMapping(id, ext) {
    const confirmMsg = window.MS_TEAMS_I18N.delete_confirm.replace('%s', ext);
    if (!confirm(confirmMsg)) {
        return;
    }

    const formData = new FormData();
    formData.append('id', id);
    formData.append('csrf_token', window.CSRF_TOKEN || '');

    fetch('/ms-teams?action=delete_mapping', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const row = document.getElementById('mapping-row-' + id);
            if (row) row.remove();
        } else {
            alert(data.message || window.MS_TEAMS_I18N.delete_failed);
        }
    })
    .catch(err => alert(window.MS_TEAMS_I18N.conn_error + err));
}

function testTeamsWebhook() {
    const url = document.getElementById('teamsWebhookUrlInput').value.trim();
    const resBox = document.getElementById('webhookTestResult');
    const btn = document.getElementById('btnTestWebhook');

    if (!url) {
        alert(window.MS_TEAMS_I18N.enter_webhook_url);
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + window.MS_TEAMS_I18N.sending;
    resBox.style.display = 'none';

    const formData = new FormData();
    formData.append('webhook_url', url);
    formData.append('csrf_token', window.CSRF_TOKEN || '');

    fetch('/ms-teams?action=test_webhook', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> ' + window.MS_TEAMS_I18N.btn_test;
        resBox.style.display = 'block';
        if (data.success) {
            resBox.className = 'alert alert-success';
            resBox.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
        } else {
            resBox.className = 'alert alert-danger';
            resBox.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + (data.error || data.message || window.MS_TEAMS_I18N.test_failed);
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> ' + window.MS_TEAMS_I18N.btn_test;
        resBox.style.display = 'block';
        resBox.className = 'alert alert-danger';
        resBox.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + window.MS_TEAMS_I18N.conn_error + err;
    });
}

function copyPowerShellScript() {
    const code = document.getElementById('powerShellCodeBlock').innerText;
    navigator.clipboard.writeText(code).then(() => {
        alert(window.MS_TEAMS_I18N.copied);
    }).catch(err => {
        alert(window.MS_TEAMS_I18N.copy_failed + err);
    });
}
