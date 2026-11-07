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
    UIHelper.copyToClipboard(document.getElementById('redirectUriInput').value, window.GOOGLE_INTEGRATION_COPIED);
}
