/**
 * PBX -> Dynamic Routing: the step form (input, source, rules, test).
 */
let drRuleSeq = 0;

function drVal(id) {
    const el = document.getElementById(id);
    return el ? el.value : '';
}

/** Shows only the fields of the chosen input, source, method and options. */
function drToggle() {
    const input = drVal('dr_input_source');
    const source = drVal('dr_source');
    const method = drVal('dr_method');
    const nameMode = drVal('dr_name_mode');
    const sayMode = drVal('dr_say_mode');
    const direct = document.getElementById('dr_direct')?.checked ? '1' : '';
    const show = (sel, value) => document.querySelectorAll('#drForm [' + sel + ']').forEach(el => {
        el.style.display = el.getAttribute(sel).split(' ').includes(value) ? '' : 'none';
    });
    show('data-dr-input', input);
    show('data-dr-source', source);
    show('data-dr-method', method);
    show('data-dr-name', nameMode);
    show('data-dr-say', sayMode);
    show('data-dr-direct', direct);
}

function drRuleToggle(typeEl) {
    const row = typeEl.closest('[data-dr-rule]');
    row.querySelector('[data-f="value2"]').style.display = typeEl.value === 'range' ? '' : 'none';
}

/** One rule row; its destination pair is filled like the others on the page. */
function drAddRule(rule) {
    const tpl = document.getElementById('drRuleTpl');
    const row = tpl.content.firstElementChild.cloneNode(true);
    const n = drRuleSeq++;
    row.querySelectorAll('[data-f]').forEach(el => {
        el.name = 'rules[' + n + '][' + el.dataset.f + ']';
        el.id = 'dr_rule_' + n + '_' + el.dataset.f;
    });
    const typeEl = row.querySelector('[data-f="type"]');
    const destType = row.querySelector('[data-f="dest_type"]');
    typeEl.value = rule?.type || 'exact';
    row.querySelector('[data-f="value"]').value = rule?.value || '';
    row.querySelector('[data-f="value2"]').value = rule?.value2 || '';
    destType.value = rule?.dest_type || 'queue';
    document.getElementById('drRules').appendChild(row);
    drRuleToggle(typeEl);
    bindDestinationSelector(destType.id, 'dr_rule_' + n + '_dest_id');
    loadDestinationOptions(destType.id, 'dr_rule_' + n + '_dest_id', rule?.dest_id || '');
}

function drSetDest(prefix, type, id) {
    const typeEl = document.getElementById('dr_' + prefix + '_dest_type');
    if (!typeEl) return;
    typeEl.value = type;
    loadDestinationOptions(typeEl.id, 'dr_' + prefix + '_dest_id', id || '');
}

/** Opens the form empty (s = null) or with a saved step. */
function drOpen(s) {
    const f = document.getElementById('drForm');
    f.reset();
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v ?? ''; };
    const title = document.getElementById('drModalTitle');
    title.innerHTML = '<i class="fas fa-route u-primary"></i> ' + escapeHtml(s ? __('js.dynamic_routing.edit_title') + (s.name || '') : __('js.dynamic_routing.new_title'));
    set('dr_id', s?.id);
    set('dr_name', s?.name);
    set('dr_description', s?.description);
    set('dr_channel_var', s?.channel_var_name || 'IVR_DATA_VAL');
    set('dr_input_source', s?.input_source || 'dtmf');
    set('dr_input_var', s?.input_var);
    set('dr_prompt', s?.prompt_file);
    set('dr_invalid_prompt', s?.invalid_prompt);
    set('dr_min_digits', s?.min_digits || 1);
    set('dr_max_digits', s?.max_digits || 10);
    set('dr_timeout', s?.input_timeout_sec || 5);
    set('dr_retries', s?.retries || 0);
    set('dr_source', (!s || s.data_source === 'database') ? 'saved_query' : s.data_source);
    set('dr_saved_query', s?.saved_query_id);
    set('dr_url', s?.http_url);
    set('dr_method', s?.http_method || 'GET');
    set('dr_body', s?.http_body);
    set('dr_json_path', s?.json_path);
    set('dr_agi', s?.agi_script);
    set('dr_value', s?.value_template);
    set('dr_prefix', s?.var_prefix);
    set('dr_name_mode', s?.caller_name_mode);
    set('dr_name_tpl', s?.caller_name_template);
    set('dr_say_mode', s?.say_mode || 'none');
    set('dr_say_tpl', s?.say_template);
    set('dr_cache_ttl', s?.cache_ttl_sec || 0);
    const cdrEl = document.getElementById('dr_cdr_write');
    if (cdrEl) cdrEl.checked = s ? parseInt(s.cdr_write) === 1 : false;
    document.getElementById('dr_headers').placeholder = s && parseInt(s.has_headers) === 1
        ? __('js.dynamic_routing.headers_saved') : 'Authorization: Bearer …';
    document.getElementById('dr_headers_clear_box').style.display = s && parseInt(s.has_headers) === 1 ? '' : 'none';
    document.getElementById('dr_direct').checked = s ? parseInt(s.direct_extension) === 1 : false;
    document.getElementById('dr_active').checked = s ? parseInt(s.is_active) === 1 : true;
    drSetDest('found', s?.found_dest_type || 'hangup', s?.found_dest_id);
    drSetDest('not_found', s?.not_found_dest_type || 'hangup', s?.not_found_dest_id);
    drSetDest('timeout', s?.timeout_dest_type || 'hangup', s?.timeout_dest_id);
    drSetDest('busy', s?.busy_dest_type || '', s?.busy_dest_id);
    document.getElementById('drRules').innerHTML = '';
    (s?.rules || []).forEach(r => drAddRule(r));
    const out = document.getElementById('drTestOut');
    out.hidden = true;
    out.textContent = '';
    drToggle();
    UIHelper.openOverlayModal('drModal');
}

function drClose() {
    UIHelper.closeOverlayModal('drModal');
}

/** Runs the source as it is in the form with the sample values; shows value, fields and time. */
async function drTest() {
    const fd = new FormData(document.getElementById('drForm'));
    fd.delete('save_step');
    fd.append('test_step', '1');
    document.querySelectorAll('[data-dr-sample]').forEach(el => fd.append('sample[' + el.dataset.drSample + ']', el.value));
    const out = document.getElementById('drTestOut');
    out.hidden = false;
    out.textContent = __('js.common.loading');
    try {
        const res = await fetch(location.pathname, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const r = await res.json();
        const lines = [];
        if (r.status) lines.push(__('js.dynamic_routing.test_status') + ': ' + r.status + (r.ms !== undefined ? ' (' + r.ms + ' ms)' : ''));
        if (r.value !== undefined) lines.push(__('js.dynamic_routing.test_value') + ': ' + r.value);
        Object.entries(r.vars || {}).forEach(([k, v]) => lines.push('  ' + k + ' = ' + v));
        if (r.error) lines.push(__('js.dynamic_routing.test_error') + ': ' + r.error);
        (r.log || []).forEach(l => lines.push('> ' + l));
        out.textContent = lines.join('\n');
    } catch (e) {
        out.textContent = __('js.common.connection_error');
    }
}
