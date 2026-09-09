/**
 * IVR Management Client Module
 */
function bindIvrDestinationSelectors() {
    bindDestinationSelector('modal_timeout_dest_type', 'modal_timeout_dest_id');
    bindDestinationSelector('modal_invalid_dest_type', 'modal_invalid_dest_id');
}
bindIvrDestinationSelectors();

function openCreateIvrModal() {
    bindIvrDestinationSelectors();
    document.getElementById('ivrModalTitle').innerHTML = '<i class="fas fa-microphone-alt" style="color: var(--primary);"></i> Yeni IVR Karşılama Menüsü Ekle';
    document.getElementById('modal_ivr_id').value = '0';
    document.getElementById('modal_title').value = '';
    var inEl = document.getElementById('modal_internal_number');
    if (inEl) inEl.value = '';

    document.getElementById('modal_prompt_file').value = 'custom/welcome';
    document.getElementById('modal_timeout_seconds').value = '10';
    document.getElementById('modal_timeout_dest_type').value = 'queue';
    document.getElementById('modal_invalid_dest_type').value = 'hangup';
    document.getElementById('modal_max_failures').value = '3';
    const actCb = document.getElementById('modal_is_active');
    if (actCb) actCb.checked = true;
    const directCb = document.getElementById('modal_allow_direct_dial');
    if (directCb) directCb.checked = true;
    const langSel = document.getElementById('modal_language');
    if (langSel) langSel.value = '';

    loadDestinationOptions('modal_timeout_dest_type', 'modal_timeout_dest_id', '');
    loadDestinationOptions('modal_invalid_dest_type', 'modal_invalid_dest_id', '');

    const modal = document.getElementById('ivrModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function openEditIvrModal(ivr) {
    if (!ivr) return;

    document.getElementById('ivrModalTitle').innerHTML = '<i class="fas fa-edit" style="color: var(--primary);"></i> IVR Menüsü Düzenle #' + ivr.id;
    document.getElementById('modal_ivr_id').value = ivr.id;
    document.getElementById('modal_title').value = ivr.title || '';
    var inEl = document.getElementById('modal_internal_number');
    if (inEl) inEl.value = (ivr.internal_number || '');

    document.getElementById('modal_prompt_file').value = ivr.prompt_file || 'custom/welcome';
    document.getElementById('modal_timeout_seconds').value = ivr.timeout_seconds || '10';
    document.getElementById('modal_timeout_dest_type').value = ivr.timeout_dest_type || 'queue';
    document.getElementById('modal_invalid_dest_type').value = ivr.invalid_dest_type || 'hangup';
    document.getElementById('modal_max_failures').value = ivr.max_failures || '3';
    const actCb = document.getElementById('modal_is_active');
    if (actCb) actCb.checked = (parseInt(ivr.is_active) === 1);
    const directCb = document.getElementById('modal_allow_direct_dial');
    if (directCb) directCb.checked = (parseInt(ivr.allow_direct_dial) === 1);
    const langSel = document.getElementById('modal_language');
    if (langSel) langSel.value = ivr.language || '';

    loadDestinationOptions('modal_timeout_dest_type', 'modal_timeout_dest_id', ivr.timeout_dest_id);
    loadDestinationOptions('modal_invalid_dest_type', 'modal_invalid_dest_id', ivr.invalid_dest_id);

    const modal = document.getElementById('ivrModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function closeIvrModal() {
    UIHelper.closeOverlayModal('ivrModal');
}

function openIvrEntriesModal(ivrId, title) {
    document.getElementById('entries_ivr_id').value = ivrId;
    document.getElementById('entries_title_label').innerText = title;

    // Mevcut tuşlama listesi: her IVR için sunucuda basılmış gizli bloklardan
    // yalnızca ilgili olanı göster. Modal önceden SADECE ekleme formuydu; hangi
    // tuşun dolu olduğu görünmüyor ve bir eşlemeyi silmenin hiçbir yolu yoktu
    // (delete_ivr_entry backend'i yazılmış ama arayüzde hiç çağrılmıyordu).
    document.querySelectorAll('.ivr-entries-list').forEach(function (el) {
        el.style.display = 'none';
    });
    const list = document.getElementById('entries_list_' + ivrId);
    if (list) { list.style.display = 'block'; }

    // Dolu bir tuş seçilirse üzerine yazılacağını önceden söyle — kayıt
    // INSERT ... ON DUPLICATE KEY UPDATE ile sessizce eziyordu.
    const digitSel = document.querySelector('#ivrEntriesModal select[name="digit"]');
    const warn = document.getElementById('entry_overwrite_warning');
    if (digitSel && warn) {
        const used = (list && list.dataset.digits ? list.dataset.digits.split(',') : []).filter(Boolean);
        const kontrolEt = function () {
            warn.style.display = used.indexOf(digitSel.value) !== -1 ? 'block' : 'none';
        };
        digitSel.onchange = kontrolEt;
        kontrolEt();
    }

    bindDestinationSelector('modal_entry_dest_type', 'modal_entry_dest_id');
    loadDestinationOptions('modal_entry_dest_type', 'modal_entry_dest_id', '');

    const modal = document.getElementById('ivrEntriesModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function closeIvrEntriesModal() {
    UIHelper.closeOverlayModal('ivrEntriesModal');
}
