/**
 * Call normalization rules (/dialplan-rules): rule modal with live preview,
 * the simulator, and the country presets confirmation.
 * Texts come from window.DPR_I18N (set by the view).
 */

function dprText(key, fallback) {
    return (window.DPR_I18N && window.DPR_I18N[key]) || fallback;
}

function getCsrfToken() {
    return (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')) ||
           (document.querySelector('input[name="csrf_token"]')?.value) || '';
}

function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

document.addEventListener('DOMContentLoaded', function () {
    ['modal_preview_test', 'modal_strip_front', 'modal_strip_back', 'modal_prepend', 'modal_append'].forEach(function (id) {
        document.getElementById(id)?.addEventListener('input', updateModalPreview);
    });
});

/* ---------------------------------------------------------------- modal */

function openCreateRuleModal(clientType) {
    document.getElementById('ruleForm').reset();
    document.getElementById('ruleModalTitle').textContent = dprText('modal_new', 'New rule');
    document.getElementById('modal_rule_id').value = '0';
    document.getElementById('modal_is_active').checked = true;
    if (clientType) {
        document.getElementById('modal_client_type').value = clientType;
    }
    updateModalPreview();
    UIHelper.openModal('ruleModal');
}

function openEditRuleModal(rule) {
    document.getElementById('ruleModalTitle').textContent = dprText('modal_edit', 'Edit rule');
    document.getElementById('modal_rule_id').value = rule.id || 0;
    document.getElementById('modal_client_type').value = rule.client_type || 'all';
    document.getElementById('modal_rule_name').value = rule.rule_name || '';
    document.getElementById('modal_match_pattern').value = rule.match_pattern || '';
    document.getElementById('modal_strip_front').value = rule.strip_front || 0;
    document.getElementById('modal_prepend').value = rule.prepend ?? '';
    document.getElementById('modal_strip_back').value = rule.strip_back || 0;
    document.getElementById('modal_append').value = rule.append ?? '';
    document.getElementById('modal_description').value = rule.description || '';
    document.getElementById('modal_is_active').checked = (parseInt(rule.is_active, 10) === 1);
    updateModalPreview();
    UIHelper.openModal('ruleModal');
}

function openCopyRuleModal(rule) {
    openEditRuleModal(rule);
    document.getElementById('ruleModalTitle').textContent = dprText('modal_copy', 'Copy rule');
    document.getElementById('modal_rule_id').value = '0';
    document.getElementById('modal_rule_name').value = (rule.rule_name || '') + ' ' + dprText('copy_suffix', '(copy)');
}

/** The rule's transformation applied to the sample number (the pattern is not checked). */
function updateModalPreview() {
    var raw = (document.getElementById('modal_preview_test')?.value || '').trim();
    var out = document.getElementById('modal_preview_result');
    if (!out) return;
    if (!raw) { out.textContent = '—'; return; }

    var front = parseInt(document.getElementById('modal_strip_front')?.value, 10) || 0;
    var back = parseInt(document.getElementById('modal_strip_back')?.value, 10) || 0;
    var core = back > 0 ? raw.substring(front, Math.max(front, raw.length - back)) : raw.substring(front);
    out.textContent = (document.getElementById('modal_prepend')?.value || '') + core + (document.getElementById('modal_append')?.value || '');
}

/* ------------------------------------------------------------- presets */

function confirmLoadDefaults(form) {
    var cc = form.querySelector('[name=country_code]').value;
    if (!cc) {
        alert(form.dataset.msgRequired);
        return false;
    }
    return confirm((form.dataset.confirmTmpl || '').replace('%s', cc));
}

/* ----------------------------------------------------------- simulator */

var simDebounceTimer = null;
function debounceSimulation() {
    clearTimeout(simDebounceTimer);
    simDebounceTimer = setTimeout(runLiveSimulation, 200);
}

function setSimNumber(num) {
    var el = document.getElementById('sim_input_number');
    if (el) {
        el.value = num;
        runLiveSimulation();
    }
}

function runLiveSimulation() {
    var table = document.getElementById('sim_result_table');
    var num = (document.getElementById('sim_input_number')?.value || '').trim();
    if (!table) return;
    var body = table.querySelector('tbody');
    if (!num) {
        body.innerHTML = '<tr><td colspan="3" class="text-muted text-center">' + escapeHtml(table.dataset.txtWaiting) + '</td></tr>';
        return;
    }

    var csrf = getCsrfToken();
    var fd = new FormData();
    fd.append('action', 'simulate');
    fd.append('csrf_token', csrf);
    fd.append('number', num);
    fetch('/dialplan-rules', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) { if (res.success && res.data) renderSimulation(table, res.data); })
        .catch(function () {});
}

function renderSimulation(table, data) {
    var rows = ['desktop', 'mobile', 'web'].map(function (type) {
        var d = data[type] || {};
        var label = table.dataset['txt' + type.charAt(0).toUpperCase() + type.slice(1)] || type;
        var rule = d.matched && d.rule
            ? escapeHtml(d.rule.rule_name) + ' <code class="dpr-pattern">' + escapeHtml(d.rule.match_pattern) + '</code>'
            : '<span class="text-muted">' + escapeHtml(table.dataset.txtNoRule) + '</span>';
        var result = data.enabled ? (d.normalized ?? data.original) : data.original;
        return '<tr><td>' + escapeHtml(label) + '</td><td>' + rule + '</td>' +
               '<td class="text-right"><span class="dpr-result' + (d.matched && data.enabled ? ' dpr-result-changed' : '') + '">' +
               escapeHtml(result) + '</span></td></tr>';
    });
    if (!data.enabled) {
        rows.push('<tr><td colspan="3" class="text-muted u-fs-12"><i class="fas fa-power-off"></i> ' + escapeHtml(table.dataset.txtDisabled) + '</td></tr>');
    }
    table.querySelector('tbody').innerHTML = rows.join('');
}
