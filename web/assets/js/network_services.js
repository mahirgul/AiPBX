/* Page script of templates/views/network_services/index.php */

// Shows the fields of the switched-on services: the interface and DHCP
// settings with DHCP, the TFTP networks note with TFTP.
(function () {
    const form = document.getElementById('netsvcForm');
    if (!form) return;
    const show = (selector, on) => form.querySelectorAll(selector).forEach(el => { el.style.display = on ? '' : 'none'; });
    const dhcp = document.getElementById('netsvc_dhcp_on');
    const tftp = document.getElementById('netsvc_tftp_on');
    const update = () => {
        show('.netsvc-dhcp', dhcp.checked);
        show('.netsvc-needs-iface', dhcp.checked);
        show('.netsvc-tftp', tftp.checked);
    };
    [dhcp, tftp].forEach(c => c.addEventListener('change', update));
    update();
})();
