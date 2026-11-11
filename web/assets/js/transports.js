/* Page script of templates/views/transports/index.php */

function transportEditBuiltin(b) {
    document.getElementById('builtinTitle').textContent = b.name;
    document.getElementById('builtin_key').value = b.key;
    document.getElementById('builtin_addr').value = b.bind_addr;
    document.getElementById('builtin_port').value = b.port;
    document.getElementById('builtin_enabled').checked = b.enabled;
    // UDP stays on: trunks and phones are written for it.
    document.getElementById('builtin_enabled_label').style.display = b.key === 'udp' ? 'none' : '';
    UIHelper.openOverlayModal('transportBuiltinModal');
}

function updateCustomProtocol() {
    const proto = document.getElementById('custom_protocol').value;
    const verifyGroup = document.getElementById('custom_verify_group');
    if (verifyGroup) {
        verifyGroup.style.display = (proto === 'tls') ? '' : 'none';
    }
}

function transportEditCustom(id) {
    const c = (window.TRANSPORTS_CUSTOM || []).find((x) => Number(x.id) === id) || {};
    document.getElementById('custom_id').value = String(id);
    document.getElementById('custom_name').value = c.name || '';
    document.getElementById('custom_protocol').value = c.protocol || 'udp';
    document.getElementById('custom_addr').value = c.bind_addr || '0.0.0.0';
    document.getElementById('custom_port').value = c.port || '';
    document.getElementById('custom_external').value = c.external_address || '';
    document.getElementById('custom_active').checked = id === 0 || Number(c.is_active) === 1;
    const verifyBox = document.getElementById('custom_verify_server');
    if (verifyBox) {
        verifyBox.checked = Number(c.verify_server || 0) === 1;
    }
    updateCustomProtocol();
    UIHelper.openOverlayModal('transportCustomModal');
}

document.addEventListener('DOMContentLoaded', () => {
    const protoSel = document.getElementById('custom_protocol');
    if (protoSel) {
        protoSel.addEventListener('change', updateCustomProtocol);
    }
});
