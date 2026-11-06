/* Page script of templates/views/phone_regions/index.php */

function phonesEditRegion(id) {
    const r = (window.PHONE_REGIONS || []).find((x) => Number(x.id) === id) || {};
    document.getElementById('region_id').value = String(id);
    document.getElementById('region_name').value = r.name || '';
    ['sip_server', 'sip_proxy', 'sip_port', 'sip443_sources', 'base_url', 'ntp', 'syslog', 'transport', 'srtp', 'timezone', 'language'].forEach((field) => {
        document.getElementById('region_' + field).value = r[field] || '';
    });
    document.getElementById('region_sip443').checked = Number(r.sip443) === 1;
    const ticked = (r.codecs || '').split(',');
    document.querySelectorAll('.region-codec').forEach((box) => { box.checked = ticked.includes(box.value); });
    UIHelper.openOverlayModal('phoneRegionModal');
}
