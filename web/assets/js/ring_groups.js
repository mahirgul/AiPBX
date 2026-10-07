/* Page script of templates/views/ring_groups/index.php */

let currentRgDestId = '';

function loadRgDestOptions(callback) {
    const type = document.getElementById('modal_rg_dest_type').value;
    const destSelect = document.getElementById('modal_rg_dest_id');
    destSelect.innerHTML = '<option value="">' + __('js.common.loading') + '</option>';

    fetch('/api/destinations.php?module=' + encodeURIComponent(type))
        .then(r => r.json())
        .then(data => {
            destSelect.innerHTML = '';
            if (data.success && data.options && data.options.length > 0) {
                data.options.forEach(opt => {
                    const el = document.createElement('option');
                    el.value = opt.id;
                    el.textContent = opt.name;
                    if (String(opt.id) === String(currentRgDestId)) el.selected = true;
                    destSelect.appendChild(el);
                });
            } else {
                destSelect.innerHTML = '<option value="">' + __('js.common.no_destination') + '</option>';
            }
            if (callback) callback();
        })
        .catch(() => {
            destSelect.innerHTML = '<option value="">' + __('js.common.error_paren') + '</option>';
        });
}

function openCreateRgModal() {
    document.getElementById('rgModalTitle').innerHTML = '<i class="fas fa-plus-circle u-primary"></i> ' + __('js.rg.new_title') + '';
    document.getElementById('modal_rg_id').value = '0';
    document.getElementById('modal_rg_number').value = '';
    document.getElementById('modal_rg_name').value = '';
    document.getElementById('modal_rg_numbers').value = '';
    document.getElementById('modal_rg_strategy').value = 'ringall';
    document.getElementById('modal_rg_timeout').value = '30';
    document.getElementById('modal_rg_cid_prefix').value = '';
    document.getElementById('modal_rg_record').checked = true;
    document.getElementById('modal_rg_active').checked = true;

    document.getElementById('modal_rg_dest_type').value = 'hangup';
    currentRgDestId = 'busy';
    loadRgDestOptions();
    UIHelper.openOverlayModal('rgModal');
}

function openEditRgModal(rg) {
    document.getElementById('rgModalTitle').innerHTML = '<i class="fas fa-edit u-primary"></i> ' + __('js.rg.edit_title') + '' + escapeHtml(rg.name || '');
    document.getElementById('modal_rg_id').value = rg.id || '0';
    document.getElementById('modal_rg_number').value = rg.group_number || '';
    document.getElementById('modal_rg_name').value = rg.name || '';
    document.getElementById('modal_rg_numbers').value = rg.numbers_list || '';
    document.getElementById('modal_rg_strategy').value = rg.ring_strategy || 'ringall';
    document.getElementById('modal_rg_timeout').value = rg.ring_timeout || 30;
    document.getElementById('modal_rg_cid_prefix').value = rg.cid_prefix || '';
    document.getElementById('modal_rg_record').checked = (rg.record_call == 1);
    document.getElementById('modal_rg_active').checked = (rg.is_active == 1);

    document.getElementById('modal_rg_dest_type').value = rg.fallback_dest_type || 'hangup';
    currentRgDestId = rg.fallback_dest_id || 'busy';
    loadRgDestOptions();
    UIHelper.openOverlayModal('rgModal');
}
