/**
 * Time Conditions & Time Groups Client Module (Multi-Rule Support)
 */
var ruleCounter = window.ruleCounter || 0;

document.addEventListener('DOMContentLoaded', function() {
    bindDestinationSelector('modal_nomatch_dest_type', 'modal_nomatch_dest_id');
});
document.addEventListener('spa:pageLoaded', function() {
    bindDestinationSelector('modal_nomatch_dest_type', 'modal_nomatch_dest_id');
});

function switchTcTab(tabName) {
    document.querySelectorAll('.tc-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tc-tab-pane').forEach(pane => pane.style.display = 'none');

    const activeBtn = document.getElementById('tab-btn-' + tabName);
    const activePane = document.getElementById('tab-pane-' + tabName);

    if (activeBtn) activeBtn.classList.add('active');
    if (activePane) activePane.style.display = 'block';
}

function openCreateTcModal() {
    document.getElementById('tcModalTitle').innerHTML = '<i class="fas fa-plus-circle" style="color: var(--primary);"></i> Yeni Zaman Koşulu Ekle';
    document.getElementById('modal_tc_id').value = '0';
    document.getElementById('modal_title').value = '';
    var inEl = document.getElementById('modal_internal_number');
    if (inEl) inEl.value = '';

    const actCb = document.getElementById('modal_is_active');
    if (actCb) actCb.checked = true;

    document.getElementById('modal_nomatch_dest_type').value = 'announcement';
    loadDestinationOptions('modal_nomatch_dest_type', 'modal_nomatch_dest_id', '');

    const container = document.getElementById('tc_rules_container');
    if (container) container.innerHTML = '';
    ruleCounter = 0;

    // Add initial default rule
    addTcRuleRow();

    const modal = document.getElementById('tcModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function openEditTcModal(tc) {
    if (!tc) return;

    document.getElementById('tcModalTitle').innerHTML = '<i class="fas fa-edit" style="color: var(--primary);"></i> Zaman Koşulu Düzenle #' + tc.id;
    document.getElementById('modal_tc_id').value = tc.id;
    document.getElementById('modal_title').value = tc.title || '';
    var inEl = document.getElementById('modal_internal_number');
    if (inEl) inEl.value = (tc.internal_number || '');

    const actCb = document.getElementById('modal_is_active');
    if (actCb) actCb.checked = (parseInt(tc.is_active) === 1);

    document.getElementById('modal_nomatch_dest_type').value = tc.nomatch_dest_type || 'announcement';
    loadDestinationOptions('modal_nomatch_dest_type', 'modal_nomatch_dest_id', tc.nomatch_dest_id);

    const container = document.getElementById('tc_rules_container');
    if (container) container.innerHTML = '';
    ruleCounter = 0;

    let rules = [];
    if (tc.rules_json) {
        try {
            rules = JSON.parse(tc.rules_json);
        } catch (e) {
            rules = [];
        }
    }

    if (!Array.isArray(rules) || rules.length === 0) {
        // Legacy single rule fallback
        rules = [{
            time_group_id: tc.time_group_id || 1,
            match_dest_type: tc.match_dest_type || 'queue',
            match_dest_id: tc.match_dest_id || '',
            nomatch_dest_type: '',
            nomatch_dest_id: ''
        }];
    }

    rules.forEach(r => {
        addTcRuleRow(r);
    });

    const modal = document.getElementById('tcModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function addTcRuleRow(ruleData = null) {
    const container = document.getElementById('tc_rules_container');
    if (!container) return;

    ruleCounter++;
    const idx = ruleCounter;

    const row = document.createElement('div');
    row.className = 'tc-rule-card';
    row.id = 'rule_row_' + idx;
    row.style.cssText = 'background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 6px; padding: 8px 10px; margin-bottom: 6px;';

    const tgList = window.TIME_GROUPS_LIST || [];
    const modList = window.DEST_MODULES_LIST || [];

    let tgOptionsHtml = '';
    tgList.forEach(tg => {
        const selected = (ruleData && ruleData.time_group_id == tg.id) ? 'selected' : '';
        tgOptionsHtml += `<option value="${tg.id}" ${selected}>${escapeHtml(tg.title)} (${tg.time_start.substring(0,5)}-${tg.time_end.substring(0,5)})</option>`;
    });

    let modOptionsHtml = '';
    modList.forEach(m => {
        modOptionsHtml += `<option value="${escapeHtml(m.key)}">${escapeHtml(m.name)}</option>`;
    });

    const matchTypeVal = ruleData ? (ruleData.match_dest_type || 'queue') : 'queue';
    const matchIdVal = ruleData ? (ruleData.match_dest_id || '') : '';

    row.innerHTML = `
        <div style="display: flex; gap: 10px; align-items: center; width: 100%;">
            <span style="font-weight: 700; font-size: 13px; color: var(--primary); width: 24px;">#${idx}</span>

            <div style="flex: 1.5;">
                <select name="rules[${idx}][time_group_id]" class="form-control" required style="font-size: 13px; padding: 5px 10px; height: 36px;">
                    ${tgOptionsHtml}
                </select>
            </div>

            <div style="flex: 1;">
                <select name="rules[${idx}][match_dest_type]" id="rule_m_type_${idx}" class="form-control" style="font-size: 13px; padding: 5px 10px; height: 36px;">
                    ${modOptionsHtml}
                </select>
            </div>

            <div style="flex: 1.5;">
                <select name="rules[${idx}][match_dest_id]" id="rule_m_id_${idx}" class="form-control" style="font-size: 13px; padding: 5px 10px; height: 36px;">
                    <option value="">Yükleniyor...</option>
                </select>
            </div>

            <button type="button" class="btn btn-danger btn-sm" onclick="removeTcRuleRow(${idx})" title="Kuralı Sil" style="padding: 6px 12px; height: 36px;">
                <i class="fas fa-trash-alt"></i>
            </button>
        </div>
    `;

    container.appendChild(row);

    // Bind Destination Selector for Match Target
    bindDestinationSelector('rule_m_type_' + idx, 'rule_m_id_' + idx);
    document.getElementById('rule_m_type_' + idx).value = matchTypeVal;
    loadDestinationOptions('rule_m_type_' + idx, 'rule_m_id_' + idx, matchIdVal);
}

function removeTcRuleRow(idx) {
    const rows = document.querySelectorAll('.tc-rule-card');
    if (rows.length <= 1) {
        alert('En az bir zaman grubu kuralı olmak zorundadır!');
        return;
    }
    const row = document.getElementById('rule_row_' + idx);
    if (row) row.remove();
}

function closeTcModal() {
    UIHelper.closeOverlayModal('tcModal');
}

/* Time Group Modal Handlers */
function openCreateTgModal() {
    document.getElementById('tgModalTitle').innerHTML = '<i class="fas fa-plus-circle" style="color: var(--primary);"></i> Yeni Zaman Grubu Ekle';
    document.getElementById('modal_tg_id').value = '0';
    document.getElementById('modal_tg_title').value = '';
    document.getElementById('modal_tg_time_start').value = '08:30';
    document.getElementById('modal_tg_time_end').value = '17:30';
    document.getElementById('modal_tg_holidays').value = '';
    const actCb = document.getElementById('modal_tg_is_active');
    if (actCb) actCb.checked = true;

    for (let i = 1; i <= 7; i++) {
        const chk = document.getElementById('day_chk_' + i);
        if (chk) chk.checked = (i <= 5);
    }

    const modal = document.getElementById('tgModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function openEditTgModal(tg) {
    if (!tg) return;

    document.getElementById('tgModalTitle').innerHTML = '<i class="fas fa-edit" style="color: var(--primary);"></i> Zaman Grubu Düzenle #' + tg.id;
    document.getElementById('modal_tg_id').value = tg.id;
    document.getElementById('modal_tg_title').value = tg.title || '';
    document.getElementById('modal_tg_time_start').value = (tg.time_start || '08:30:00').substring(0, 5);
    document.getElementById('modal_tg_time_end').value = (tg.time_end || '17:30:00').substring(0, 5);
    const actCb = document.getElementById('modal_tg_is_active');
    if (actCb) actCb.checked = ((tg.is_active === undefined) || parseInt(tg.is_active) === 1);

    let hStr = '';
    if (tg.holidays_json) {
        try {
            const arr = JSON.parse(tg.holidays_json);
            if (Array.isArray(arr)) hStr = arr.join(', ');
        } catch (e) {
            hStr = tg.holidays_json;
        }
    }
    document.getElementById('modal_tg_holidays').value = hStr;

    const daysArr = (tg.days_of_week || '1,2,3,4,5').split(',');
    for (let i = 1; i <= 7; i++) {
        const chk = document.getElementById('day_chk_' + i);
        if (chk) chk.checked = daysArr.includes(String(i));
    }

    const modal = document.getElementById('tgModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function closeTgModal() {
    UIHelper.closeOverlayModal('tgModal');
}
