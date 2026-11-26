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
    const render = f => {
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
        } else if (aiCloudModelKind(def, f.key)) {
            // A model is chosen from a list: the known models first, the provider's own list once it is loaded.
            // The text field stays hidden; it carries the value and opens only for "Other".
            const kind = aiCloudModelKind(def, f.key);
            input = '<select class="form-control" data-model-for="' + name + '" data-kind="' + kind + '" data-default="'
                + escapeHtml(f.placeholder || f.default || '') + '" onchange="aiCloudPickModel(this)"></select>'
                + '<input type="text" name="' + name + '" class="form-control u-mt-6" value="' + escapeHtml(f.value || '')
                + '" placeholder="' + escapeHtml(__('js.ai_cloud.model_other_hint')) + '" style="display:none">';
        } else {
            input = '<input type="text" name="' + name + '" class="form-control" value="' + escapeHtml(f.value || '') + '" placeholder="' + escapeHtml(f.placeholder || f.default || '') + '">';
        }
        return '<div class="form-group u-mb-8' + (f.json ? ' u-grid-column-1-1' : '') + '"><label class="form-label">' + label + '</label>' + input + '</div>';
    };
    const basic = fields.filter(f => !f.advanced).map(render).join('');
    const advanced = fields.filter(f => f.advanced).map(render).join('');
    box.innerHTML = basic + (advanced
        ? '<details class="u-grid-column-1-1 u-mt-6"><summary class="u-pointer u-fs-12 u-fw-600">' + escapeHtml(__('js.ai_cloud.advanced')) + '</summary><div class="u-grid-2 u-mt-8">' + advanced + '</div></details>'
        : '');
    const status = document.getElementById('aiCloudModelsStatus');
    status.textContent = '';
    const supportsDiscover = (def.caps || []).includes('llm');
    box.querySelectorAll('select[data-model-for]').forEach(sel => aiCloudFillModels(sel, (def.models || {})[sel.dataset.kind] || [], supportsDiscover));
    if (!supportsDiscover || !box.querySelector('select[data-model-for]')) {
        return;
    }
    // The provider's full list needs its key: a saved account asks at once, a new one as soon as the key is typed.
    const keyInput = box.querySelector('[name="api_key"]');
    if (keyInput) {
        // asked again while the key is typed or pasted (after a short pause), with the new key, never from the cache
        let timer = null;
        const ask = () => {
            clearTimeout(timer);
            if (keyInput.value.trim().length >= 8) timer = setTimeout(() => aiCloudLoadModels(true), 700);
        };
        keyInput.addEventListener('input', ask);
        keyInput.addEventListener('change', ask);
    }
    const keyField = fields.find(f => f.key === 'api_key');
    const keyRequired = Boolean(keyInput && keyField && !keyField.optional);
    if ((acc && acc.type === type) || !keyRequired) {
        aiCloudLoadModels(false);
    } else {
        status.className = 'u-fs-12 u-mt-6 u-muted';
        status.textContent = __('js.ai_cloud.models_need_key');
    }
}

/** The choices of one model list: the default, the models, the stored value if it is not among them, "Other".
 *  `known`: the built-in list, not yet the provider's: a note in the list says the API key brings the full one. */
function aiCloudFillModels(sel, models, known) {
    const input = document.getElementById('aiCloudAccountForm').querySelector('[name="' + sel.dataset.modelFor + '"]');
    const current = input.value;
    const list = models.slice();
    if (current && !list.includes(current)) {
        list.unshift(current);
    }
    const def = sel.dataset.default;
    sel.innerHTML = '<option value="">' + escapeHtml(def ? __('js.ai_cloud.model_default').replace('%s', def) : __('js.ai_cloud.model_default_plain')) + '</option>'
        + list.map(m => '<option value="' + escapeHtml(m) + '"' + (m === current ? ' selected' : '') + '>' + escapeHtml(m) + '</option>').join('')
        + '<option value="__other">' + escapeHtml(__('js.ai_cloud.model_other')) + '</option>'
        + (known ? '<option value="" disabled>' + escapeHtml(__('js.ai_cloud.model_list_note')) + '</option>' : '');
    input.style.display = 'none';
}

