(function () {
    var type = document.getElementById('tunnel_type');
    var login = document.getElementById('tunnelLogin');
    var show = function () { login.style.display = type.value === 'wg' ? 'none' : ''; };
    type.addEventListener('change', show);
    show();
})();
