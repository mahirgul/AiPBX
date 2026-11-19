/* Page script of templates/views/my_phone/index.php */

/**
 * My Phone page JS functions
 */
/** Opens a tab from a button elsewhere on the page (uiTabs, shared switching). */
function switchMyPhoneTab(tabName) {
    const btn = document.querySelector('.ui-tab[data-tab="' + tabName + '"]') || document.querySelector('.ui-tab[data-tab="history"]');
    if (btn) UIHelper.selectTab(btn);
}

/** A tab was opened: keep it in the address and for the next visit; Settings lists the audio devices. */
function onMyPhoneTab(e) {
    if (!document.getElementById("tab-pane-history")) return;
    const tabName = e.detail;
    const urlParams = new URLSearchParams(window.location.search);
    if (tabName === "history") urlParams.delete("tab"); else urlParams.set("tab", tabName);
    const queryStr = urlParams.toString();
    history.replaceState(null, "", "/my-phone" + (queryStr ? "?" + queryStr : ""));
    try { localStorage.setItem("my_phone_active_tab", tabName); } catch (err) {}
    if (tabName === "settings" && typeof loadMyPhoneAudioDevices === "function") {
        loadMyPhoneAudioDevices();
    }
}
// Once: the page script runs again on every visit through the page router.
if (!window.myPhoneTabListener) {
    window.myPhoneTabListener = true;
    document.addEventListener("tabchange", onMyPhoneTab);
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
    UIHelper.copyToClipboard(passInput.value, __('js.my_phone.sip_copied'));
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

    // Keep the WebRTC status in sync with the softphone
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
