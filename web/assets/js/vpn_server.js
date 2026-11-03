(function () {
    var type = document.getElementById('vpn_type');
    var port = document.getElementById('vpn_port');
    var defaults = { wg: '51820', ovpn: '1194' };
    type.addEventListener('change', function () {
        document.getElementById('vpnServerFields').style.display = type.value === 'off' ? 'none' : '';
        document.getElementById('vpnProtoGroup').style.display = type.value === 'ovpn' ? '' : 'none';
        if (defaults[type.value] && (port.value === defaults.wg || port.value === defaults.ovpn)) {
            port.value = defaults[type.value];
        }
    });
})();
