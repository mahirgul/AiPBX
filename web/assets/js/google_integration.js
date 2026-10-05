/* Page script of templates/views/google_integration/index.php */

function toggleSecretVisibility() {
    var inp = document.getElementById('googleClientSecretInput');
    var icon = document.getElementById('secretEyeIcon');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        inp.type = 'password';
        icon.className = 'fas fa-eye';
    }
}

function copyRedirectUri() {
    var copyText = document.getElementById('redirectUriInput');
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value).then(function() {
        var btnText = document.getElementById('copyBtnText');
        var orig = btnText.innerText;
        btnText.innerText = window.GOOGLE_INTEGRATION_COPIED;
        setTimeout(function() {
            btnText.innerText = orig;
        }, 2000);
    });
}
