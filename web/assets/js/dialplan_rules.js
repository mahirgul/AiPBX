/**
 * Dialplan Normalization Rules JavaScript (/dialplan-rules)
 */

function getCsrfToken() {
    return (window.CSRF_TOKEN) ||
           (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')) ||
           (document.querySelector('input[name="csrf_token"]')?.value) ||
           '';
}

document.addEventListener('DOMContentLoaded', function () {
    // Enable reorder on rule tables
    ['table-mobile', 'table-desktop', 'table-web', 'table-all'].forEach(function (tableId) {
        var el = document.getElementById(tableId);
        if (el) {
            UIHelper.enableRowReorder(el, 'dialplan_rules', function (orderedIds) {
                var csrf = getCsrfToken();
                var formData = new FormData();
                formData.append('action', 'reorder');
                formData.append('csrf_token', csrf);
                orderedIds.forEach(function (id) {
                    formData.append('rule_ids[]', id);
                });

                fetch('/dialplan-rules', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': csrf
                    },
                    body: formData
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success && typeof showToast === 'function') {
                        showToast(res.message || 'Updated', 'success');
                    }
                })
                .catch(function () {});
            });
        }
    });

    // Realtime preview in modal
    var testInput = document.getElementById('modal_preview_test');
    if (testInput) {
        testInput.addEventListener('input', updateModalPreview);
    }
    ['modal_strip_front', 'modal_strip_back', 'modal_prepend', 'modal_append'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', updateModalPreview);
        }
    });

    // Simulator input live calculation
    var simInput = document.getElementById('sim_input_number');
    if (simInput) {
        simInput.addEventListener('input', debounceSimulation);
        // Run once on initial page load
        runLiveSimulation();
    }
});

function switchTab(tabKey) {
    document.querySelectorAll('.dialplan-tab-pane').forEach(function (p) {
        p.style.display = 'none';
    });
    document.querySelectorAll('.tab-btn').forEach(function (b) {
        b.classList.remove('active');
    });

    var pane = document.getElementById('pane-' + tabKey);
    var btn = document.getElementById('tab-btn-' + tabKey);
    if (pane) pane.style.display = 'block';
    if (btn) btn.classList.add('active');

    // Update query string without reloading
    if (window.history && window.history.replaceState) {
        var url = new URL(window.location.href);
        url.searchParams.set('tab', tabKey);
        window.history.replaceState(null, '', url.toString());
    }
}

function openCreateRuleModal(clientType) {
    document.getElementById('ruleModalTitle').textContent = t('dialplan_rules.modal_title_new') || 'Yeni Normalleştirme Kuralı';
    document.getElementById('ruleForm').reset();
    document.getElementById('modal_rule_id').value = '0';
    document.getElementById('modal_is_active').checked = true;

    if (clientType) {
        var sel = document.getElementById('modal_client_type');
        if (sel) sel.value = clientType;
    }

    updateModalPreview();
    UIHelper.openModal('ruleModal');
}

function openEditRuleModal(rule) {
    document.getElementById('ruleModalTitle').textContent = t('dialplan_rules.modal_title_edit') || 'Kuralı Düzenle';
    document.getElementById('modal_rule_id').value = rule.id || 0;
    document.getElementById('modal_client_type').value = rule.client_type || 'all';
    document.getElementById('modal_rule_name').value = rule.rule_name || '';
    document.getElementById('modal_match_pattern').value = rule.match_pattern || '';
    document.getElementById('modal_strip_front').value = rule.strip_front || 0;
    document.getElementById('modal_prepend').value = rule.prepend || '';
    document.getElementById('modal_strip_back').value = rule.strip_back || 0;
    document.getElementById('modal_append').value = rule.append || '';
    document.getElementById('modal_description').value = rule.description || '';
    document.getElementById('modal_is_active').checked = (parseInt(rule.is_active, 10) === 1);

    updateModalPreview();
    UIHelper.openModal('ruleModal');
}

function openCopyRuleModal(rule) {
    openEditRuleModal(rule);
    document.getElementById('ruleModalTitle').textContent = t('dialplan_rules.modal_title_copy') || 'Kuralı Kopyala';
    document.getElementById('modal_rule_id').value = '0';
    document.getElementById('modal_rule_name').value = (rule.rule_name || '') + ' (Kopya)';
}

function updateModalPreview() {
    var raw = (document.getElementById('modal_preview_test')?.value || '').trim();
    if (!raw) raw = '+905379902352';

    var front = parseInt(document.getElementById('modal_strip_front')?.value, 10) || 0;
    var back = parseInt(document.getElementById('modal_strip_back')?.value, 10) || 0;
    var prepend = document.getElementById('modal_prepend')?.value || '';
    var append = document.getElementById('modal_append')?.value || '';

    var core = raw;
    if (front > 0 || back > 0) {
        if (back > 0) {
            core = raw.substring(front, Math.max(front, raw.length - back));
        } else {
            core = raw.substring(front);
        }
    }
    var res = prepend + core + append;

    var previewEl = document.getElementById('modal_preview_result');
    if (previewEl) {
        previewEl.textContent = res;
    }
}

var simDebounceTimer = null;
function debounceSimulation() {
    clearTimeout(simDebounceTimer);
    simDebounceTimer = setTimeout(runLiveSimulation, 150);
}

function setSimNumber(num) {
    var el = document.getElementById('sim_input_number');
    if (el) {
        el.value = num;
        runLiveSimulation();
    }
}

