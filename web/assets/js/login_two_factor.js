/* Page script of templates/views/login/two_factor.php */

let isRecovery = false;
function toggleAuthMode() {
    isRecovery = !isRecovery;
    const secTotp = document.getElementById('section_totp');
    const secRec = document.getElementById('section_recovery');
    const modeInput = document.getElementById('auth_mode');
    const btn = document.getElementById('toggleModeBtn');

    if (isRecovery) {
        secTotp.style.display = 'none';
        secRec.style.display = 'block';
        modeInput.value = 'recovery';
        btn.innerHTML = '<i class="fas fa-mobile-alt"></i> ' + window.LOGIN_2FA_TEXT.use_totp;
        document.getElementById('recovery_code').focus();
    } else {
        secTotp.style.display = 'block';
        secRec.style.display = 'none';
        modeInput.value = 'totp';
        btn.innerHTML = '<i class="fas fa-key"></i> ' + window.LOGIN_2FA_TEXT.use_recovery;
        document.getElementById('totp_code').focus();
    }
}

// Auto-submit when 6 digits entered
document.getElementById('totp_code').addEventListener('input', function(e) {
    this.value = this.value.replace(/[^0-9]/g, '');
    if (this.value.length === 6) {
        document.getElementById('twoFactorForm').submit();
    }
});
