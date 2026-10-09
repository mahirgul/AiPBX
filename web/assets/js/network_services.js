/* Page script of templates/views/network_services/index.php */

// Shows the fields of the selected mode: DHCP settings for "dhcp", the TFTP
// networks note for "tftp" and "dhcp", the interface for "dhcp" only.
(function () {
    const form = document.getElementById('netsvcForm');
    if (!form) return;
    const show = (selector, on) => form.querySelectorAll(selector).forEach(el => { el.style.display = on ? '' : 'none'; });
    const update = () => {
        const checked = form.querySelector('input.netsvc-mode:checked');
        const mode = checked ? checked.value : 'off';
        show('.netsvc-dhcp', mode === 'dhcp');
        show('.netsvc-needs-iface', mode === 'dhcp');
        show('.netsvc-tftp', mode !== 'off');
    };
    form.querySelectorAll('input.netsvc-mode').forEach(r => r.addEventListener('change', update));
    update();
})();
