/* AI -> Cloud AI: the add / edit dialog of one provider account (templates/views/ai_cloud/index.php). */

function aiCloudData() {
    return window.AI_CLOUD || { types: [], accounts: [] };
}

function aiCloudType(type) {
    return aiCloudData().types.find(t => t.type === type) || null;
}

function aiCloudAccount(id) {
    return aiCloudData().accounts.find(a => a.id === id) || null;
}

/** Opens the dialog: '' adds a new account, an id edits that one. */
function aiCloudOpenAccount(id) {
    const acc = id ? aiCloudAccount(id) : null;
    const typeSel = document.getElementById('aiCloudAccountType');
    document.getElementById('aiCloudAccountId').value = acc ? acc.id : '';
    document.getElementById('aiCloudAccountName').value = acc ? (acc.name || '') : '';
    document.getElementById('aiCloudAccountTitle').innerHTML = '<i class="fas ' + (acc ? 'fa-pen' : 'fa-plus') + ' u-primary"></i> '
        + escapeHtml(acc ? __('js.ai_cloud.edit_title') : __('js.ai_cloud.add_title'));
    if (acc) {
        typeSel.value = acc.type;
    }
    // The provider of an existing account cannot change: its settings belong to that provider.
    typeSel.disabled = !!acc;
    const limits = acc ? (acc.limits || {}) : {};
    ['stt_min', 'tts_chars', 'llm_tokens'].forEach(k => {
        const el = document.getElementById('aiCloudLimit_' + k);
        if (el) el.value = limits[k] ? limits[k] : '';
    });
    document.getElementById('aiCloudTestResult').textContent = '';
    aiCloudRenderFields();
    UIHelper.openOverlayModal('aiCloudAccountModal');
}

/** The fields of the chosen provider; an edited account shows its stored values (secrets only masked). */
function aiCloudRenderFields() {
    const type = document.getElementById('aiCloudAccountType').value;
    const id = document.getElementById('aiCloudAccountId').value;
    const acc = id ? aiCloudAccount(id) : null;
    const def = aiCloudType(type);
    const box = document.getElementById('aiCloudAccountFields');
    const signup = document.getElementById('aiCloudAccountSignup');
    if (!def) {
        box.innerHTML = '';
        return;
    }
    signup.innerHTML = def.url ? '<a href="' + escapeHtml(def.url) + '" target="_blank" rel="noopener">' + escapeHtml(__('js.ai_cloud.get_key')) + '</a>' : '';
    const fields = acc && acc.type === type ? acc.fields : def.fields;
    box.innerHTML = fields.map(f => {
        const name = escapeHtml(f.key);
        const label = escapeHtml(f.label || f.key) + (f.optional ? ' <span class="u-muted u-fs-11">(' + escapeHtml(__('js.ai_cloud.optional')) + ')</span>' : '');
        let input;
        if (f.options) {
            const options = Object.entries(f.options).map(([value, text]) =>
                '<option value="' + escapeHtml(value) + '"' + (value === (f.value || '') ? ' selected' : '') + '>'
                + escapeHtml(text) + '</option>').join('');
            input = '<select name="' + name + '" class="form-control">' + options + '</select>';
        } else if (f.json) {
            input = '<textarea name="' + name + '" class="form-control u-mono u-fs-12" rows="3" placeholder="' + escapeHtml(f.masked || '{ "type": "service_account", ... }') + '"></textarea>'
                + '<input type="file" name="service_account_file" accept=".json,application/json" class="form-control form-control-sm u-mt-6">';
        } else if (f.secret) {
            input = '<input type="password" name="' + name + '" class="form-control" autocomplete="new-password" placeholder="'
                + escapeHtml(f.masked ? f.masked + ' — ' + __('js.ai_cloud.keep_secret') : '') + '">';
        } else {
            input = '<input type="text" name="' + name + '" class="form-control" value="' + escapeHtml(f.value || '') + '" placeholder="' + escapeHtml(f.placeholder || f.default || '') + '">';
        }
        return '<div class="form-group u-mb-8' + (f.json ? ' u-grid-column-1-1' : '') + '"><label class="form-label">' + label + '</label>' + input + '</div>';
    }).join('');
}

/** Tests what is typed in the dialog (a stored secret is used when its field is left empty). */
function aiCloudTestAccount() {
    const form = document.getElementById('aiCloudAccountForm');
    const out = document.getElementById('aiCloudTestResult');
    const data = new FormData(form);
    const id = document.getElementById('aiCloudAccountId').value;
    data.set('action', 'test');
    data.set('provider', id || document.getElementById('aiCloudAccountType').value);
    data.delete('service_account_file');
    out.className = 'u-fs-12 u-mt-10 u-muted';
    out.textContent = __('js.ai_cloud.testing');
    fetch('/api/ai_cloud.php', { method: 'POST', body: data, headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' } })
        .then(r => r.json())
        .then(d => {
            out.className = 'u-fs-12 u-mt-10 ' + (d.success ? 'u-success' : 'u-danger');
            out.textContent = d.success ? d.message : d.error;
        })
        .catch(err => {
            out.className = 'u-fs-12 u-mt-10 u-danger';
            out.textContent = __('js.common.connection_error_colon') + err.message;
        });
}
