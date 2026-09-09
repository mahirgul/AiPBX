/**
 * Outbound Routes Management Client Script
 */
function addRouteTrunkRow(selectedValue, cidValue) {
    const tpl = document.getElementById('route_trunk_row_template');
    const list = document.getElementById('route_trunks_list');
    if (!tpl || !list) return;

    const row = tpl.content.cloneNode(true);
    if (selectedValue) {
        const sel = row.querySelector('.route-trunk-select');
        if (sel) sel.value = selectedValue;
    }
    if (cidValue) {
        const cid = row.querySelector('.route-trunk-cid');
        if (cid) cid.value = cidValue;
    }
    list.appendChild(row);
}

function clearRouteTrunkRows() {
    const list = document.getElementById('route_trunks_list');
    if (list) list.innerHTML = '';
}

function openCreateRouteModal() {
    const title = document.getElementById('routeModalTitle');
    if (title) title.innerHTML = '<i class="fas fa-plus-circle" style="color: var(--primary);"></i> Yeni Giden Rota Ekle';

    document.getElementById('modal_route_id').value = '';
    document.getElementById('modal_route_name').value = '';
    document.getElementById('modal_match_pattern').value = '';
    document.getElementById('modal_prepend').value = '';
    document.getElementById('modal_append').value = '';
    document.getElementById('modal_strip_front').value = 0;
    document.getElementById('modal_strip_back').value = 0;
    clearRouteTrunkRows();
    addRouteTrunkRow();
    const intCb = document.getElementById('modal_is_internal');
    if (intCb) intCb.checked = false;
    const actCb = document.getElementById('modal_is_active');
    if (actCb) actCb.checked = true;

    const modal = document.getElementById('routeModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function openEditRouteModal(item) {
    if (!item) return;

    const title = document.getElementById('routeModalTitle');
    if (title) title.innerHTML = '<i class="fas fa-edit" style="color: var(--primary);"></i> Giden Rota Düzenle: ' + escapeHtml(item.route_name || item.match_pattern || '');

    document.getElementById('modal_route_id').value = item.id || '';
    document.getElementById('modal_route_name').value = item.route_name || '';
    document.getElementById('modal_match_pattern').value = item.match_pattern || '';
    document.getElementById('modal_prepend').value = item.prepend || '';
    document.getElementById('modal_append').value = item.append || '';
    document.getElementById('modal_strip_front').value = item.strip_front || 0;
    document.getElementById('modal_strip_back').value = item.strip_back || 0;
    const intCb = document.getElementById('modal_is_internal');
    if (intCb) intCb.checked = (parseInt(item.is_internal) === 1);
    const actCb = document.getElementById('modal_is_active');
    if (actCb) actCb.checked = (parseInt(item.is_active) === 1);

    clearRouteTrunkRows();
    if (item.trunks_json) {
        try {
            const trunks = JSON.parse(item.trunks_json);
            if (Array.isArray(trunks) && trunks.length > 0) {
                trunks.forEach(t => addRouteTrunkRow(t.trunk_name, t.callerid_override));
            } else {
                addRouteTrunkRow();
            }
        } catch (e) {
            console.error('trunks_json parse error:', e);
            addRouteTrunkRow();
        }
    } else {
        addRouteTrunkRow();
    }

    const modal = document.getElementById('routeModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
    }
}

function closeRouteModal() {
    UIHelper.closeOverlayModal('routeModal');
}
