/**
 * Fax Units page: an account is a fax user with their numbers.
 */
function fillFaxAccount(item) {
    const set = (id, value) => { document.getElementById(id).value = value; };
    set('modal_did_id', item.id || '0');
    set('modal_department_name', item.department_name || '');
    set('modal_did_extension', (item.numbers || []).join(', '));
    set('modal_notification_email', item.notification_email || '');
    set('modal_username', item.username || '');
    set('modal_password', '');
    set('modal_outbound_group', item.outbound_group || 1);
    set('modal_cid_external', item.cid_external || '');
    set('modal_permission_group_id', String(item.permission_group_id || 0));
    set('modal_role', item.role || 'fax_user');
    document.getElementById('modal_fax_mail').checked = item.fax_mail === undefined || parseInt(item.fax_mail, 10) === 1;
    document.getElementById('modal_is_active').checked = item.is_active === undefined || parseInt(item.is_active, 10) === 1;
    UIHelper.openOverlayModal('didModal');
}

function openCreateFaxDidModal() {
    const title = document.getElementById('didModalTitle');
    if (title) title.innerHTML = '<i class="fas fa-plus-circle" style="color: var(--primary);"></i> ' + __('js.fax_settings.title_new');
    fillFaxAccount({});
}

function openEditFaxDidModal(item) {
    if (!item) return;
    const title = document.getElementById('didModalTitle');
    if (title) title.innerHTML = '<i class="fas fa-edit" style="color: var(--primary);"></i> ' + escapeHtml(item.department_name || '');
    fillFaxAccount(item);
}

function closeFaxDidModal() {
    UIHelper.closeOverlayModal('didModal');
}
