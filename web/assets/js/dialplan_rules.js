/**
 * Dialplan Normalization Rules JavaScript (/dialplan-rules)
 */

document.addEventListener('DOMContentLoaded', function () {
    // Enable reorder on rule tables
    ['table-mobile', 'table-desktop', 'table-web', 'table-all'].forEach(function (tableId) {
        var el = document.getElementById(tableId);
        if (el) {
            UIHelper.enableRowReorder(el, 'dialplan_rules', function (orderedIds) {
                var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                var formData = new FormData();
                formData.append('action', 'reorder');
                formData.append('csrf_token', csrf);
                orderedIds.forEach(function (id) {
                    formData.append('rule_ids[]', id);
                });

                fetch('/dialplan-rules', {
                    method: 'POST',
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
        var simTimer = null;
        simInput.addEventListener('input', function () {
            clearTimeout(simTimer);
            simTimer = setTimeout(runLiveSimulation, 150);
        });
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

function runLiveSimulation() {
    var num = (document.getElementById('sim_input_number')?.value || '').trim();
    var clientType = document.getElementById('sim_select_client')?.value || 'mobile';
    var outBox = document.getElementById('sim_result_container');
    if (!outBox) return;

    if (!num) {
        outBox.innerHTML = '<div class="text-muted u-fs-12" style="padding: 12px; text-align: center;"><i class="fas fa-keyboard"></i> Test etmek istediğiniz numarayı yazın...</div>';
        return;
    }

    var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    var fd = new FormData();
    fd.append('action', 'simulate');
    fd.append('csrf_token', csrf);
    fd.append('number', num);
    fd.append('client_type', clientType);

    fetch('/dialplan-rules', {
        method: 'POST',
        body: fd
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (!res.success || !res.data) return;
        renderSimulationResult(res.data);
    })
    .catch(function () {});
}

function renderSimulationResult(data) {
    var outBox = document.getElementById('sim_result_container');
    if (!outBox) return;

    if (!data.matched) {
        outBox.innerHTML = `
            <div style="background: var(--bg-surface-secondary); padding: 14px; border-radius: 8px; border-left: 4px solid var(--text-muted);">
                <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 4px;">
                    <i class="fas fa-arrow-right"></i> Doğrudan Geçiş (Eşleşen Kural Yok)
                </div>
                <div class="u-fs-12 text-muted">Numara herhangi bir normalleştirme kuralına takılmadan doğrudan Giden Rotalara aktarılır.</div>
                <div style="margin-top: 8px; font-family: monospace; font-size: 15px; font-weight: bold; color: var(--color-primary);">
                    Sonuç: ${escapeHtml(data.normalized)}
                </div>
            </div>
        `;
        return;
    }

    var r = data.rule;
    outBox.innerHTML = `
        <div style="background: rgba(46, 204, 113, 0.08); padding: 14px; border-radius: 8px; border-left: 4px solid #2ecc71;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                <span class="badge badge-success"><i class="fas fa-check-circle"></i> Kural Eşleşti</span>
                <span class="badge badge-info"><code>${escapeHtml(r.match_pattern)}</code></span>
            </div>
            <div style="font-weight: 600; color: var(--text-primary); font-size: 14px;">
                ${escapeHtml(r.rule_name)}
            </div>
            <div class="u-fs-11 text-muted" style="margin-top: 4px;">
                Cihaz: <strong>${escapeHtml(data.client_type.toUpperCase())}</strong> | 
                Baştan Kes: <strong>${r.strip_front}</strong> | 
                Başa Ekle: <strong>${escapeHtml(r.prepend || '-')}</strong>
            </div>
            <div style="margin-top: 10px; display: flex; align-items: center; gap: 8px;">
                <span class="text-muted" style="font-family: monospace;">${escapeHtml(data.original)}</span>
                <i class="fas fa-long-arrow-alt-right" style="color: #2ecc71;"></i>
                <span style="font-family: monospace; font-size: 16px; font-weight: bold; color: #2ecc71;">${escapeHtml(data.normalized)}</span>
            </div>
        </div>
    `;
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
