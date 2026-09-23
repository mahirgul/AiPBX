/**
 * SIP Trunk Management Client Script
 */

function switchTrunkTab(tabKey, btn) {
    const panes = document.querySelectorAll('.trunk-tab-pane');
    panes.forEach(pane => {
        pane.style.display = 'none';
    });

    const target = document.getElementById('trunk_tab_' + tabKey);
    if (target) {
        target.style.display = 'block';
    }

    const buttons = document.querySelectorAll('.trunk-tab-btn');
    buttons.forEach(b => {
        b.classList.remove('btn-primary');
        b.classList.add('btn-secondary');
    });

    if (btn) {
        btn.classList.remove('btn-secondary');
        btn.classList.add('btn-primary');
    }
}

function openCreateTrunkModal() {
    const title = document.getElementById('trunkModalTitle');
    if (title) title.innerHTML = '<i class="fas fa-plus-circle" style="color: var(--primary);"></i> Yeni SIP Dış Hat Tanımla';

    document.getElementById('modal_trunk_id').value = '';
    const nameEl = document.getElementById('modal_trunk_name');
    nameEl.value = '';
    nameEl.readOnly = false;

    // Tab 1: Basic
    document.getElementById('modal_title').value = '';
    document.getElementById('modal_ip_address').value = '';
    document.getElementById('modal_port').value = 5060;
    document.getElementById('modal_transport').value = 'udp';
    document.getElementById('modal_codecs').value = 'alaw,ulaw';
    document.getElementById('modal_outbound_proxy').value = '';
    document.getElementById('modal_match_hosts').value = '';
    const actEl = document.getElementById('modal_is_active');
    if (actEl) actEl.checked = true;

    // Tab 2: Auth & Registration
    const regEl = document.getElementById('modal_registration_enabled');
    if (regEl) regEl.checked = false;
    document.getElementById('modal_connection_mode').value = 'ip';
    document.getElementById('modal_auth_username').value = '';
    document.getElementById('modal_auth_password').value = '';
    document.getElementById('modal_registration_expiration').value = 3600;
    document.getElementById('modal_registration_retry_interval').value = 60;
    document.getElementById('modal_max_contacts').value = 1;

    // Tab 3: Caller ID & Headers
    document.getElementById('modal_outbound_caller_id').value = '';
    document.getElementById('modal_from_user').value = '';
    document.getElementById('modal_from_domain').value = '';
    const sendNameEl = document.getElementById('modal_send_caller_name');
    if (sendNameEl) sendNameEl.checked = false;
    const sendPaiEl = document.getElementById('modal_send_pai');
    if (sendPaiEl) sendPaiEl.checked = false;
    const sendRpidEl = document.getElementById('modal_send_rpid');
    if (sendRpidEl) sendRpidEl.checked = false;

    // Tab 4: Signaling & Advanced
    document.getElementById('modal_dtmf_mode').value = 'rfc4733';
    document.getElementById('modal_t38_support').value = 1;
    document.getElementById('modal_t38_udptl_ec').value = 'redundancy';
    document.getElementById('modal_t38_udptl_nat').value = 'yes';
    document.getElementById('modal_t38_udptl_maxdatagram').value = 400;
    document.getElementById('modal_fax_detect').value = 1;
    document.getElementById('modal_fax_detect_timeout').value = 30;
    document.getElementById('modal_qualify_frequency').value = 60;
    document.getElementById('modal_direct_media').value = 'no';
    document.getElementById('modal_rtp_symmetric').value = 'yes';
    document.getElementById('modal_rewrite_contact').value = 'yes';
    document.getElementById('modal_force_rport').value = 'yes';
    document.getElementById('modal_timers').value = 'yes';
    document.getElementById('modal_context').value = 'from-trunk-inbound';
    document.getElementById('modal_max_channels').value = 0;
    document.getElementById('modal_custom_pjsip_params').value = '';
    document.getElementById('modal_did_trim_digits').value = 0;
    const allowOutEl = document.getElementById('modal_allow_outbound_routing');
    if (allowOutEl) allowOutEl.checked = false;
    document.getElementById('modal_outbound_route_group').value = 1;

    const firstTabBtn = document.querySelector('.trunk-tab-btn[data-tab="basic"]');
    switchTrunkTab('basic', firstTabBtn);

    const modal = document.getElementById('trunkModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function openEditTrunkModal(item) {
    if (!item) return;

    const title = document.getElementById('trunkModalTitle');
    if (title) title.innerHTML = '<i class="fas fa-edit" style="color: var(--primary);"></i> Dış Hat Düzenle: ' + escapeHtml(item.title || item.trunk_name || '');

    document.getElementById('modal_trunk_id').value = item.id || '';
    const nameEl = document.getElementById('modal_trunk_name');
    nameEl.value = item.trunk_name || '';
    nameEl.readOnly = true;

    // Tab 1: Basic
    document.getElementById('modal_title').value = item.title || '';
    document.getElementById('modal_ip_address').value = item.ip_address || '';
    document.getElementById('modal_port').value = item.port || 5060;
    document.getElementById('modal_transport').value = item.transport || 'udp';
    document.getElementById('modal_codecs').value = item.codecs || 'alaw,ulaw';
    document.getElementById('modal_outbound_proxy').value = item.outbound_proxy || '';
    document.getElementById('modal_match_hosts').value = item.match_hosts || '';
    const actEl = document.getElementById('modal_is_active');
    if (actEl) actEl.checked = (item.is_active == 1);

    // Tab 2: Auth & Registration
    const regEl = document.getElementById('modal_registration_enabled');
    if (regEl) regEl.checked = (item.registration_enabled == 1);
    document.getElementById('modal_connection_mode').value = item.connection_mode || 'ip';
    document.getElementById('modal_auth_username').value = item.auth_username || '';
    document.getElementById('modal_auth_password').value = item.auth_password || '';
    document.getElementById('modal_registration_expiration').value = item.registration_expiration || 3600;
    document.getElementById('modal_registration_retry_interval').value = item.registration_retry_interval || 60;
    document.getElementById('modal_max_contacts').value = item.max_contacts || 1;

    // Tab 3: Caller ID & Headers
    document.getElementById('modal_outbound_caller_id').value = item.outbound_caller_id || '';
    document.getElementById('modal_from_user').value = item.from_user || '';
    document.getElementById('modal_from_domain').value = item.from_domain || '';
    const sendNameEl = document.getElementById('modal_send_caller_name');
    if (sendNameEl) sendNameEl.checked = (item.send_caller_name == 1);
    const sendPaiEl = document.getElementById('modal_send_pai');
    if (sendPaiEl) sendPaiEl.checked = (item.send_pai == 1);
    const sendRpidEl = document.getElementById('modal_send_rpid');
    if (sendRpidEl) sendRpidEl.checked = (item.send_rpid == 1);

    // Tab 4: Signaling & Advanced
    document.getElementById('modal_dtmf_mode').value = item.dtmf_mode || 'rfc4733';
    document.getElementById('modal_t38_support').value = item.t38_support !== undefined ? item.t38_support : 1;
    document.getElementById('modal_t38_udptl_ec').value = item.t38_udptl_ec || 'redundancy';
    document.getElementById('modal_t38_udptl_nat').value = item.t38_udptl_nat || 'yes';
    document.getElementById('modal_t38_udptl_maxdatagram').value = item.t38_udptl_maxdatagram || 400;
    document.getElementById('modal_fax_detect').value = item.fax_detect !== undefined ? item.fax_detect : 1;
    document.getElementById('modal_fax_detect_timeout').value = item.fax_detect_timeout || 30;
    document.getElementById('modal_qualify_frequency').value = item.qualify_frequency || 60;
    document.getElementById('modal_direct_media').value = item.direct_media || 'no';
    document.getElementById('modal_rtp_symmetric').value = item.rtp_symmetric || 'yes';
    document.getElementById('modal_rewrite_contact').value = item.rewrite_contact || 'yes';
    document.getElementById('modal_force_rport').value = item.force_rport || 'yes';
    document.getElementById('modal_timers').value = item.timers || 'yes';
    document.getElementById('modal_context').value = item.context || 'from-trunk-inbound';
    document.getElementById('modal_max_channels').value = item.max_channels || 0;
    document.getElementById('modal_custom_pjsip_params').value = item.custom_pjsip_params || '';
    document.getElementById('modal_did_trim_digits').value = item.did_trim_digits !== undefined ? item.did_trim_digits : 0;
    const allowOutEl = document.getElementById('modal_allow_outbound_routing');
    if (allowOutEl) allowOutEl.checked = (item.allow_outbound_routing == 1);
    document.getElementById('modal_outbound_route_group').value = item.outbound_route_group || 1;

    const firstTabBtn = document.querySelector('.trunk-tab-btn[data-tab="basic"]');
    switchTrunkTab('basic', firstTabBtn);

    const modal = document.getElementById('trunkModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function closeTrunkModal() {
    UIHelper.closeOverlayModal('trunkModal');
}
