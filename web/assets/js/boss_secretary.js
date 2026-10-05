/* Page script of templates/views/boss_secretary/index.php */

let currentBsDestId = '';

function loadBsDestOptions(callback) {
    const type = document.getElementById('modal_bs_dest_type').value;
    const destSelect = document.getElementById('modal_bs_dest_id');
    destSelect.innerHTML = '<option value="">Yükleniyor...</option>';

    fetch('/api/destinations.php?module=' + encodeURIComponent(type))
        .then(r => r.json())
        .then(data => {
            destSelect.innerHTML = '';
            if (data.success && data.options && data.options.length > 0) {
                data.options.forEach(opt => {
                    const el = document.createElement('option');
                    el.value = opt.id;
                    el.textContent = opt.name;
                    if (String(opt.id) === String(currentBsDestId)) el.selected = true;
                    destSelect.appendChild(el);
                });
            } else {
                destSelect.innerHTML = '<option value="">(Hedef bulunamadı)</option>';
            }
            if (callback) callback();
        })
        .catch(() => {
            destSelect.innerHTML = '<option value="">(Hata)</option>';
        });
}

function openCreateBsModal() {
    document.getElementById('bsModalTitle').innerHTML = '<i class="fas fa-plus-circle u-primary"></i> Yeni Şef - Sekreter Grubu';
    document.getElementById('modal_bs_id').value = '0';
    document.getElementById('modal_bs_group_number').value = String(window.BOSS_SECRETARY_NEXT_NUMBER);
    document.getElementById('modal_bs_group_name').value = '';
    document.getElementById('modal_bs_boss').value = '';
    document.querySelectorAll('.bs-secretary-chk').forEach(c => c.checked = false);
    document.getElementById('modal_bs_strategy').value = 'ringall';
    document.getElementById('modal_bs_timeout').value = '20';
    document.getElementById('modal_bs_whitelist').value = '';
    document.getElementById('modal_bs_active').checked = true;
    document.getElementById('modal_bs_dest_type').value = 'hangup';
    currentBsDestId = 'busy';
    loadBsDestOptions();
    UIHelper.openOverlayModal('bsModal');
}

function openEditBsModal(g) {
    document.getElementById('bsModalTitle').innerHTML = '<i class="fas fa-edit u-primary"></i> Grup Düzenle: ' + escapeHtml(g.group_name || '');
    document.getElementById('modal_bs_id').value = g.id || '0';
    document.getElementById('modal_bs_group_number').value = g.group_number || 1;
    document.getElementById('modal_bs_group_name').value = g.group_name || '';
    document.getElementById('modal_bs_boss').value = g.boss_extension || '';

    let secs = [];
    try {
        secs = JSON.parse(g.secretaries_json || '[]');
    } catch(e) {}
    document.querySelectorAll('.bs-secretary-chk').forEach(c => {
        c.checked = secs.includes(String(c.value));
    });

    document.getElementById('modal_bs_strategy').value = g.ring_strategy || 'ringall';
    document.getElementById('modal_bs_timeout').value = g.ring_timeout || 20;
    document.getElementById('modal_bs_whitelist').value = g.whitelist_extensions || '';
    document.getElementById('modal_bs_active').checked = (g.is_active == 1);

    document.getElementById('modal_bs_dest_type').value = g.fallback_dest_type || 'hangup';
    currentBsDestId = g.fallback_dest_id || 'busy';
    loadBsDestOptions();

    UIHelper.openOverlayModal('bsModal');
}
