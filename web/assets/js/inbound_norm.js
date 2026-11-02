/**
 * Inbound Call Normalization (/inbound-normalization): tabs, the rule window with its preview, and
 * the simulator. Texts come from window.INBOUND_NORM_I18N (set by the view).
 */
function showInboundTab(key, btn) {
    document.querySelectorAll('.settings-tab-pane').forEach(function (pane) {
        pane.style.display = pane.id === 'inbound_tab_' + key ? 'block' : 'none';
    });
    document.querySelectorAll('.settings-tab-btn').forEach(function (b) { b.classList.toggle('active', b === btn); });
}

function fillInboundForm(rule, title) {
    document.getElementById('inboundRuleModalTitle').textContent = title;
    document.getElementById('inbound_rule_id').value = rule.id || 0;
    document.getElementById('inbound_target').value = rule.target || 'caller';
    document.getElementById('inbound_trunk').value = rule.trunk_name || '';
    document.getElementById('inbound_rule_name').value = rule.rule_name || '';
    document.getElementById('inbound_pattern').value = rule.match_pattern || '';
    document.getElementById('inbound_strip').value = rule.strip_front || 0;
    document.getElementById('inbound_prepend').value = rule.prepend ?? '';
    document.getElementById('inbound_is_active').checked = rule.is_active === undefined || parseInt(rule.is_active, 10) === 1;
    updateInboundPreview();
    UIHelper.openOverlayModal('inboundRuleModal');
}

function openCreateInboundRule(tab) {
    document.getElementById('inboundRuleForm').reset();
    fillInboundForm({ target: tab === 'did' ? 'did' : 'caller' }, window.INBOUND_NORM_I18N.new);
}

function openEditInboundRule(btn) {
    fillInboundForm(JSON.parse(btn.dataset.rule), window.INBOUND_NORM_I18N.edit);
}

function openCopyInboundRule(btn) {
    const rule = JSON.parse(btn.dataset.rule);
    rule.id = 0;
    rule.rule_name = (rule.rule_name || '') + ' ' + window.INBOUND_NORM_I18N.copy_suffix;
    fillInboundForm(rule, window.INBOUND_NORM_I18N.copy);
}

function updateInboundPreview() {
    const raw = document.getElementById('inbound_preview_test').value.trim();
    const strip = parseInt(document.getElementById('inbound_strip').value, 10) || 0;
    document.getElementById('inbound_preview_result').textContent =
        raw ? document.getElementById('inbound_prepend').value + raw.substring(strip) : '—';
}

var inboundSimTimer = null; // var: the portal can load this script more than once
function debounceInboundSim() {
    clearTimeout(inboundSimTimer);
    inboundSimTimer = setTimeout(runInboundSim, 200);
}

function runInboundSim() {
    const table = document.getElementById('inbound_sim_table');
    const number = document.getElementById('inbound_sim_number').value.trim();
    const body = table.querySelector('tbody');
    if (!number) {
        body.innerHTML = '<tr><td colspan="3" class="text-muted text-center">' + escapeHtml(table.dataset.txtWaiting) + '</td></tr>';
        return;
    }
    const data = new FormData();
    data.append('action', 'simulate');
    data.append('csrf_token', document.querySelector('#inboundRuleForm input[name="csrf_token"]').value);
    data.append('number', number);
    data.append('trunk_name', document.getElementById('inbound_sim_trunk').value);
    fetch('/inbound-normalization', { method: 'POST', body: data })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success) return;
            body.innerHTML = ['caller', 'did'].map(function (target) {
                const row = res.data[target];
                const label = target === 'did' ? table.dataset.txtDid : table.dataset.txtCaller;
                return '<tr><td>' + escapeHtml(label) + '</td><td>'
                    + (row.rule ? escapeHtml(row.rule) : '<span class="text-muted">' + escapeHtml(table.dataset.txtNoRule) + '</span>')
                    + '</td><td class="text-right"><span class="dpr-result' + (row.rule ? ' dpr-result-changed' : '') + '">' + escapeHtml(row.result) + '</span></td></tr>';
            }).join('');
        })
        .catch(function () {});
}
