/* Page script of templates/views/login/index.php */

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

async function loginWithPasskey() {
    if (!window.PublicKeyCredential) {
        if (window.notify) {
            window.notify.warning(__('js.login.passkey_unsupported'));
        } else {
            alert(__('js.login.passkey_unsupported'));
        }
        return;
    }

    const btn = document.getElementById('btnPasskeyLogin');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + __('js.login.verifying') + '';

    try {
        const optRes = await fetch('/api/passkey.php?action=auth-options');
        const optData = await optRes.json();
        if (!optData.success) {
            throw new Error(optData.error || __('js.login.passkey_options_failed'));
        }

        const getArgs = optData.options;
        getArgs.challenge = base64urlToUint8Array(getArgs.challenge);

        if (getArgs.allowCredentials && Array.isArray(getArgs.allowCredentials) && getArgs.allowCredentials.length > 0) {
            getArgs.allowCredentials.forEach(c => {
                c.id = base64urlToUint8Array(c.id);
            });
        }

        // With an IP address, rpId does not count as a domain under the W3C
        // standard; rpId is removed so the browser does not fail and the origin is used by default.
        if (getArgs.rpId && /^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$/.test(getArgs.rpId)) {
            delete getArgs.rpId;
        }

        const assertion = await navigator.credentials.get({ publicKey: getArgs });
        if (!assertion) {
            throw new Error(__('js.login.passkey_cancelled'));
        }

        const payload = {
            action: 'auth-verify',
            id: (assertion.rawId ? arrayBufferToBase64(assertion.rawId) : '') || assertion.id,
            rawId: assertion.id,
            clientDataJSON: arrayBufferToBase64(assertion.response.clientDataJSON),
            authenticatorData: arrayBufferToBase64(assertion.response.authenticatorData),
            signature: arrayBufferToBase64(assertion.response.signature),
        };

        const verifyRes = await fetch('/api/passkey.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const verifyData = await verifyRes.json();

        if (verifyData.success) {
            try {
                if (window.notify) window.notify.success(__('js.login.passkey_ok'));
            } catch (e) {
                console.warn(e);
            }
            window.location.href = verifyData.redirect || '/dashboard';
        } else {
            throw new Error(verifyData.error || __('js.login.passkey_not_verified'));
        }
    } catch (err) {
        console.error(err);
        try {
            if (window.notify) {
                window.notify.error(err.message || __('js.login.passkey_failed'));
            } else {
                alert(err.message || __('js.login.passkey_failed'));
            }
        } catch (e) {
            alert(err.message || __('js.login.passkey_failed'));
        }
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}
