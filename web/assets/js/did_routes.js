/**
 * DID Inbound Routes Client Module
 */
// Bind destination selector immediately
bindDestinationSelector('modal_dest_type', 'modal_dest_id');

function openCreateDidModal() {
    bindDestinationSelector('modal_dest_type', 'modal_dest_id');
    document.getElementById('didModalTitle').innerHTML = '<i class="fas fa-plus-circle" style="color: var(--primary);"></i> Yeni Gelen Rota Ekle';
    document.getElementById('modal_route_id').value = '0';
    document.getElementById('modal_did_number').value = '';
    document.getElementById('modal_title').value = '';
    document.getElementById('modal_dest_type').value = 'time_condition';
    const recCb = document.getElementById('modal_record_call');
    if (recCb) recCb.checked = false;
    const actCb = document.getElementById('modal_is_active');
    if (actCb) actCb.checked = true;
    const langSel = document.getElementById('modal_language');
    if (langSel) langSel.value = '';

    loadDestinationOptions('modal_dest_type', 'modal_dest_id', '');

    UIHelper.openOverlayModal('didModal');
}

function openEditDidModal(route) {
    if (!route) return;

    bindDestinationSelector('modal_dest_type', 'modal_dest_id');
    document.getElementById('didModalTitle').innerHTML = '<i class="fas fa-edit" style="color: var(--primary);"></i> Gelen Rota Düzenle #' + route.id;
    document.getElementById('modal_route_id').value = route.id;
    document.getElementById('modal_did_number').value = route.did_number || '';
    document.getElementById('modal_title').value = route.title || '';
    document.getElementById('modal_dest_type').value = route.dest_type || 'time_condition';
    const recCb = document.getElementById('modal_record_call');
    if (recCb) recCb.checked = (parseInt(route.record_call) === 1);
    const actCb = document.getElementById('modal_is_active');
    if (actCb) actCb.checked = (parseInt(route.is_active) === 1);
    const langSel = document.getElementById('modal_language');
    if (langSel) langSel.value = route.language || '';

    loadDestinationOptions('modal_dest_type', 'modal_dest_id', route.dest_id);

    UIHelper.openOverlayModal('didModal');
}

function closeDidModal() {
    UIHelper.closeOverlayModal('didModal');
}
