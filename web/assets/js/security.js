/* Page script of templates/views/security/index.php */

const CSRF_TOKEN = window.SECURITY_PAGE.csrf;
const RECOVERY_CODES = window.SECURITY_PAGE.recoveryCodes;

function copySecretKey() {
    const text = document.getElementById('manual_secret_key').innerText.trim();
    navigator.clipboard.writeText(text).then(() => {
        if (window.notify) window.notify.success(window.SECURITY_PAGE.i18n.secret_copied);
    });
}

function copyRecoveryCodes() {
    if (!RECOVERY_CODES || !RECOVERY_CODES.length) return;
    const text = "AiPBX Yedek Kurtarma Kodları:\n" + RECOVERY_CODES.join("\n");
    navigator.clipboard.writeText(text).then(() => {
        if (window.notify) window.notify.success(window.SECURITY_PAGE.i18n.codes_copied);
    });
}

function downloadRecoveryCodes() {
    if (!RECOVERY_CODES || !RECOVERY_CODES.length) return;
    const content = "AiPBX Yedek Kurtarma Kodları (" + new Date().toLocaleString() + ")\n"
                  + "====================================================\n"
                  + "Her kod yalnızca BİR KEZ kullanılabilir:\n\n"
                  + RECOVERY_CODES.map((c, i) => (i + 1) + ". " + c).join("\n") + "\n";
    const blob = new Blob([content], { type: "text/plain;charset=utf-8" });
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = "aipbx-recovery-codes.txt";
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

function openDisable2faModal() {
    document.getElementById('modalDisable2fa').style.display = 'flex';
}
function closeDisable2faModal() {
    document.getElementById('modalDisable2fa').style.display = 'none';
}

function openRegenCodesModal() {
    document.getElementById('modalRegenCodes').style.display = 'flex';
}
function closeRegenCodesModal() {
    document.getElementById('modalRegenCodes').style.display = 'none';
}

// --- WEBAUTHN PASSKEY KAYDI ---
function base64urlToUint8Array(base64url) {
    if (!base64url) return new Uint8Array(0);
    if (base64url instanceof Uint8Array) return base64url;
    if (base64url instanceof ArrayBuffer) return new Uint8Array(base64url);
    let str = String(base64url).trim();
    if (str.startsWith('=?BINARY?B?') && str.endsWith('?=')) {
        str = str.substring(11, str.length - 2);
    }
    let base64 = str.replace(/-/g, '+').replace(/_/g, '/');
    while (base64.length % 4) {
        base64 += '=';
    }
    const raw = window.atob(base64);
    const bytes = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
    }
    return bytes;
}

function arrayBufferToBase64(buffer) {
    if (!buffer) return '';
    if (typeof buffer === 'string') return buffer;
    let binary = '';
    const bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
    for (let i = 0; i < bytes.byteLength; i++) {
        binary += String.fromCharCode(bytes[i]);
    }
    return window.btoa(binary);
}

async function registerNewPasskey() {
    if (!window.PublicKeyCredential) {
        alert(window.SECURITY_PAGE.i18n.webauthn_not_supported);
        return;
    }

    const deviceName = prompt(
        window.SECURITY_PAGE.i18n.prompt_device_name,
        "Passkey (" + (navigator.platform || 'Cihaz') + ")"
    );
    if (deviceName === null) return; // cancelled

    try {
        // 1. Get the create options from the server
        const optRes = await fetch('/api/passkey.php?action=register-options');
        const optData = await optRes.json();
        if (!optData.success) {
            alert("Hata: " + (optData.error || "Seçenekler alınamadı"));
            return;
        }

        const makeArgs = optData.options;
        makeArgs.challenge = base64urlToUint8Array(makeArgs.challenge);
        makeArgs.user.id = base64urlToUint8Array(makeArgs.user.id);

        if (makeArgs.excludeCredentials && Array.isArray(makeArgs.excludeCredentials)) {
            makeArgs.excludeCredentials.forEach(c => {
                c.id = base64urlToUint8Array(c.id);
            });
        }

        // With an IP address, rp.id does not count as a domain under the W3C
        // standard; rp.id is removed so the browser does not fail and the origin is used by default.
        if (makeArgs.rp && makeArgs.rp.id && /^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$/.test(makeArgs.rp.id)) {
            delete makeArgs.rp.id;
        }

        // 2. Create the biometric / security key in the browser
        const credential = await navigator.credentials.create({ publicKey: makeArgs });
        if (!credential) {
            alert("Passkey oluşturulamadı.");
            return;
        }

        // 3. Send it to the server for verification
        const payload = {
            action: 'register-verify',
            deviceName: deviceName.trim() || 'Passkey',
            id: credential.id || (credential.rawId ? arrayBufferToBase64(credential.rawId) : ''),
            clientDataJSON: arrayBufferToBase64(credential.response.clientDataJSON),
            attestationObject: arrayBufferToBase64(credential.response.attestationObject),
        };

        const verifyRes = await fetch('/api/passkey.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const verifyData = await verifyRes.json();

        if (verifyData.success) {
            if (window.notify) window.notify.success(window.SECURITY_PAGE.i18n.passkey_added_success);
            setTimeout(() => window.location.reload(), 800);
        } else {
            alert("Hata: " + (verifyData.error || "Passkey doğrulanamadı."));
        }
    } catch (err) {
        console.error(err);
        alert("Passkey işlemi iptal edildi veya bir hata oluştu: " + err.message);
    }
}

async function deletePasskey(passkeyId, deviceName) {
    if (!confirm("'" + deviceName + "' " + window.SECURITY_PAGE.i18n.confirm_delete_passkey)) {
        return;
    }

    try {
        const res = await fetch('/api/passkey.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'delete',
                passkey_id: passkeyId,
                csrf_token: CSRF_TOKEN
            })
        });
        const data = await res.json();
        if (data.success) {
            if (window.notify) window.notify.success(window.SECURITY_PAGE.i18n.passkey_deleted_success);
            setTimeout(() => window.location.reload(), 800);
        } else {
            alert("Hata: " + (data.error || "Passkey silinemedi."));
        }
    } catch (err) {
        console.error(err);
        alert("Bir hata oluştu: " + err.message);
    }
}