function confirmLoadDefaultsWithCc(e, form) {
    var ccInput = form.querySelector('input[name="country_code"]');
    var cc = (ccInput ? ccInput.value.trim() : '') || '90';
    var tmpl = form.dataset.confirmTmpl || 'Load presets for country code +%s? Current rules will be replaced.';
    var msg = tmpl.replace('%s', cc).replace('{cc}', cc);
    if (!confirm(msg)) {
        e.preventDefault();
        return false;
    }
    return true;
}

function runLiveSimulation() {
    var num = (document.getElementById('sim_input_number')?.value || '').trim();
    var outBox = document.getElementById('sim_result_container');
    if (!outBox) return;

    if (!num) {
        var waitingMsg = outBox.dataset.txtWaiting || 'Enter a number to test...';
        outBox.innerHTML = '<div class="text-muted u-fs-12" style="padding: 18px; text-align: center;"><i class="fas fa-keyboard"></i> ' + escapeHtml(waitingMsg) + '</div>';
        return;
    }

    var csrf = getCsrfToken();
    var fd = new FormData();
    fd.append('action', 'simulate');
    fd.append('csrf_token', csrf);
    fd.append('number', num);

    fetch('/dialplan-rules', {
        method: 'POST',
        headers: {
            'X-CSRF-Token': csrf
        },
        body: fd
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (!res.success || !res.data) return;
        renderSimulationResult(res.data);
    })
    .catch(function () {});
}

function renderClientResultCard(type, clientData, original, outBox) {
    var typeIcons = {
        'desktop': 'fa-desktop',
        'mobile': 'fa-mobile-alt',
        'web': 'fa-globe'
    };
    var typeTitles = {
        'desktop': outBox.dataset.txtTabDesktop || 'Desk Phones',
        'mobile': outBox.dataset.txtTabMobile || 'Mobile App',
        'web': outBox.dataset.txtTabWeb || 'Web Softphone & CRM'
    };

    var icon = typeIcons[type] || 'fa-phone';
    var title = typeTitles[type] || type.toUpperCase();
    var txtDirect = outBox.dataset.txtDirect || 'Direct Pass';
    var txtMatched = outBox.dataset.txtMatched || 'Rule Matched';
    var txtStripFront = outBox.dataset.txtStripFront || 'Strip Front';
    var txtPrepend = outBox.dataset.txtPrepend || 'Prepend';

    if (!clientData || !clientData.matched) {
        var norm = clientData ? clientData.normalized : original;
        return `
            <div style="background: var(--bg-surface-secondary); padding: 10px 14px; border-radius: 6px; border-left: 4px solid var(--text-muted); display: flex; flex-direction: column; gap: 4px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div style="font-weight: 600; color: var(--text-primary); font-size: 13px;">
                        <i class="fas ${icon}" style="margin-right: 6px; color: var(--color-primary);"></i> ${escapeHtml(title)}
                    </div>
                    <span class="badge badge-secondary u-fs-10">${escapeHtml(txtDirect)}</span>
                </div>
                <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 4px;">
                    <span class="u-fs-12 text-muted" style="font-family: monospace;">${escapeHtml(original)}</span>
                    <i class="fas fa-arrow-right text-muted" style="font-size: 11px;"></i>
                    <span style="font-family: monospace; font-size: 14px; font-weight: bold; color: var(--text-primary);">${escapeHtml(norm)}</span>
                </div>
            </div>
        `;
    }

    var r = clientData.rule || {};
    var pattern = r.match_pattern || '';
    var ruleName = r.rule_name || '';
    var strip = r.strip_front || 0;
    var prep = r.prepend || '-';

    return `
        <div style="background: rgba(46, 204, 113, 0.08); padding: 10px 14px; border-radius: 6px; border-left: 4px solid #2ecc71; display: flex; flex-direction: column; gap: 4px;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div style="font-weight: 600; color: var(--text-primary); font-size: 13px;">
                    <i class="fas ${icon}" style="margin-right: 6px; color: #2ecc71;"></i> ${escapeHtml(title)}
                </div>
                <div style="display: flex; gap: 4px; align-items: center;">
                    <span class="badge badge-success u-fs-10"><i class="fas fa-check-circle"></i> ${escapeHtml(txtMatched)}</span>
                    <span class="badge badge-info u-fs-10"><code>${escapeHtml(pattern)}</code></span>
                </div>
            </div>
            <div class="u-fs-11 text-muted" style="display: flex; justify-content: space-between; align-items: center;">
                <span>${escapeHtml(ruleName)}</span>
                <span>${escapeHtml(txtStripFront)}: <strong>${strip}</strong> | ${escapeHtml(txtPrepend)}: <strong>${escapeHtml(prep)}</strong></span>
            </div>
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 4px;">
                <span class="u-fs-12 text-muted" style="font-family: monospace;">${escapeHtml(original)}</span>
                <i class="fas fa-arrow-right" style="color: #2ecc71; font-size: 11px;"></i>
                <span style="font-family: monospace; font-size: 14px; font-weight: bold; color: #2ecc71;">${escapeHtml(clientData.normalized)}</span>
            </div>
        </div>
    `;
}

function renderSimulationResult(data) {
    var outBox = document.getElementById('sim_result_container');
    if (!outBox) return;

    var original = data.original || '';
    var desktopHtml = renderClientResultCard('desktop', data.desktop, original, outBox);
    var mobileHtml = renderClientResultCard('mobile', data.mobile, original, outBox);
    var webHtml = renderClientResultCard('web', data.web, original, outBox);

    outBox.innerHTML = `
        <div style="display: flex; flex-direction: column; gap: 8px;">
            ${desktopHtml}
            ${mobileHtml}
            ${webHtml}
        </div>
    `;
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