/** Which model list a field takes: language, speech-to-text, text-to-speech or Realtime models. */
function aiCloudModelKind(def, fieldKey) {
    if (fieldKey === 'model') {
        const hasOwnLlmField = Boolean(def && (def.fields || []).some(x => x.key === 'llm_model'));
        const hasLlmCap = Boolean(def && (def.caps || []).includes('llm'));
        return (hasOwnLlmField || !hasLlmCap) ? 'tts' : 'llm';
    }
    return { llm_model: 'llm', stt_model: 'stt', tts_model: 'tts', realtime_model: 'realtime' }[fieldKey] || null;
}

let aiCloudModelsSeq = 0;

/** Asks the provider for its models (with the dialog's values, cached 15 minutes) and fills every model list. */
function aiCloudLoadModels(refresh) {
    const form = document.getElementById('aiCloudAccountForm');
    const status = document.getElementById('aiCloudModelsStatus');
    const id = document.getElementById('aiCloudAccountId').value;
    const def = aiCloudType(document.getElementById('aiCloudAccountType').value);
    if (!def || !(def.caps || []).includes('llm') || !form.querySelector('[data-model-for]')) {
        return;
    }
    const seq = ++aiCloudModelsSeq;      // only the newest answer fills the lists
    const data = new FormData(form);
    data.set('action', 'discover');
    data.set('provider', id || def.type);
    data.set('is_new', id ? '0' : '1');
    if (refresh) data.set('refresh', '1');
    data.delete('service_account_file');
    status.className = 'u-fs-12 u-mt-6 u-muted';
    status.textContent = __('js.ai_cloud.models_loading');
    fetch('/api/ai_cloud.php', { method: 'POST', body: data, headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' } })
        .then(r => r.json())
        .then(d => {
            if (seq !== aiCloudModelsSeq) {
                return;
            }
            if (!d.success) {
                status.className = 'u-fs-12 u-mt-6 u-danger';
                status.textContent = __('js.ai_cloud.models_failed') + ' ' + d.error;
                return;
            }
            const kinds = d.kinds || {};
            const known = def.models || {};
            form.querySelectorAll('select[data-model-for]').forEach(sel => {
                const fit = (d.models || []).filter(m => (kinds[m] || 'llm') === sel.dataset.kind);
                aiCloudFillModels(sel, fit.length ? fit : (known[sel.dataset.kind] || []), !fit.length);
            });
            status.className = 'u-fs-12 u-mt-6 u-muted';
            status.innerHTML = escapeHtml(__('js.ai_cloud.models_loaded').replace('%d', (d.models || []).length))
                + ' · <a href="#" onclick="aiCloudLoadModels(true); return false;">' + escapeHtml(__('js.ai_cloud.models_refresh')) + '</a>';
        })
        .catch(err => {
            status.className = 'u-fs-12 u-mt-6 u-danger';
            status.textContent = __('js.common.connection_error_colon') + err.message;
        });
}

/** A model chosen from the list goes into its field; "Other" opens the field to type a name. */
function aiCloudPickModel(sel) {
    const input = document.getElementById('aiCloudAccountForm').querySelector('[name="' + sel.dataset.modelFor + '"]');
    if (sel.value === '__other') {
        input.value = '';
        input.style.display = '';
        input.focus();
        return;
    }
    input.value = sel.value;
    input.style.display = 'none';
}

/** Tests what is typed in the dialog (a stored secret is used when its field is left empty). */
function aiCloudTestAccount() {
    const form = document.getElementById('aiCloudAccountForm');
    const out = document.getElementById('aiCloudTestResult');
    const data = new FormData(form);
    const id = document.getElementById('aiCloudAccountId').value;
    data.set('action', 'test');
    data.set('provider', id || document.getElementById('aiCloudAccountType').value);
    data.set('is_new', id ? '0' : '1');
    data.delete('service_account_file');
    out.className = 'u-fs-12 u-mt-10 u-muted';
    out.textContent = __('js.ai_cloud.testing');
    fetch('/api/ai_cloud.php', { method: 'POST', body: data, headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' } })
        .then(r => r.json())
        .then(d => {
            out.className = 'u-fs-12 u-mt-10 ' + (d.success ? 'u-success' : 'u-danger');
            out.textContent = d.success ? d.message : d.error;
            if (d.success) aiCloudLoadModels(false);
        })
        .catch(err => {
            out.className = 'u-fs-12 u-mt-10 u-danger';
            out.textContent = __('js.common.connection_error_colon') + err.message;
        });
}
