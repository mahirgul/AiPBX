<!DOCTYPE html>
<html lang="tr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('login_2fa.page_title', 'İki Faktörlü Doğrulama'); ?> - <?php echo htmlspecialchars($site_title); ?></title>
    <?php if (!empty($site_favicon_url)): ?>
        <link rel="shortcut icon" href="<?php echo htmlspecialchars($site_favicon_url); ?>">
    <?php endif; ?>

    <link rel="stylesheet" href="/assets/css/variables.css?v=<?php echo time(); ?>">
    <?php renderBrandColorOverrideCSS(); ?>
    <link rel="stylesheet" href="/assets/css/layout.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/assets/css/components.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/assets/css/fontawesome.min.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo time(); ?>">
</head>
<body class="auth-body">
    <div class="auth-card" style="max-width: 420px;">
        <div style="text-align: center; margin-bottom: 24px;">
            <div class="brand-icon" style="width: 56px; height: 56px; margin: 0 auto 16px auto; font-size: 24px; background: rgba(2, 132, 199, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-shield-alt" style="color: var(--primary); font-size: 26px;"></i>
            </div>
            <h2 style="font-size: 20px; font-weight: 800;"><?php echo t('login_2fa.title', 'İki Faktörlü Doğrulama'); ?></h2>
            <p style="color: var(--text-muted); font-size: 13px; margin-top: 6px;">
                <?php echo t('login_2fa.account_label', 'Hesap:'); ?> <strong><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></strong>
            </p>
        </div>

        <?php if ($error): ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 20px; text-align: center;">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/login-2fa" id="twoFactorForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="auth_mode" id="auth_mode" value="totp">

            <!-- TOTP 6-Digit Section -->
            <div id="section_totp">
                <div class="form-group" style="text-align: center;">
                    <label class="form-label" style="font-size: 13px; color: var(--text-muted); margin-bottom: 12px;">
                        <?php echo t('login_2fa.code_prompt', 'Authenticator uygulamanızdaki 6 haneli kodu girin:'); ?>
                    </label>
                    <input type="text" name="totp_code" id="totp_code" class="form-control"
                           maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code"
                           placeholder="000000"
                           style="font-size: 26px; font-weight: 800; letter-spacing: 10px; text-align: center; height: 54px; border-radius: 12px;"
                           autofocus>
                </div>
            </div>

            <!-- Recovery Code Section (Toggled) -->
            <div id="section_recovery" style="display: none;">
                <div class="form-group">
                    <label class="form-label" style="font-size: 13px; color: var(--text-muted); margin-bottom: 8px;">
                        <?php echo t('login_2fa.recovery_prompt', '8 karakterli yedek kurtarma kodunuzu girin:'); ?>
                    </label>
                    <input type="text" name="recovery_code" id="recovery_code" class="form-control"
                           placeholder="Örn: 8F3K-9M2Q"
                           style="font-size: 18px; font-weight: 700; letter-spacing: 2px; text-align: center; text-transform: uppercase; height: 50px; border-radius: 12px;">
                    <small style="display: block; color: var(--text-muted); margin-top: 6px; font-size: 11px;">
                        <?php echo t('login_2fa.recovery_help', 'Her kurtarma kodu tek kullanımlıktır ve kullanıldıktan sonra geçersiz kalır.'); ?>
                    </small>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px; margin-top: 14px; font-size: 15px; font-weight: 700;">
                <i class="fas fa-check-circle" style="margin-right: 6px;"></i> <?php echo t('login_2fa.btn_verify', 'Doğrula ve Giriş Yap'); ?>
            </button>
        </form>

        <div style="margin-top: 20px; text-align: center; display: flex; flex-direction: column; gap: 10px; font-size: 13px;">
            <button type="button" id="toggleModeBtn" onclick="toggleAuthMode()" style="background: none; border: none; color: var(--primary); cursor: pointer; text-decoration: underline; font-size: 12.5px;">
                <i class="fas fa-key"></i> <?php echo t('login_2fa.use_recovery_code', 'Cihazınıza erişemiyor musunuz? Kurtarma kodu kullanın'); ?>
            </button>

            <form method="POST" action="/login-2fa" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="cancel">
                <button type="submit" style="background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 12px;">
                    <i class="fas fa-arrow-left"></i> <?php echo t('login_2fa.btn_cancel', 'Farklı bir hesapla giriş yap'); ?>
                </button>
            </form>
        </div>
    </div>

    <script>
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
                btn.innerHTML = '<i class="fas fa-mobile-alt"></i> <?php echo addslashes(t('login_2fa.use_totp_code', 'Authenticator koduna geri dön')); ?>';
                document.getElementById('recovery_code').focus();
            } else {
                secTotp.style.display = 'block';
                secRec.style.display = 'none';
                modeInput.value = 'totp';
                btn.innerHTML = '<i class="fas fa-key"></i> <?php echo addslashes(t('login_2fa.use_recovery_code', 'Cihazınıza erişemiyor musunuz? Kurtarma kodu kullanın')); ?>';
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
    </script>
</body>
</html>
