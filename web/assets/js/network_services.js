/* Page script of templates/views/network_services/index.php */

// Shows the fields of the switched-on services: the interface and DHCP
// settings with DHCP, the networks note with TFTP, FTP or syslog, the FTP login with FTP.
(function () {
    const form = document.getElementById('netsvcForm');
    if (!form) return;
    const show = (selector, on) => form.querySelectorAll(selector).forEach(el => { el.style.display = on ? '' : 'none'; });
    const dhcp = document.getElementById('netsvc_dhcp_on');
    const tftp = document.getElementById('netsvc_tftp_on');
    const ftp = document.getElementById('netsvc_ftp_on');
    const syslog = document.getElementById('netsvc_syslog_on');
    const update = () => {
        show('.netsvc-dhcp', dhcp.checked);
        show('.netsvc-needs-iface', dhcp.checked);
        show('.netsvc-tftp', tftp.checked || ftp.checked || syslog.checked);
        show('.netsvc-ftp', ftp.checked);
    };
    [dhcp, tftp, ftp, syslog].forEach(c => c.addEventListener('change', update));
    update();
})();
