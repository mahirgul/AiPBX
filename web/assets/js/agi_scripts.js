/**
 * PBX -> AGI Scripts: editor, versions and test.
 */
function agiPost(params) {
    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('#agiForm [name="csrf_token"]').value);
    Object.entries(params).forEach(([k, v]) => fd.append(k, v));
    return fetch(location.pathname, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(r => r.json());
}

/** Opens the editor empty (row = null) or with a saved script (its code is fetched). */
async function agiOpen(row) {
    const f = document.getElementById('agiForm');
    f.reset();
    const set = (id, v) => { document.getElementById(id).value = v ?? ''; };
    document.getElementById('agiModalTitle').innerHTML = '<i class="fas fa-terminal u-primary"></i> '
        + escapeHtml(row ? __('js.agi_scripts.edit_title') + row.name : __('js.agi_scripts.new_title'));
    set('agi_id', row?.id);
    set('agi_name', row?.name);
    set('agi_language', row?.language || 'python');
    set('agi_timeout', row?.timeout_sec || 10);
    set('agi_memory', row?.memory_mb || 128);
    set('agi_description', row?.description);
    document.getElementById('agi_network').checked = row ? parseInt(row.network_access) === 1 : false;
    document.getElementById('agi_active').checked = row ? parseInt(row.is_active) === 1 : true;
    document.getElementById('agiSavedTools').style.display = row ? '' : 'none';
    document.getElementById('agiTestOut').hidden = true;
    set('agi_content', row ? '' : (window.AGI_TEMPLATES || {}).python || '');
    UIHelper.openOverlayModal('agiModal');
    if (row) {
        const r = await agiPost({ ajax: 'get', id: row.id });
        if (r.ok) set('agi_content', r.script.content);
        agiLoadVersions(row.id);
    }
}

function agiClose() {
    UIHelper.closeOverlayModal('agiModal');
}

function agiLoadTemplate() {
    const lang = document.getElementById('agi_language').value;
    const box = document.getElementById('agi_content');
    if (box.value.trim() !== '' && !confirm(__('js.agi_scripts.replace_code'))) return;
    box.value = (window.AGI_TEMPLATES || {})[lang] || '';
}

/** Saved versions: view one in the editor, or go back to it (a new save). */
async function agiLoadVersions(id) {
    const box = document.getElementById('agiVersions');
    box.textContent = __('js.common.loading');
    const r = await agiPost({ ajax: 'versions', id: id });
    box.textContent = '';
    (r.versions || []).forEach((v, i) => {
        const row = document.createElement('div');
        row.className = 'u-d-flex u-ai-center u-gap-8 u-mb-4';
        const label = document.createElement('span');
        label.textContent = v.created_at + ' · ' + v.language + ' · ' + v.size + ' B' + (i === 0 ? ' · ' + __('js.agi_scripts.current') : '');
        row.appendChild(label);
        if (i > 0) {
            const view = document.createElement('button');
            view.type = 'button';
            view.className = 'btn btn-secondary btn-sm';
            view.textContent = __('js.agi_scripts.view');
            view.onclick = async () => {
                const c = await agiPost({ ajax: 'version', id: id, version_id: v.id });
                if (c.ok) {
                    document.getElementById('agi_content').value = c.content;
                    document.getElementById('agi_language').value = c.language;
                }
            };
            const restore = document.createElement('button');
            restore.type = 'button';
            restore.className = 'btn btn-secondary btn-sm';
            restore.textContent = __('js.agi_scripts.restore');
            restore.onclick = () => {
                if (!confirm(__('js.agi_scripts.restore_confirm'))) return;
                const f = document.getElementById('agiRestoreForm');
                f.querySelector('[name="id"]').value = id;
                f.querySelector('[name="version_id"]').value = v.id;
                f.submit();
            };
            row.append(view, restore);
        }
        box.appendChild(row);
    });
}

/** Runs the saved script as a call would, with the sample values; shows its AGI commands. */
async function agiTest() {
    const out = document.getElementById('agiTestOut');
    out.hidden = false;
    out.textContent = __('js.common.loading');
    const params = { ajax: 'test', id: document.getElementById('agi_id').value };
    document.querySelectorAll('[data-agi-sample]').forEach(el => { params['sample[' + el.dataset.agiSample + ']'] = el.value; });
    try {
        const r = await agiPost(params);
        const lines = [(r.ok ? 'OK' : __('js.agi_scripts.failed')) + (r.ms !== undefined ? ' (' + r.ms + ' ms)' : '')];
        if (r.error) lines.push(r.error);
        Object.entries(r.set || {}).forEach(([k, v]) => lines.push(k + ' = ' + v));
        (r.log || []).forEach(l => lines.push('> ' + l));
        out.textContent = lines.join('\n');
    } catch (e) {
        out.textContent = __('js.common.connection_error');
    }
}
