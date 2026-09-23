<!DOCTYPE html>
<html lang="tr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('login.page_title'); ?> - <?php echo htmlspecialchars($site_title); ?></title>
    <?php if (!empty($site_favicon_url)): ?>
        <link rel="shortcut icon" href="<?php echo htmlspecialchars($site_favicon_url); ?>">
    <?php endif; ?>

    <!-- 100% Offline Local Assets -->
    <link rel="stylesheet" href="/assets/css/variables.css?v=<?php echo time(); ?>">
    <?php renderBrandColorOverrideCSS(); ?>
    <link rel="stylesheet" href="/assets/css/layout.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/assets/css/components.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/assets/css/fontawesome.min.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        @media (max-width: 768px) {
            .mobile-app-download {
                display: block !important;
            }
        }
    </style>
</head>
<body class="auth-body">
    <div class="auth-card" style="max-width: 420px;">
        <div style="text-align: center; margin-bottom: 24px;">
            <div class="brand-icon" style="width: 56px; height: 56px; margin: 0 auto 16px auto; font-size: 24px;">
                <?php if ($site_logo_type === 'image' && !empty($site_logo_image)): ?>
                    <img src="<?php echo htmlspecialchars($site_logo_image); ?>" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                <?php else: ?>
                    <i class="fas <?php echo htmlspecialchars($site_logo_icon); ?>" style="color: var(--primary);"></i>
                <?php endif; ?>
            </div>
            <h2 style="font-size: 22px; font-weight: 800;"><?php echo htmlspecialchars($brand_title); ?></h2>
            <p style="color: var(--text-muted); font-size: 13px; margin-top: 6px;"><?php echo htmlspecialchars($brand_sub); ?></p>
        </div>

        <?php if ($error): ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 20px; text-align: center;">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div class="form-group">
                <label class="form-label"><?php echo t('login.field_username'); ?></label>
                <div style="position: relative;">
                    <i class="fas fa-user" style="position: absolute; left: 16px; top: 15px; color: var(--text-muted);"></i>
                    <input type="text" name="username" class="form-control" style="padding-left: 44px;" required autofocus autocomplete="off">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('login.field_password'); ?></label>
                <div style="position: relative;">
                    <i class="fas fa-lock" style="position: absolute; left: 16px; top: 15px; color: var(--text-muted);"></i>
                    <input type="password" name="password" class="form-control" style="padding-left: 44px;" required>
                </div>
            </div>

            <!-- Dynamic Math Security Challenge -->
            <div class="form-group" style="background: var(--bg-card); border: 1px solid var(--border-color); padding: 14px; border-radius: 12px;">
                <label class="form-label" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span><?php echo t('login.security_check'); ?></span>
                    <strong style="color: var(--primary); font-size: 16px;"><?php echo $num1; ?> + <?php echo $num2; ?> = ?</strong>
                </label>
                <input type="number" name="captcha_answer" class="form-control" placeholder="<?php echo t('login.captcha_placeholder'); ?>" required autocomplete="off">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px; margin-top: 10px; font-size: 15px;" title="<?php echo t('login.submit_tooltip'); ?>">
                <i class="fas fa-sign-in-alt"></i>
            </button>
        </form>

        <div style="display: flex; align-items: center; margin: 16px 0 12px 0; gap: 10px;">
            <div style="flex: 1; height: 1px; background: var(--border-color);"></div>
            <span style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: 700;"><?php echo t('login.or_divider', 'VEYA'); ?></span>
            <div style="flex: 1; height: 1px; background: var(--border-color);"></div>
        </div>

        <button type="button" class="btn btn-outline-primary" id="btnPasskeyLogin" onclick="loginWithPasskey()" style="width: 100%; justify-content: center; padding: 11px 14px; font-size: 13.5px; font-weight: 700; gap: 8px; border-radius: 10px;">
            <i class="fas fa-fingerprint" style="font-size: 16px;"></i> <?php echo t('login.btn_passkey', 'Passkey ile Giriş Yap'); ?>
        </button>

        <?php
        $login_ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $is_mobile_device = (bool) preg_match('/(android|iphone|ipad|ipod|mobile|phone|silk|blackberry|opera mini|windows phone)/i', $login_ua);
        ?>
        <div class="mobile-app-download" style="<?php echo $is_mobile_device ? 'margin-top: 22px; padding-top: 18px; border-top: 1px dashed var(--border-color); text-align: center;' : 'display: none; margin-top: 22px; padding-top: 18px; border-top: 1px dashed var(--border-color); text-align: center;'; ?>">
            <div style="font-size: 13px; font-weight: 700; margin-bottom: 4px; display: flex; align-items: center; justify-content: center; gap: 8px; color: var(--text-color);">
                <i class="fab fa-android" style="color: #3DDC84; font-size: 18px;"></i>
                <span><?php echo t('login.mobile_app_title'); ?></span>
            </div>
            <p style="font-size: 11px; color: var(--text-muted); margin-bottom: 12px; line-height: 1.4;">
                <?php echo t('login.mobile_app_desc'); ?>
            </p>
            <a href="/app.apk" download="AiPBX.apk" class="btn btn-outline-primary" style="width: 100%; justify-content: center; gap: 8px; font-weight: 600; padding: 10px 14px; font-size: 13px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center;">
                <i class="fas fa-download"></i> <?php echo t('login.download_apk'); ?>
            </a>
        </div>
    </div>

    <div class="footer-toast-container" id="footer-toast-container"></div>
    <script src="/assets/js/theme.js"></script>
    <script src="/assets/js/footer_notify.js"></script>

    <script>
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
                    window.notify.warning("Tarayıcınız Passkey (WebAuthn) standardını desteklemiyor.");
                } else {
                    alert("Tarayıcınız Passkey (WebAuthn) standardını desteklemiyor.");
                }
                return;
            }

            const btn = document.getElementById('btnPasskeyLogin');
            const origHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Doğrulanıyor...';

            try {
                const optRes = await fetch('/api/passkey.php?action=auth-options');
                const optData = await optRes.json();
                if (!optData.success) {
                    throw new Error(optData.error || 'Passkey seçenekleri alınamadı.');
                }

                const getArgs = optData.options;
                getArgs.challenge = base64urlToUint8Array(getArgs.challenge);

                if (getArgs.allowCredentials && Array.isArray(getArgs.allowCredentials) && getArgs.allowCredentials.length > 0) {
                    getArgs.allowCredentials.forEach(c => {
                        c.id = base64urlToUint8Array(c.id);
                    });
                }

                // IP adresi durumunda rpId W3C standardı gereği alan adı sayılmaz;
                // tarayıcının hata vermemesi için rpId silinerek origin varsayılan alınır.
                if (getArgs.rpId && /^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$/.test(getArgs.rpId)) {
                    delete getArgs.rpId;
                }

                const assertion = await navigator.credentials.get({ publicKey: getArgs });
                if (!assertion) {
                    throw new Error('Passkey doğrulaması iptal edildi.');
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
                        if (window.notify) window.notify.success("Passkey doğrulandı! Giriş yapılıyor...");
                    } catch (e) {
                        console.warn(e);
                    }
                    window.location.href = verifyData.redirect || '/dashboard';
                } else {
                    throw new Error(verifyData.error || 'Passkey doğrulanamadı.');
                }
            } catch (err) {
                console.error(err);
                try {
                    if (window.notify) {
                        window.notify.error(err.message || 'Passkey doğrulaması başarısız.');
                    } else {
                        alert(err.message || 'Passkey doğrulaması başarısız.');
                    }
                } catch (e) {
                    alert(err.message || 'Passkey doğrulaması başarısız.');
                }
            } finally {
                btn.disabled = false;
                btn.innerHTML = origHtml;
            }
        }
    </script>

    <?php if ($error): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (window.notify) {
                window.notify.error(<?php echo json_encode($error); ?>);
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
