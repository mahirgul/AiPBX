/* Mobile sign-in QR code dialog (templates/qr_login_modal.php), shared by My Phone and the header phone. */
var _qrToken = null;
var _qrTimer = null;
var _qrPollInterval = null;
var _qrSecondsLeft = 600;

function openQrLoginModal() {
    const modal = document.getElementById("qrLoginModal");
    if (!modal) return;
    modal.style.display = "flex";
    modal.classList.add("active");
    generateNewQrCode();
}

function closeQrLoginModal() {
    const modal = document.getElementById("qrLoginModal");
    if (modal) {
        modal.style.display = "none";
        modal.classList.remove("active");
    }
    if (_qrTimer) clearInterval(_qrTimer);
    if (_qrPollInterval) clearInterval(_qrPollInterval);
}

function generateNewQrCode() {
    const spinner = document.getElementById("qrLoadingSpinner");
    const svgWrapper = document.getElementById("qrSvgWrapper");
    const successAlert = document.getElementById("qrSuccessAlert");

    if (spinner) {
        spinner.innerHTML = '<i class="fas fa-spinner fa-spin" style="font-size: 28px; color: var(--primary); margin-bottom: 8px; display: block;"></i> ' + __('js.my_phone.qr_generating') + '';
        spinner.style.display = "block";
    }
    if (svgWrapper) {
        svgWrapper.style.display = "none";
        svgWrapper.style.opacity = "1";
        svgWrapper.innerHTML = "";
    }
    if (successAlert) successAlert.style.display = "none";
    if (_qrTimer) clearInterval(_qrTimer);
    if (_qrPollInterval) clearInterval(_qrPollInterval);

    const csrf = window.CSRF_TOKEN;
    fetch("/api/qr_code.php?action=generate", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "X-CSRF-Token": csrf
        },
        body: "csrf_token=" + encodeURIComponent(csrf)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.qr_data_uri) {
            _qrToken = data.qr_token;
            if (spinner) spinner.style.display = "none";
            if (svgWrapper) {
                svgWrapper.innerHTML = '<img src="' + escapeHtml(data.qr_data_uri) + '" alt="' + __('js.my_phone.qr_alt') + '" style="width: 100%; max-width: 220px; height: auto; display: block; margin: 0 auto; user-select: none;">';
                svgWrapper.style.display = "block";
            }
            _qrSecondsLeft = data.expires_in || 600;
            startQrCountdown();
            startQrPolling();
        } else {
            if (spinner) spinner.innerHTML = '<span class="u-danger">' + escapeHtml(data.error || __('js.my_phone.qr_failed')) + '</span>';
        }
    })
    .catch(err => {
        if (spinner) spinner.innerHTML = '<span class="u-danger">' + __('js.common.connection_error_colon') + '' + escapeHtml(err.message) + '</span>';
    });
}

function startQrCountdown() {
    const el = document.getElementById("qrCountdown");
    if (_qrTimer) clearInterval(_qrTimer);

    function update() {
        if (_qrSecondsLeft <= 0) {
            clearInterval(_qrTimer);
            if (el) el.textContent = __('js.my_phone.expired');
            const svgWrapper = document.getElementById("qrSvgWrapper");
            if (svgWrapper) svgWrapper.style.opacity = "0.25";
            return;
        }
        const m = Math.floor(_qrSecondsLeft / 60);
        const s = _qrSecondsLeft % 60;
        if (el) el.textContent = String(m).padStart(2, "0") + ":" + String(s).padStart(2, "0");
        _qrSecondsLeft--;
    }
    update();
    _qrTimer = setInterval(update, 1000);
}

function startQrPolling() {
    if (_qrPollInterval) clearInterval(_qrPollInterval);
    _qrPollInterval = setInterval(() => {
        if (!_qrToken) return;
        fetch("/api/qr_code.php?action=status&token=" + encodeURIComponent(_qrToken))
        .then(r => r.json())
        .then(res => {
            if (res.success && res.data && res.data.used) {
                clearInterval(_qrPollInterval);
                clearInterval(_qrTimer);
                const alertEl = document.getElementById("qrSuccessAlert");
                const devEl = document.getElementById("qrPairedDevice");
                if (devEl) devEl.textContent = res.data.device_name || __('js.my_phone.mobile_device');
                if (alertEl) alertEl.style.display = "block";
                const svgWrapper = document.getElementById("qrSvgWrapper");
                if (svgWrapper) svgWrapper.style.opacity = "0.35";
                if (window.showFooterToast) {
                    showFooterToast(__('js.my_phone.paired'), "success");
                }
            }
        })
        .catch(() => {});
    }, 2500);
}
