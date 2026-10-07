/* Page script of templates/views/my_phone/index.php */

/**
 * My Phone page JS functions
 */
function switchMyPhoneTab(tabName) {
    const panes = { history: "block", calls: "grid", settings: "grid", voicemail: "block" };
    if (!panes[tabName]) tabName = "history";
    Object.keys(panes).forEach(function (name) {
        const pane = document.getElementById("tab-pane-" + name);
        const btn = document.getElementById("btn-tab-" + name);
        if (pane) pane.style.display = (name === tabName) ? panes[name] : "none";
        if (btn) {
            btn.classList.toggle("btn-primary", name === tabName);
            btn.classList.toggle("btn-secondary", name !== tabName);
        }
    });

    const urlParams = new URLSearchParams(window.location.search);
    if (tabName === "history") urlParams.delete("tab"); else urlParams.set("tab", tabName);
    const queryStr = urlParams.toString();
    history.replaceState(null, "", "/my-phone" + (queryStr ? "?" + queryStr : ""));
    try { localStorage.setItem("my_phone_active_tab", tabName); } catch (e) {}

    if (tabName === "settings" && typeof loadMyPhoneAudioDevices === "function") {
        loadMyPhoneAudioDevices();
    }
}

function deleteVoicemailMessage(msgId) {
    if (!confirm(__('js.my_phone.vm_delete_confirm'))) return;
    const form = new FormData();
    form.append('csrf_token', window.CSRF_TOKEN);
    form.append('action', 'delete');
    form.append('ext', window.CURRENT_USER_EXT);
    form.append('msg_id', msgId);

    fetch('/api/voicemail.php', { method: 'POST', body: form })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.error || __('js.common.delete_failed'));
            }
        })
        .catch(() => alert(__('js.common.connection_error')));
}

function setMyPhoneFilter(filterVal) {
    const input = document.getElementById("myPhoneFilterInput");
    const form = document.getElementById("myPhoneFilterForm");
    if (input && form) {
        input.value = filterVal;
        form.submit();
    }
}

function callTargetNumber(number) {
    if (!number) return;
    const input = document.getElementById("header-quick-dial-input");
    if (input) {
        input.value = number;
    }
    if (typeof headerPhoneMakeCall === "function") {
        headerPhoneMakeCall();
    } else {
        alert(__('js.my_phone.webrtc_failed'));
    }
}

function toggleSipPassVisibility() {
    const passInput = document.getElementById("my_phone_sip_pass_val");
    const eye = document.getElementById("my_phone_pass_eye");
    if (!passInput) return;
    if (passInput.type === "password") {
        passInput.type = "text";
        if (eye) eye.className = "fas fa-eye-slash";
    } else {
        passInput.type = "password";
        if (eye) eye.className = "fas fa-eye";
    }
}

function copySipPassword() {
    const passInput = document.getElementById("my_phone_sip_pass_val");
    if (!passInput || !passInput.value) return;
    navigator.clipboard.writeText(passInput.value).then(function() {
        if (window.showFooterToast) {
            showFooterToast(__('js.my_phone.sip_copied'), "success");
        }
    });
}

function updateVolSlider(val) {
    const lbl = document.getElementById("my-phone-vol-label");
    if (lbl) lbl.textContent = val + "%";
    if (typeof savePhoneRingVolume === "function") {
        savePhoneRingVolume(val);
    }
}

function loadMyPhoneAudioDevices() {
    if (typeof populatePhoneDeviceSelects === "function") {
        populatePhoneDeviceSelects();
    }
}

/**
 * QR Code Quick Mobile Login Functions
 */
let _qrToken = null;
let _qrTimer = null;
let _qrPollInterval = null;
let _qrSecondsLeft = 600;

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
                svgWrapper.innerHTML = '<img src="' + data.qr_data_uri + '" alt="' + __('js.my_phone.qr_alt') + '" style="width: 100%; max-width: 220px; height: auto; display: block; margin: 0 auto; user-select: none;">';
                svgWrapper.style.display = "block";
            }
            _qrSecondsLeft = data.expires_in || 600;
            startQrCountdown();
            startQrPolling();
        } else {
            if (spinner) spinner.innerHTML = '<span class="u-danger">' + (data.error || __('js.my_phone.qr_failed')) + '</span>';
        }
    })
    .catch(err => {
        if (spinner) spinner.innerHTML = '<span class="u-danger">' + __('js.common.connection_error_colon') + '' + err.message + '</span>';
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

function initMyPhone() {
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get("tab");
    let savedTab = null;
    try { savedTab = localStorage.getItem("my_phone_active_tab"); } catch (e) {}
    const startTab = tabParam || savedTab;
    if (startTab === "settings" || startTab === "calls") {
        switchMyPhoneTab(startTab);
    }
    if (startTab !== "settings") {
        loadMyPhoneAudioDevices();
    }

    // WebRTC durumunu softphone ile senkronize et
    function syncMyPhoneWebrtc() {
        const txt = document.getElementById("my-phone-webrtc-text");
        if (!txt) return;
        if (typeof headerSipRegistered !== "undefined" && headerSipRegistered) {
            txt.textContent = __('js.my_phone.online');
            txt.style.color = "var(--success)";
        }
    }
    syncMyPhoneWebrtc();
    if (!window._myPhoneSyncInterval) {
        window._myPhoneSyncInterval = setInterval(syncMyPhoneWebrtc, 1000);
    }
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initMyPhone);
} else {
    initMyPhone();
}
if (navigator.mediaDevices && navigator.mediaDevices.addEventListener) {
    navigator.mediaDevices.addEventListener("devicechange", loadMyPhoneAudioDevices);
}
