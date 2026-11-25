/**
 * Call Modifiers (Set Language / Caller ID Prefix) client logic.
 */
function initModifierPage() {
    if (typeof bindDestinationSelector === 'function') {
        bindDestinationSelector('mod_dest_type', 'mod_dest_id');
    }
}
if (!window._callModifiersListenersBound) {
    window._callModifiersListenersBound = true;
    document.addEventListener('DOMContentLoaded', initModifierPage);
    document.addEventListener('spa:pageLoaded', initModifierPage);
}

function toggleModifierTypeField() {
    const t = document.getElementById('mod_type')?.value || 'language';
    const langGroup = document.getElementById('mod_lang_group');
    const prefixGroup = document.getElementById('mod_prefix_group');
    const prioGroup = document.getElementById('mod_prio_group');
    if (langGroup) langGroup.style.display = (t === 'language') ? '' : 'none';
    if (prefixGroup) prefixGroup.style.display = (t === 'cid_prefix') ? '' : 'none';
    if (prioGroup) prioGroup.style.display = (t === 'queue_priority') ? '' : 'none';
    syncModifierValue();
}

function syncModifierValue() {
    const t = document.getElementById('mod_type')?.value || 'language';
    const valEl = document.getElementById('mod_value');
    if (!valEl) return;
    if (t === 'language') {
        valEl.value = document.getElementById('mod_lang_select')?.value || 'en';
    } else if (t === 'queue_priority') {
        valEl.value = document.getElementById('mod_prio_input')?.value || '0';
    } else {
        valEl.value = document.getElementById('mod_prefix_input')?.value || '';
    }
}

function openCreateModifierModal() {
    const titleEl = document.getElementById('modifierModalTitle');
    if (titleEl) {
        titleEl.innerHTML = '<i class="fas fa-tags u-primary"></i> ' + escapeHtml(__('js.call_modifiers.new_title'));
    }
    document.getElementById('mod_id').value = '0';
    document.getElementById('mod_title').value = '';
    document.getElementById('mod_type').value = 'language';
    const langSel = document.getElementById('mod_lang_select');
    if (langSel && langSel.options.length > 0) langSel.selectedIndex = 0;
    document.getElementById('mod_prefix_input').value = '';
    document.getElementById('mod_is_active').checked = true;
    const dt = document.getElementById('mod_dest_type');
    if (dt) {
        dt.value = 'queue';
        if (typeof loadDestinationOptions === 'function') {
            loadDestinationOptions('mod_dest_type', 'mod_dest_id', '');
        }
    }
    toggleModifierTypeField();
    UIHelper.openOverlayModal('modifierModal');
}

function openEditModifierModal(m) {
    if (!m) return;
    const titleEl = document.getElementById('modifierModalTitle');
    if (titleEl) {
        titleEl.innerHTML = '<i class="fas fa-edit u-primary"></i> ' + escapeHtml(__('js.call_modifiers.edit_title') + (m.title || ''));
    }
    document.getElementById('mod_id').value = m.id || '0';
    document.getElementById('mod_title').value = m.title || '';
    document.getElementById('mod_type').value = m.mod_type || 'language';
    if (m.mod_type === 'cid_prefix') {
        document.getElementById('mod_prefix_input').value = m.mod_value || '';
    } else if (m.mod_type === 'queue_priority') {
        document.getElementById('mod_prio_input').value = m.mod_value || '0';
    } else {
        const langSel = document.getElementById('mod_lang_select');
        if (langSel) langSel.value = m.mod_value || 'en';
    }
    document.getElementById('mod_is_active').checked = (parseInt(m.is_active) === 1);
    const dt = document.getElementById('mod_dest_type');
    if (dt) {
        dt.value = m.dest_type || 'hangup';
        if (typeof loadDestinationOptions === 'function') {
            loadDestinationOptions('mod_dest_type', 'mod_dest_id', m.dest_id || '');
        }
    }
    toggleModifierTypeField();
    UIHelper.openOverlayModal('modifierModal');
}

function closeModifierModal() {
    UIHelper.closeOverlayModal('modifierModal');
}
