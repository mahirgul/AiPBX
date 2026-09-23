/**
 * Multi-Queue Management Client Module
 */
function openCreateQueueModal() {
    const title = document.getElementById('queueModalTitle');
    if (title) title.innerHTML = '<i class="fas fa-plus-circle" style="color: var(--primary);"></i> Yeni Çağrı Merkezi Kuyruğu Tanımla';

    const idEl = document.getElementById('modal_queue_id');
    const nameEl = document.getElementById('modal_queue_name');
    const titleEl = document.getElementById('modal_queue_title');
    const stratEl = document.getElementById('modal_strategy');
    const timeoutEl = document.getElementById('modal_timeout');
    const retryEl = document.getElementById('modal_retry');
    const wrapEl = document.getElementById('modal_wrapuptime');
    const mohEl = document.getElementById('modal_musicclass');
    const maxlenEl = document.getElementById('modal_maxlen');
    const annFreqEl = document.getElementById('modal_announce_frequency');
    const annHoldEl = document.getElementById('modal_announce_holdtime');
    const joinEl = document.getElementById('modal_joinempty');
    const leaveEl = document.getElementById('modal_leavewhenempty');
    const ringEl = document.getElementById('modal_ringinuse');
    const supEl = document.getElementById('modal_supervisor_extension');
    const maxWaitEl = document.getElementById('modal_max_wait_seconds');
    const fbActionEl = document.getElementById('modal_fallback_action');
    const fbTargetEl = document.getElementById('modal_fallback_target');
    const recEnabledEl = document.getElementById('modal_record_enabled');
    const langEl = document.getElementById('modal_language');

    if (idEl) idEl.value = '';
    if (nameEl) { nameEl.value = ''; nameEl.readOnly = false; }
    if (titleEl) titleEl.value = '';
    var inEl = document.getElementById('modal_internal_number');
    if (inEl) inEl.value = '';

    if (stratEl) stratEl.value = 'rrmemory';
    if (timeoutEl) timeoutEl.value = 15;
    if (retryEl) retryEl.value = 5;
    if (wrapEl) wrapEl.value = 10;
    if (mohEl) mohEl.value = 'default';
    if (maxlenEl) maxlenEl.value = 0;
    if (langEl) langEl.value = '';
    if (annFreqEl) annFreqEl.value = 30;
    if (annHoldEl) annHoldEl.value = 'yes';
    if (joinEl) joinEl.value = 'yes';
    if (leaveEl) leaveEl.value = 'no';
    if (ringEl) ringEl.value = 'no';
    if (supEl) supEl.value = '';
    if (maxWaitEl) maxWaitEl.value = 300;
    if (fbActionEl) fbActionEl.value = 'hangup';
    if (fbTargetEl) fbTargetEl.value = '';
    if (recEnabledEl) recEnabledEl.checked = true;
    toggleQueueFallbackTarget();
    const actCb = document.getElementById('modal_is_active');
    if (actCb) actCb.checked = true;

    // Uncheck all agent & supervisor checkboxes
    document.querySelectorAll('.modal-agent-mode').forEach(sel => sel.value = '');
    document.querySelectorAll('.modal-supervisor-checkbox').forEach(cb => cb.checked = false);

    const firstTabBtn = document.querySelector('.queue-tab-btn[data-tab="basic"]');
    switchQueueTab('basic', firstTabBtn);

    const modal = document.getElementById('queueModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function openEditQueueModal(item) {
    if (!item) return;

    const title = document.getElementById('queueModalTitle');
    if (title) title.innerHTML = '<i class="fas fa-edit" style="color: var(--primary);"></i> Kuyruk Düzenle: ' + escapeHtml(item.title || item.queue_name || '');

    const idEl = document.getElementById('modal_queue_id');
    const nameEl = document.getElementById('modal_queue_name');
    const titleEl = document.getElementById('modal_queue_title');
    const stratEl = document.getElementById('modal_strategy');
    const timeoutEl = document.getElementById('modal_timeout');
    const retryEl = document.getElementById('modal_retry');
    const wrapEl = document.getElementById('modal_wrapuptime');
    const mohEl = document.getElementById('modal_musicclass');
    const maxlenEl = document.getElementById('modal_maxlen');
    const annFreqEl = document.getElementById('modal_announce_frequency');
    const annHoldEl = document.getElementById('modal_announce_holdtime');
    const joinEl = document.getElementById('modal_joinempty');
    const leaveEl = document.getElementById('modal_leavewhenempty');
    const ringEl = document.getElementById('modal_ringinuse');
    const supEl = document.getElementById('modal_supervisor_extension');
    const maxWaitEl = document.getElementById('modal_max_wait_seconds');
    const fbActionEl = document.getElementById('modal_fallback_action');
    const fbTargetEl = document.getElementById('modal_fallback_target');
    const recEnabledEl = document.getElementById('modal_record_enabled');
    const langEl = document.getElementById('modal_language');
    const actCb = document.getElementById('modal_is_active');
    if (actCb) actCb.checked = (parseInt(item.is_active) === 1);

    if (idEl) idEl.value = item.id || '';
    if (nameEl) { nameEl.value = item.queue_name || ''; nameEl.readOnly = true; }
    if (titleEl) titleEl.value = item.title || '';
    var inEl = document.getElementById('modal_internal_number');
    if (inEl) inEl.value = (item.internal_number || '');
    if (stratEl) stratEl.value = item.strategy || 'rrmemory';
    if (timeoutEl) timeoutEl.value = item.timeout || 15;
    if (retryEl) retryEl.value = item.retry || 5;
    if (wrapEl) wrapEl.value = item.wrapuptime || 10;
    if (mohEl) mohEl.value = item.musicclass || 'default';
    if (maxlenEl) maxlenEl.value = (item.maxlen !== undefined) ? item.maxlen : 0;
    if (langEl) langEl.value = item.language || '';
    if (annFreqEl) annFreqEl.value = (item.announce_frequency !== undefined) ? item.announce_frequency : 30;
    if (annHoldEl) annHoldEl.value = item.announce_holdtime || 'yes';
    if (joinEl) joinEl.value = item.joinempty || 'yes';
    if (leaveEl) leaveEl.value = item.leavewhenempty || 'no';
    if (ringEl) ringEl.value = item.ringinuse || 'no';
    if (supEl) supEl.value = item.supervisor_extension || '';
    if (maxWaitEl) maxWaitEl.value = (item.max_wait_seconds !== undefined && item.max_wait_seconds !== null) ? item.max_wait_seconds : 300;
    if (fbActionEl) fbActionEl.value = item.fallback_action || 'hangup';
    if (fbTargetEl) fbTargetEl.value = item.fallback_target || '';
    if (recEnabledEl) recEnabledEl.checked = (item.record_enabled === undefined || item.record_enabled === null) ? true : (parseInt(item.record_enabled) === 1);
    toggleQueueFallbackTarget();

    // Check assigned agent checkboxes
    let members = [];
    try {
        members = JSON.parse(item.members_json || '[]');
    } catch(e) { members = []; }

    let staticMembers = [];
    try {
        staticMembers = JSON.parse(item.static_members_json || '[]');
    } catch(e) { staticMembers = []; }
    members = members.map(String);
    staticMembers = staticMembers.map(String);

    document.querySelectorAll('.modal-agent-mode').forEach(sel => {
        const ext = sel.dataset.ext;
        sel.value = !members.includes(ext) ? '' : (staticMembers.includes(ext) ? 'static' : 'dynamic');
    });

    // Check assigned supervisor checkboxes
    let supervisors = [];
    try {
        supervisors = JSON.parse(item.supervisors_json || '[]');
    } catch(e) { supervisors = []; }
    if (supervisors.length === 0 && item.supervisor_extension) {
        supervisors = [item.supervisor_extension];
    }

    document.querySelectorAll('.modal-supervisor-checkbox').forEach(cb => {
        cb.checked = supervisors.map(String).includes(cb.value);
    });

    const firstTabBtn = document.querySelector('.queue-tab-btn[data-tab="basic"]');
    switchQueueTab('basic', firstTabBtn);

    const modal = document.getElementById('queueModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function switchQueueTab(tabKey, btn) {
    const panes = document.querySelectorAll('.queue-tab-pane');
    panes.forEach(pane => {
        pane.style.display = 'none';
        pane.classList.remove('active');
    });

    const target = document.getElementById('queue_tab_' + tabKey);
    if (target) {
        target.style.display = 'block';
        target.classList.add('active');
    }

    const buttons = document.querySelectorAll('.queue-tab-btn');
    buttons.forEach(b => {
        b.classList.remove('active');
    });

    if (btn) {
        btn.classList.add('active');
    }
}

function toggleQueueFallbackTarget() {
    const actionEl = document.getElementById('modal_fallback_action');
    const group = document.getElementById('modal_fallback_target_group');
    if (!actionEl || !group) return;
    group.style.display = (actionEl.value === 'forward') ? 'block' : 'none';
}

function closeQueueModal() {
    UIHelper.closeOverlayModal('queueModal');
}

